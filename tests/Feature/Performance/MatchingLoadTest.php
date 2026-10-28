<?php

declare(strict_types=1);

use App\Modules\Matching\Services\MatchingCoordinator;
use App\Modules\Matching\Services\ProviderMatcher;
use App\Modules\Requests\Models\RequestOffer;
use App\Modules\Requests\Models\ServiceRequest;
use App\Modules\Users\Models\Category;
use Database\Factories\ProviderProfileFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Role;

/*
| Teste de carga simples: 500 formadores elegíveis para o mesmo pedido.
| Mede (1) a consulta de matching e (2) o envio: inserir as ofertas e pôr em fila as notificações
| e os broadcasts. Os limites são folgados para CI; os tempos medidos ficam em storage/logs/performance.log.
*/

const ELIGIBLE = 500;

beforeEach(function () {
    $this->sgme = Category::query()->where('slug', 'sgme-gestao-empresarial')->firstOrFail();
    [$lat, $lng] = ProviderProfileFactory::TALATONA;
    $now = now();
    $providerRole = Role::findByName('provider', 'web')->id;

    // Inserção em massa (as factories seriam lentas para 500 + ruído)
    $users = array_map(fn (int $i) => [
        'phone' => sprintf('+2449%08d', 50_000_000 + $i), 'name' => "Formador {$i}", 'phone_verified_at' => $now,
        'onboarded_at' => $now, 'status' => 'active', 'locale' => 'pt', 'created_at' => $now, 'updated_at' => $now,
    ], range(1, ELIGIBLE + 200));
    foreach (array_chunk($users, 250) as $chunk) {
        DB::table('users')->insert($chunk);
    }
    $userIds = DB::table('users')->where('phone', 'like', '+24495%')->orderBy('id')->pluck('id')->all();

    DB::table('model_has_roles')->insert(array_map(fn ($id) => ['role_id' => $providerRole, 'model_type' => App\Modules\Users\Models\User::class, 'model_id' => $id], $userIds));

    // 500 elegíveis espalhados até 4,5 km + 200 fora do raio (6 a 10 km) para a consulta ter de filtrar
    $profiles = [];
    foreach ($userIds as $i => $userId) {
        $inside = $i < ELIGIBLE;
        $meters = $inside ? random_int(100, 4500) : random_int(6000, 10000);
        $angle = deg2rad(random_int(0, 359));
        $profiles[] = [
            'user_id' => $userId, 'bio' => 'x', 'modalities' => json_encode(['in_person']),
            'municipality' => 'Talatona', 'neighborhood' => 'Talatona',
            'latitude' => $lat + $meters * cos($angle) / 111_320,
            'longitude' => $lng + $meters * sin($angle) / (111_320 * cos(deg2rad($lat))),
            'service_radius_m' => 5000, 'verification_status' => 'verified', 'score' => random_int(30, 99),
            'is_available' => true, 'onboarding_completed_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ];
    }
    foreach (array_chunk($profiles, 250) as $chunk) {
        DB::table('provider_profiles')->insert($chunk);
    }
    $profileIds = DB::table('provider_profiles')->whereIn('user_id', $userIds)->pluck('id')->all();
    DB::table('category_provider_profile')->insert(array_map(fn ($id) => ['provider_profile_id' => $id, 'category_id' => $this->sgme->id], $profileIds));

    $this->request = ServiceRequest::factory()->forCategory($this->sgme)->create();
});

it('encontra e ordena 500 formadores elegíveis em tempo aceitável', function () {
    $matcher = app(ProviderMatcher::class);
    $matcher->candidates($this->request, 10); // aquecimento (planos de execução, ligação)

    $start = hrtime(true);
    $top = $matcher->candidates($this->request, 10);
    $topMs = (hrtime(true) - $start) / 1e6;

    $start = hrtime(true);
    $all = $matcher->candidates($this->request, 1000);
    $allMs = (hrtime(true) - $start) / 1e6;

    logPerformance('matching', ['eligible' => count($all), 'top10_ms' => round($topMs, 1), 'all_ms' => round($allMs, 1)]);

    expect($all)->toHaveCount(ELIGIBLE)
        ->and($top)->toHaveCount(10)
        ->and($top[0]->rankScore)->toBeGreaterThanOrEqual($top[9]->rankScore)
        ->and($topMs)->toBeLessThan(500)
        ->and($allMs)->toBeLessThan(1500);
});

it('envia uma onda para os 500 (pior caso: todos ao mesmo tempo) em tempo aceitável', function () {
    config(['longa.matching.first_wave_size' => ELIGIBLE]);
    Queue::fake();

    $start = hrtime(true);
    $sent = app(MatchingCoordinator::class)->start($this->request);
    $ms = (hrtime(true) - $start) / 1e6;

    // pushedJobs() agrupa por classe de job
    $queued = array_sum(array_map('count', Queue::pushedJobs()));
    logPerformance('dispatch', ['offers' => $sent, 'queued_jobs' => $queued, 'total_ms' => round($ms, 1), 'per_offer_ms' => round($ms / max(1, $sent), 2)]);

    expect($sent)->toBe(ELIGIBLE)
        ->and(RequestOffer::query()->where('service_request_id', $this->request->id)->count())->toBe(ELIGIBLE)
        // Por oferta: 1 notificação + 1 broadcast; mais o job de avanço da onda
        ->and($queued)->toBeGreaterThanOrEqual(ELIGIBLE * 2)
        ->and($ms)->toBeLessThan(15000);
});

/**
 * @param  array<string, int|float>  $metrics
 */
function logPerformance(string $name, array $metrics): void
{
    $line = now()->toIso8601String()." {$name} ".json_encode($metrics).PHP_EOL;
    file_put_contents(storage_path('logs/performance.log'), $line, FILE_APPEND);
    fwrite(STDERR, $line);
}
