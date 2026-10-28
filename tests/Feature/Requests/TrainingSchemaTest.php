<?php

declare(strict_types=1);

use App\Modules\Requests\Models\RequestOffer;
use App\Modules\Requests\Models\ServiceRequest;
use App\Modules\Requests\Services\Commission;
use App\Modules\Sessions\Models\ServiceJob;
use App\Modules\Users\Models\ProviderProfile;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

function offerFor(ServiceRequest $request, ProviderProfile $provider): RequestOffer
{
    return RequestOffer::query()->create([
        'service_request_id' => $request->id,
        'provider_profile_id' => $provider->id,
        'wave' => 1, 'rank' => 1, 'distance_m' => 1000, 'score_snapshot' => 50,
        'sent_at' => now(), 'expires_at' => now()->addMinute(),
    ]);
}

it('calcula a comissão em Kz inteiros, descontada ao formador', function (int $amount, int $commission, int $net) {
    expect(Commission::split($amount, 15))->toBe(['amount' => $amount, 'commission' => $commission, 'provider_net' => $net]);
})->with([
    [25000, 3750, 21250],
    [5000, 750, 4250],
    [2010, 302, 1708], // 301,5 → 302 (meio para cima)
    [3333, 500, 2833], // 499,95 → 500
]);

it('impede duas ofertas aceites no mesmo pedido ao nível da base de dados', function () {
    $request = ServiceRequest::factory()->create();
    $a = offerFor($request, ProviderProfile::factory()->create());
    $b = offerFor($request, ProviderProfile::factory()->create());

    DB::table('request_offers')->where('id', $a->id)->update(['state' => 'accepted']);

    expect(fn () => DB::table('request_offers')->where('id', $b->id)->update(['state' => 'accepted']))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('impede duas aulas para o mesmo pedido', function () {
    $request = ServiceRequest::factory()->create();
    $provider = ProviderProfile::factory()->create();
    $data = [
        'service_request_id' => $request->id, 'client_id' => $request->client_id, 'provider_profile_id' => $provider->id,
        'category_id' => $request->category_id, 'title' => 'x', 'amount' => 1, 'commission' => 0, 'provider_net' => 1,
        'scheduled_at' => now(), 'modality' => 'in_person', 'municipality' => 'a', 'neighborhood' => 'b',
        'latitude' => -8.9, 'longitude' => 13.1,
    ];

    ServiceJob::query()->create($data);

    expect(fn () => ServiceJob::query()->create($data))->toThrow(UniqueConstraintViolationException::class);
});

it('impede a mesma oferta duas vezes ao mesmo formador', function () {
    $request = ServiceRequest::factory()->create();
    $provider = ProviderProfile::factory()->create();
    offerFor($request, $provider);

    expect(fn () => offerFor($request, $provider))->toThrow(UniqueConstraintViolationException::class);
});
