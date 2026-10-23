<?php

declare(strict_types=1);

use App\Modules\Matching\Services\MatchingCoordinator;
use App\Modules\Requests\Enums\OfferState;
use App\Modules\Requests\Enums\RequestState;
use App\Modules\Requests\Models\RequestOffer;
use App\Modules\Requests\Models\TrainingRequest;
use App\Modules\Sessions\Models\TrainingSession;
use App\Modules\Users\Models\Skill;
use App\Modules\Users\Models\TrainerProfile;
use Database\Factories\TrainerProfileFactory;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/*
| Concorrência real: N processos PHP independentes (sem transação de teste partilhada) aceitam
| ofertas do mesmo pedido no mesmo instante. Só um pode ganhar.
| Os dados são gravados de verdade (DatabaseTruncation), porque os outros processos têm de os ver.
*/

const WORKERS = 8;

beforeEach(function () {
    config(['longa.matching.first_wave_size' => WORKERS]);
    $sgme = Skill::query()->where('slug', 'sgme-gestao-empresarial')->firstOrFail();
    [$lat, $lng] = TrainerProfileFactory::TALATONA;

    foreach (range(1, WORKERS) as $i) {
        TrainerProfile::factory()->verified()->near($lat, $lng, 100 * $i)->withSkills($sgme)->create();
    }

    $this->request = TrainingRequest::factory()->forSkill($sgme)->create();
    app(MatchingCoordinator::class)->start($this->request);
});

/**
 * @param  list<int>  $offerIds
 * @return list<array{offer: int, ok: bool, session?: int, code?: string, message?: string}>
 */
function raceToAccept(array $offerIds): array
{
    $php = (new PhpExecutableFinder)->find() ?: 'php';
    // Margem para todos os processos arrancarem a aplicação antes da barreira
    $startAt = microtime(true) + 4;

    $processes = array_map(function (int $offerId) use ($php, $startAt) {
        $process = new Process([$php, base_path('tests/Concurrency/accept-worker.php'), (string) $offerId, sprintf('%.6F', $startAt)], base_path());
        $process->setTimeout(60);
        $process->start();

        return $process;
    }, $offerIds);

    return array_map(function (Process $process) {
        $process->wait();
        $output = trim($process->getOutput());

        return json_decode($output, true) ?? ['offer' => 0, 'ok' => false, 'code' => 'no_output', 'message' => $output.$process->getErrorOutput()];
    }, $processes);
}

it('só um formador consegue a aula quando vários aceitam ao mesmo tempo', function () {
    $offerIds = RequestOffer::query()->where('training_request_id', $this->request->id)->pluck('id')->all();
    expect($offerIds)->toHaveCount(WORKERS);

    $results = raceToAccept($offerIds);

    $winners = array_values(array_filter($results, fn ($r) => $r['ok']));
    $losers = array_values(array_filter($results, fn ($r) => ! $r['ok']));

    expect($winners)->toHaveCount(1, json_encode($results))
        ->and(array_unique(array_column($losers, 'code')))->toBe(['request_already_matched']);

    expect(TrainingSession::query()->count())->toBe(1)
        ->and(RequestOffer::query()->where('state', OfferState::Accepted->value)->count())->toBe(1)
        ->and(RequestOffer::query()->where('state', OfferState::Lost->value)->count())->toBe(WORKERS - 1)
        ->and($this->request->fresh()?->state)->toBe(RequestState::Matched);
});

it('é idempotente sob concorrência: o mesmo formador a aceitar várias vezes recebe a mesma aula', function () {
    $offerId = RequestOffer::query()->where('training_request_id', $this->request->id)->value('id');

    $results = raceToAccept(array_fill(0, 5, $offerId));

    expect(array_column($results, 'ok'))->toBe([true, true, true, true, true])
        ->and(array_unique(array_column($results, 'session')))->toHaveCount(1)
        ->and(TrainingSession::query()->count())->toBe(1);
});
