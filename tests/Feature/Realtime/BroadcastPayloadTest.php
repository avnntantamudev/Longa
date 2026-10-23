<?php

declare(strict_types=1);

use App\Modules\Matching\Services\MatchingCoordinator;
use App\Modules\Requests\Events\OfferSent;
use App\Modules\Requests\Events\RequestMatched;
use App\Modules\Requests\Models\RequestOffer;
use App\Modules\Requests\Models\TrainingRequest;
use App\Modules\Sessions\Models\TrainingSession;
use App\Modules\Users\Models\Skill;
use App\Modules\Users\Models\TrainerProfile;
use Database\Factories\TrainerProfileFactory;
use Illuminate\Support\Facades\Event;

it('a oferta em tempo real vai só para o formador e sem dados privados do aluno', function () {
    Event::fake([OfferSent::class]);
    $sgme = Skill::query()->where('slug', 'sgme-gestao-empresarial')->firstOrFail();
    [$lat, $lng] = TrainerProfileFactory::TALATONA;
    $trainer = TrainerProfile::factory()->verified()->near($lat, $lng, 900)->withSkills($sgme)->create();
    $request = TrainingRequest::factory()->forSkill($sgme)->create();

    app(MatchingCoordinator::class)->start($request);

    Event::assertDispatched(OfferSent::class, function (OfferSent $event) use ($trainer, $request) {
        $payload = json_encode($event->broadcastWith());

        return $event->broadcastOn()->name === "private-user.{$trainer->user_id}"
            && $event->broadcastAs() === 'offer.sent'
            && ! str_contains((string) $payload, (string) $request->latitude)
            && ! str_contains((string) $payload, $request->student->phone)
            && str_contains((string) $payload, 'Talatona');
    });
});

it('o pedido aceite é anunciado ao aluno e aos formadores que perderam', function () {
    $request = TrainingRequest::factory()->create();
    $session = new TrainingSession;
    $session->id = 99;

    $event = new RequestMatched($request, $session, [11, 12]);

    expect(array_map(fn ($c) => $c->name, $event->broadcastOn()))
        ->toBe(["private-request.{$request->id}", 'private-user.11', 'private-user.12'])
        ->and($event->broadcastWith())->toBe(['request_id' => $request->id, 'session_id' => 99, 'state' => 'draft']);
    expect(RequestOffer::query()->count())->toBe(0);
});
