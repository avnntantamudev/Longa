<?php

declare(strict_types=1);

namespace App\Modules\Requests\Actions;

use App\Modules\Matching\Services\MatchingCoordinator;
use App\Modules\Requests\Enums\RequestState;
use App\Modules\Requests\Models\ServiceRequest;
use App\Modules\Requests\Models\ServiceRequestMedia;
use App\Modules\Requests\Services\Commission;
use App\Modules\Requests\Services\HourlyPricing;
use App\Modules\Users\Enums\PricingMode;
use App\Modules\Users\Models\Category;
use App\Modules\Users\Models\CategoryPackage;
use App\Modules\Users\Models\User;
use App\Support\Api\ApiException;
use App\Support\Audit\AuditLogger;
use App\Support\Settings\PlatformSettings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * O cliente publica um pedido: valida as regras de negócio, fixa o valor conforme o modo de preço da categoria
 * (fixo: tabela da categoria, bloqueado no envio; orçamento: 0 até haver orçamento aceite; hora/pacote: o valor
 * indicado), guarda as respostas e os ficheiros do formulário, passa a "searching" e envia a 1.ª onda.
 */
class CreateServiceRequest
{
    public function __construct(private readonly MatchingCoordinator $matching, private readonly AuditLogger $audit) {}

    /**
     * @param  array{category_id: int, title?: string|null, description?: string|null, max_hourly_rate?: int|null, category_package_id?: int|null, answers?: array<string, mixed>, scheduled_at: string, duration_minutes?: int, payment_method?: string, modality: string, province: string, municipality: string, neighborhood: string, latitude: float, longitude: float}  $data
     * @param  array<string, list<UploadedFile>>  $media  fotos e vídeos por campo do formulário
     */
    public function handle(User $client, array $data, ?ServiceRequest $republishedFrom = null, array $media = []): ServiceRequest
    {
        $scheduledAt = Carbon::parse($data['scheduled_at']);
        $category = Category::query()->findOrFail((int) $data['category_id']);
        $package = isset($data['category_package_id']) ? CategoryPackage::query()->find($data['category_package_id']) : null;
        $sessions = $package->sessions ?? 1;
        $discount = $package->discount_percent ?? 0;
        $amount = $this->amountFor($category, $data['max_hourly_rate'] ?? null, (int) ($data['duration_minutes'] ?? 60), $sessions, $discount);
        $stored = $this->storeMedia($media);

        $request = DB::transaction(function () use ($client, $data, $scheduledAt, $republishedFrom, $category, $amount, $stored, $sessions, $discount) {
            // Serializa os pedidos do mesmo cliente para os limites não serem contornados em paralelo
            User::query()->whereKey($client->id)->lockForUpdate()->first();

            $this->ensureWithinActiveLimit($client);
            $this->ensureNotDuplicate($client, $category->id, $scheduledAt);

            $split = Commission::split($amount);

            $request = new ServiceRequest([
                ...$data,
                'title' => $data['title'] ?? null ?: $category->name,
                'amount' => $amount,
                'answers' => ($data['answers'] ?? []) === [] ? null : $data['answers'],
                'scheduled_at' => $scheduledAt,
                'commission' => $split['commission'],
                'provider_net' => $split['provider_net'],
                'republished_from_id' => $republishedFrom?->id,
            ]);
            $request->client_id = $client->id;
            $request->scheduled = $this->isScheduled($scheduledAt);
            // Modo de preço da categoria no envio: decide se o pedido passa por diagnóstico e orçamento
            $request->pricing_mode = $category->pricing_mode;
            $request->sessions = $sessions;
            $request->discount_percent = $discount;
            $request->save();

            foreach ($stored as $file) {
                ServiceRequestMedia::query()->create([...$file, 'service_request_id' => $request->id]);
            }
            // Republicar mantém as fotos e o vídeo do pedido original (os mesmos ficheiros)
            foreach ($republishedFrom->media ?? [] as $original) {
                ServiceRequestMedia::query()->create([
                    ...$original->only(['field_key', 'kind', 'path', 'original_name', 'mime', 'size']),
                    'service_request_id' => $request->id,
                ]);
            }

            return $request;
        });

        $this->audit->record('requests.created', $request, ['amount' => $request->amount, 'pricing_mode' => $request->pricing_mode->value, 'republished_from' => $republishedFrom?->id], $client->id);

        $this->matching->start($request);

        return $request->refresh();
    }

