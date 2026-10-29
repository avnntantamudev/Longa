<?php

declare(strict_types=1);

use App\Modules\Matching\Services\MatchingCoordinator;
use App\Modules\Requests\Events\OfferSent;
use App\Modules\Requests\Events\RequestMatched;
use App\Modules\Requests\Models\RequestOffer;
use App\Modules\Requests\Models\ServiceRequest;
use App\Modules\Users\Models\Category;
use App\Modules\Users\Models\ProviderProfile;
use Database\Factories\ProviderProfileFactory;
use Illuminate\Support\Facades\Event;

it('a oferta em tempo real vai só para o técnico e sem dados privados do cliente', function () {
    Event::fake([OfferSent::class]);
    $sgme = Category::query()->where('slug', 'sgme-gestao-empresarial')->firstOrFail();
    [$lat, $lng] = ProviderProfileFactory::TALATONA;
    $provider = ProviderProfile::factory()->verified()->near($lat, $lng, 900)->withCategories($sgme)->create();
    $request = ServiceRequest::factory()->forCategory($sgme)->create();

    app(MatchingCoordinator::class)->start($request);

    Event::assertDispatched(OfferSent::class, function (OfferSent $event) use ($provider, $request) {
        $payload = json_encode($event->broadcastWith());

        return $event->broadcastOn()->name === "private-user.{$provider->user_id}"
            && $event->broadcastAs() === 'offer.sent'
            && ! str_contains((string) $payload, (string) $request->latitude)
            && ! str_contains((string) $payload, $request->client->phone)
            && str_contains((string) $payload, 'Talatona');
    });
});

it('o pedido aceite é anunciado ao cliente e aos técnicos que perderam', function () {
    $request = ServiceRequest::factory()->create();

    $event = new RequestMatched($request, [11, 12]);

    expect(array_map(fn ($c) => $c->name, $event->broadcastOn()))
        ->toBe(["private-request.{$request->id}", 'private-user.11', 'private-user.12'])
        ->and($event->broadcastWith())->toBe(['request_id' => $request->id, 'session_id' => $request->id, 'state' => 'created']);
    expect(RequestOffer::query()->count())->toBe(0);
});
