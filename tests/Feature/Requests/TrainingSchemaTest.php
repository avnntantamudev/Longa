<?php

declare(strict_types=1);

use App\Modules\Requests\Models\RequestOffer;
use App\Modules\Requests\Models\TrainingRequest;
use App\Modules\Requests\Services\Commission;
use App\Modules\Sessions\Models\TrainingSession;
use App\Modules\Users\Models\TrainerProfile;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

function offerFor(TrainingRequest $request, TrainerProfile $trainer): RequestOffer
{
    return RequestOffer::query()->create([
        'training_request_id' => $request->id,
        'trainer_profile_id' => $trainer->id,
        'wave' => 1, 'rank' => 1, 'distance_m' => 1000, 'score_snapshot' => 50,
        'sent_at' => now(), 'expires_at' => now()->addMinute(),
    ]);
}

it('calcula a comissão em Kz inteiros, descontada ao formador', function (int $amount, int $commission, int $net) {
    expect(Commission::split($amount, 15))->toBe(['amount' => $amount, 'commission' => $commission, 'trainer_net' => $net]);
})->with([
    [25000, 3750, 21250],
    [5000, 750, 4250],
    [2010, 302, 1708], // 301,5 → 302 (meio para cima)
    [3333, 500, 2833], // 499,95 → 500
]);

it('impede duas ofertas aceites no mesmo pedido ao nível da base de dados', function () {
    $request = TrainingRequest::factory()->create();
    $a = offerFor($request, TrainerProfile::factory()->create());
    $b = offerFor($request, TrainerProfile::factory()->create());

    DB::table('request_offers')->where('id', $a->id)->update(['state' => 'accepted']);

    expect(fn () => DB::table('request_offers')->where('id', $b->id)->update(['state' => 'accepted']))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('impede duas aulas para o mesmo pedido', function () {
    $request = TrainingRequest::factory()->create();
    $trainer = TrainerProfile::factory()->create();
    $data = [
        'training_request_id' => $request->id, 'student_id' => $request->student_id, 'trainer_profile_id' => $trainer->id,
        'skill_id' => $request->skill_id, 'title' => 'x', 'amount' => 1, 'commission' => 0, 'trainer_net' => 1,
        'scheduled_at' => now(), 'modality' => 'in_person', 'municipality' => 'a', 'neighborhood' => 'b',
        'latitude' => -8.9, 'longitude' => 13.1,
    ];

    TrainingSession::query()->create($data);

    expect(fn () => TrainingSession::query()->create($data))->toThrow(UniqueConstraintViolationException::class);
});

it('impede a mesma oferta duas vezes ao mesmo formador', function () {
    $request = TrainingRequest::factory()->create();
    $trainer = TrainerProfile::factory()->create();
    offerFor($request, $trainer);

    expect(fn () => offerFor($request, $trainer))->toThrow(UniqueConstraintViolationException::class);
});
