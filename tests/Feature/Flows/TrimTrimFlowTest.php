<?php

declare(strict_types=1);

use App\Modules\Notifications\Notifications\NewOfferNotification;
use App\Modules\Notifications\Notifications\RequestOutcomeNotification;
use App\Modules\Requests\Enums\OfferState;
use App\Modules\Users\Enums\Role;
use App\Modules\Users\Models\Skill;
use App\Modules\Users\Models\TrainerProfile;
use App\Modules\Users\Models\User;
use Database\Factories\TrainerProfileFactory;
use Illuminate\Support\Facades\Notification;

/*
| Fluxo completo da Fase 2, só pela API (como a PWA e a futura app Flutter):
| aluno publica → 3 formadores recebem a oferta → um aceita → os outros perdem → aula criada → chat funciona.
*/

it('cumpre o fluxo TRIM TRIM de ponta a ponta', function () {
    Notification::fake();
    [$lat, $lng] = TrainerProfileFactory::TALATONA;
    $sgme = Skill::query()->where('slug', 'sgme-gestao-empresarial')->firstOrFail();
    $trainers = collect([800, 1500, 2500])->map(fn (int $m, int $i) => TrainerProfile::factory()->verified(70 - $i)->near($lat, $lng, $m)->withSkills($sgme)->create());
    $farAway = TrainerProfile::factory()->verified()->near($lat, $lng, 9000)->withSkills($sgme)->create();
    $student = User::factory()->withRole(Role::Student)->create();

    // 1. Aluno publica
    apiLogin($student);
    $requestId = $this->postJson('/api/v1/training-requests', [
        'skill_id' => $sgme->id, 'title' => 'Aula de SGME', 'amount' => 25000,
        'scheduled_at' => now()->addDay()->setTime(14, 0)->toIso8601String(), 'modality' => 'in_person',
        'municipality' => 'Talatona', 'neighborhood' => 'Talatona', 'latitude' => $lat, 'longitude' => $lng,
    ])->assertCreated()->assertJsonPath('data.state', 'searching')->json('data.id');

    // 2. Os 3 formadores do raio recebem a oferta (o que está a 9 km não)
    foreach ($trainers as $trainer) {
        Notification::assertSentTo($trainer->user, NewOfferNotification::class);
        apiLogin($trainer->user);
        $this->getJson('/api/v1/offers')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.request_id', $requestId);
    }
    Notification::assertNotSentTo($farAway->user, NewOfferNotification::class);

    // 3. O segundo formador aceita; 4. os outros perdem
    $winner = $trainers[1];
    apiLogin($winner->user);
    $offerId = $this->getJson('/api/v1/offers')->json('data.0.id');
    $sessionId = $this->postJson("/api/v1/offers/{$offerId}/accept")->assertOk()->json('data.id');

    foreach ([$trainers[0], $trainers[2]] as $loser) {
        apiLogin($loser->user);
        $this->getJson('/api/v1/offers')->assertJsonCount(0, 'data');
        $lostOffer = $loser->fresh()?->user->trainerProfile?->id;
        expect(App\Modules\Requests\Models\RequestOffer::query()->where('trainer_profile_id', $lostOffer)->value('state'))->toBe(OfferState::Lost);
    }

    // 5. Aula criada: o aluno vê-a com os dados do formador e é notificado
    apiLogin($student);
    $this->getJson("/api/v1/training-requests/{$requestId}")->assertJsonPath('data.state', 'matched')->assertJsonPath('data.session_id', $sessionId);
    $this->getJson("/api/v1/sessions/{$sessionId}")->assertOk()->assertJsonPath('data.trainer.name', $winner->user->name);
    Notification::assertSentTo($student, RequestOutcomeNotification::class, fn ($n) => $n->outcome === 'matched');

    // 6. Chat funciona nos dois sentidos
    $this->postJson("/api/v1/sessions/{$sessionId}/messages", ['body' => 'Olá! Pronto para a aula de SGME?'])->assertCreated();
    apiLogin($winner->user);
    $this->postJson("/api/v1/sessions/{$sessionId}/messages", ['body' => 'Bom dia! Sim, estou pronto.'])->assertCreated();
    $this->getJson("/api/v1/sessions/{$sessionId}/messages")->assertJsonCount(2, 'data');

    // Um perdedor não entra na aula
    apiLogin($trainers[0]->user);
    $this->getJson("/api/v1/sessions/{$sessionId}/messages")->assertForbidden();
});
