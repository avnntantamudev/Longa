<?php

declare(strict_types=1);

use App\Modules\Matching\Services\MatchingCoordinator;
use App\Modules\Requests\Enums\OfferState;
use App\Modules\Requests\Enums\RequestState;
use App\Modules\Requests\Events\OfferExpired;
use App\Modules\Requests\Events\OfferSent;
use App\Modules\Requests\Events\RequestExpired;
use App\Modules\Requests\Models\ServiceRequest;
use App\Modules\Score\Models\ScoreEvent;
use App\Modules\Users\Models\Category;
use App\Modules\Users\Models\ProviderProfile;
use Database\Factories\ProviderProfileFactory;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    Event::fake([OfferSent::class, OfferExpired::class, RequestExpired::class]);
    config(['longa.matching.first_wave_size' => 2, 'longa.matching.next_wave_size' => 3]);
    $this->sgme = Category::query()->where('slug', 'sgme-gestao-empresarial')->firstOrFail();
    [$lat, $lng] = ProviderProfileFactory::TALATONA;
    foreach (range(1, 6) as $i) {
        ProviderProfile::factory()->verified(100 - $i)->near($lat, $lng, $i * 200)->withCategories($this->sgme)->create();
    }
    $this->request = ServiceRequest::factory()->forCategory($this->sgme)->create();
    $this->coordinator = app(MatchingCoordinator::class);
});

it('envia ondas sucessivas quando ninguém aceita e regista as expirações no score', function () {
    $this->coordinator->start($this->request);
    expect($this->request->offers()->count())->toBe(2);

    // Antes do fim do prazo da onda nada avança
    $this->travel(30)->seconds();
    $this->artisan('longa:matching-tick')->assertSuccessful();
    expect($this->request->offers()->count())->toBe(2);

    // Prazo da onda 1 acabou: ofertas expiram e segue a onda 2 (3 formadores seguintes)
    $this->travel(31)->seconds();
    $this->artisan('longa:matching-tick')->assertSuccessful();

    expect($this->request->offers()->where('wave', 1)->pluck('state')->unique()->all())->toBe([OfferState::Expired])
        ->and($this->request->offers()->where('wave', 2)->count())->toBe(3)
        ->and($this->request->fresh()?->wave)->toBe(2)
        ->and(ScoreEvent::query()->where('type', ScoreEvent::OFFER_EXPIRED)->count())->toBe(2);

    Event::assertDispatchedTimes(OfferExpired::class, 2);
    Event::assertDispatchedTimes(OfferSent::class, 5);
});

it('expira o pedido quando acabam os formadores', function () {
    $this->coordinator->start($this->request);

    // onda 1 (2) → onda 2 (3) → onda 3 (1) → sem candidatos
    foreach (range(1, 4) as $i) {
        $this->travel(61)->seconds();
        $this->artisan('longa:matching-tick');
    }

    expect($this->request->fresh()?->state)->toBe(RequestState::Expired)
        ->and($this->request->offers()->count())->toBe(6);
    Event::assertDispatched(RequestExpired::class, fn ($e) => $e->reason === 'timeout');
});

it('expira o pedido ao fim do tempo total mesmo com ofertas abertas', function () {
    config(['longa.requests.ttl' => 90, 'longa.requests.offer_ttl' => 60]);
    $this->coordinator->start($this->request);

    $this->travel(61)->seconds();
    $this->artisan('longa:matching-tick'); // onda 2 enviada, mas só até ao fim do pedido (90 s)

    $wave2 = $this->request->offers()->where('wave', 2)->first();
    expect((int) round(now()->diffInSeconds($wave2?->expires_at)))->toBeLessThanOrEqual(29);

    $this->travel(30)->seconds();
    $this->artisan('longa:matching-tick');

    expect($this->request->fresh()?->state)->toBe(RequestState::Expired)
        ->and($this->request->offers()->whereIn('state', OfferState::open())->count())->toBe(0);
});

it('é idempotente: avançar duas vezes não duplica ondas', function () {
    $this->coordinator->start($this->request);
    $this->travel(61)->seconds();

    $this->coordinator->advance($this->request);
    $this->coordinator->advance($this->request->fresh() ?? $this->request);
    $this->artisan('longa:matching-tick');

    expect($this->request->offers()->where('wave', 2)->count())->toBe(3)
        ->and($this->request->offers()->where('wave', 3)->count())->toBe(0);
});
