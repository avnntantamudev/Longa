<?php

declare(strict_types=1);

namespace App\Modules\Requests\Services;

use App\Modules\Requests\Enums\RequestState;
use App\Modules\Requests\Models\ServiceRequest;
use App\Modules\Sessions\Models\Conversation;
use Illuminate\Support\Carbon;

/**
 * Aulas de um pacote: quando o pagamento do pacote fica retido, cria uma aula (pedido filho) por data, já
 * atribuída ao técnico e coberta pelo pagamento do pacote. Cada aula tem o seu ciclo (a caminho, início, QR) e,
 * ao ser confirmada, liberta a sua parte do valor retido no pacote (ver SessionSettlement).
 */
class PackageLessons
{
    /**
     * Cria as aulas (idempotente). Chamar dentro da transação, com a linha do pacote bloqueada.
     */
    public function createFor(ServiceRequest $package): void
    {
        if (! $package->isPackage() || $package->provider_profile_id === null || $package->lessons()->exists()) {
            return;
        }

        $dates = array_map(fn (string $d) => Carbon::parse($d), $package->lesson_schedule ?? []);
        $amounts = self::shares($package->amount, count($dates));
        $total = count($dates);

        foreach ($dates as $i => $date) {
            $split = Commission::split($amounts[$i], $package->commission_percent);
            $lesson = new ServiceRequest([
                ...$package->only([
                    'client_id', 'category_id', 'description', 'answers', 'max_hourly_rate', 'duration_minutes', 'payment_method',
                    'modality', 'province', 'municipality', 'neighborhood', 'latitude', 'longitude',
                ]),
                'title' => "{$package->title} · aula ".($i + 1)."/{$total}",
                'scheduled_at' => $date,
                'amount' => $amounts[$i],
                'commission' => $split['commission'],
                'provider_net' => $split['provider_net'],
                'commission_percent' => $package->commission_percent,
                'payment_status' => ServiceRequest::PAYMENT_HELD,
                'package_id' => $package->id,
                'package_sequence' => $i + 1,
            ]);
            $lesson->pricing_mode = $package->pricing_mode;
            $lesson->hourly_rate = $package->hourly_rate;
            $lesson->sessions = 1;
            $lesson->scheduled = true;
            $lesson->state = RequestState::Accepted;
            $lesson->provider_profile_id = $package->provider_profile_id;
            $lesson->matched_at = now();
            $lesson->save();

            Conversation::query()->create(['service_request_id' => $lesson->id]);
        }
    }

    /**
     * Valor de cada aula: partes iguais e o resto dos cêntimos na última (somam sempre o total).
     *
     * @return list<int>
     */
    public static function shares(int $total, int $count): array
    {
        if ($count <= 0) {
            return [];
        }
        $base = intdiv($total, $count);
        $shares = array_fill(0, $count, $base);
        $shares[$count - 1] += $total - $base * $count;

        return $shares;
    }
}