    /**
     * Valor do pedido conforme o modo de preço da categoria. Hora/pacote: estimativa ao preço máximo do cliente;
     * o total final fixa-se com o preço por hora do técnico que aceitar (nunca acima desta estimativa).
     */
    private function amountFor(Category $category, ?int $maxHourlyRate, int $durationMinutes, int $sessions, int $discount): int
    {
        return match ($category->pricing_mode) {
            PricingMode::Fixed => $category->effectivePrice()->fixed_price
                ?? throw new ApiException('price_unavailable', 'Este serviço ainda não tem preço definido. Tente mais tarde.', 409),
            PricingMode::Quote => 0,
            PricingMode::HourlyPackage => HourlyPricing::total(
                $maxHourlyRate ?? throw new ApiException('validation_failed', 'Os dados enviados são inválidos.', 422, ['max_hourly_rate' => ['Indique o preço máximo por hora.']]),
                $durationMinutes, $sessions, $discount,
            ),
        };
    }

    /**
     * Guarda os ficheiros no disco privado antes da transação (o caminho não depende do id do pedido).
     *
     * @param  array<string, list<UploadedFile>>  $media
     * @return list<array{field_key: string, kind: string, path: string, original_name: string, mime: string, size: int}>
     */
    private function storeMedia(array $media): array
    {
        $stored = [];
        foreach ($media as $key => $files) {
            foreach ($files as $file) {
                $kind = str_starts_with((string) $file->getMimeType(), 'video/') ? 'video' : 'photo';
                $stored[] = [
                    'field_key' => $key,
                    'kind' => $kind,
                    'path' => (string) $file->storeAs('requests/'.now()->format('Y/m'), Str::uuid()->toString().'.'.$file->extension(), 'local'),
                    'original_name' => Str::limit($file->getClientOriginalName(), 200, ''),
                    'mime' => (string) $file->getMimeType(),
                    'size' => (int) $file->getSize(),
                ];
            }
        }

        return $stored;
    }

    private function ensureWithinActiveLimit(User $client): void
    {
        $max = (int) config('longa.requests.max_active_per_client');
        $active = ServiceRequest::query()
            ->where('client_id', $client->id)
            ->whereIn('state', array_map(fn (RequestState $s) => $s->value, RequestState::active()))
            ->count();

        if ($active >= $max) {
            throw new ApiException('too_many_active_requests', "Já tem {$active} pedido(s) à procura de técnico. Aguarde ou cancele um deles.", 409);
        }
    }

    private function ensureNotDuplicate(User $client, int $categoryId, Carbon $scheduledAt): void
    {
        $window = (int) config('longa.requests.duplicate_window_minutes');

        $duplicate = ServiceRequest::query()
            ->where('client_id', $client->id)
            ->where('category_id', $categoryId)
            ->whereIn('state', [RequestState::Searching->value, RequestState::Accepted->value])
            ->whereBetween('scheduled_at', [$scheduledAt->copy()->subMinutes($window), $scheduledAt->copy()->addMinutes($window)])
            ->exists();

        if ($duplicate) {
            throw new ApiException('duplicate_request', 'Já tem um pedido igual para essa altura.', 409);
        }
    }

    /**
     * Outro dia (não hoje, hora de Angola) e com o fecho (X h antes) ainda bem no futuro.
     */
    private function isScheduled(Carbon $scheduledAt): bool
    {
        $closeAt = $scheduledAt->copy()->subHours(app(PlatformSettings::class)->int('scheduled_request_close_hours'));

        return ! $scheduledAt->copy()->setTimezone('Africa/Luanda')->isSameDay(now('Africa/Luanda'))
            && $closeAt->gt(now()->addMinutes(app(PlatformSettings::class)->int('dispatch_search_minutes')));
    }
}
