<?php

declare(strict_types=1);

namespace App\Modules\Sessions\Console;

use App\Modules\Requests\Models\ServiceRequest;
use App\Modules\Requests\Services\Commission;
use App\Modules\Sessions\Models\Conversation;
use App\Modules\Sessions\Models\ServiceJob;
use App\Modules\Users\Enums\Role;
use App\Modules\Users\Models\Category;
use App\Modules\Users\Models\ProviderProfile;
use App\Modules\Users\Models\User;
use Illuminate\Console\Command;

/**
 * Cria um serviço de demonstração a começar agora, para testar o fluxo completo com dois telemóveis
 * (só fora de produção). Os dois utilizadores entram com o número e o código OTP (driver "log": ver o log).
 */
class DemoLessonCommand extends Command
{
    protected $signature = 'longa:demo:lesson
        {--client=+244900000001 : Telefone do cliente}
        {--provider=+244900000002 : Telefone do técnico}
        {--amount=25000 : Valor em Kz}
        {--cash : Pagamento em mão em vez de eletrónico}';

    protected $description = 'Cria um serviço de demonstração (cliente + técnico) a começar agora, à espera de pagamento';

    public function handle(): int
    {
        if (app()->isProduction()) {
            $this->error('Não disponível em produção.');

            return self::FAILURE;
        }

        $category = Category::query()->firstOrFail();
        $client = $this->user((string) $this->option('client'), 'Cliente Demo', Role::Client);
        $providerUser = $this->user((string) $this->option('provider'), 'Técnico Demo', Role::Provider);
        $provider = $providerUser->providerProfile ?? ProviderProfile::factory()->verified()->for($providerUser)->create();
        $cash = (bool) $this->option('cash');
        $split = Commission::split((int) $this->option('amount'));

        $request = ServiceRequest::factory()->create([
            'client_id' => $client->id, 'category_id' => $category->id, 'amount' => $split['amount'],
            'payment_method' => $cash ? 'cash' : 'electronic', 'duration_minutes' => 60,
        ]);
        $session = ServiceJob::query()->create([
            'service_request_id' => $request->id, 'client_id' => $client->id, 'provider_profile_id' => $provider->id,
            'category_id' => $category->id, 'title' => 'Serviço de demonstração', ...$split,
            'scheduled_at' => now()->addMinutes(5), 'duration_minutes' => 60, 'payment_method' => $cash ? 'cash' : 'electronic',
            'payment_due_at' => $cash ? null : now()->addMinutes((int) config('longa.session_rules.payment_window_minutes')),
            'modality' => 'in_person', 'municipality' => 'Talatona', 'neighborhood' => 'Talatona', 'latitude' => -8.9167, 'longitude' => 13.1833,
        ]);
        $session->forceFill(['state' => $cash ? 'scheduled' : 'pending_payment'])->save();
        Conversation::query()->create(['service_job_id' => $session->id]);

        $this->info("Serviço #{$session->id} criado (".($cash ? 'em mão' : 'pagar em 10 minutos').').');
        $this->table(['Papel', 'Telefone', 'Abrir'], [
            ['Cliente', $client->phone, url("/servicos/{$session->id}")],
            ['Técnico', $providerUser->phone, url("/servicos/{$session->id}")],
        ]);
        $this->line('Fluxo: cliente paga → técnico "Iniciar serviço" → "Terminar serviço" → cliente "Escanear QR para confirmar".');

        return self::SUCCESS;
    }

    private function user(string $phone, string $name, Role $role): User
    {
        $user = User::query()->firstOrCreate(['phone' => $phone], ['name' => $name, 'phone_verified_at' => now(), 'onboarded_at' => now()]);
        $user->assignRole($role->value);

        return $user;
    }
}
