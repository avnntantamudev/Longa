<?php

declare(strict_types=1);

namespace App\Modules\Requests\Actions;

use App\Modules\Requests\Enums\OfferState;
use App\Modules\Requests\Enums\RequestState;
use App\Modules\Requests\Events\RequestMatched;
use App\Modules\Requests\Models\RequestOffer;
use App\Modules\Requests\Models\TrainingRequest;
use App\Modules\Sessions\Enums\SessionState;
use App\Modules\Sessions\Models\Conversation;
use App\Modules\Sessions\Models\TrainingSession;
use App\Modules\Sessions\Services\SessionLifecycle;
use App\Support\Api\ApiException;
use App\Support\Audit\AuditLogger;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Aceitação atómica de uma oferta TRIM TRIM. Tudo ou nada, numa única transação:
 *
 *  1. lock em Redis por pedido (reduz a disputa na BD quando muitos formadores carregam ao mesmo tempo);
 *  2. SELECT … FOR UPDATE na linha do pedido (serializa as aceitações do mesmo pedido entre processos);
 *  3. verifica o estado do pedido e da oferta e o prazo (decidido pelo servidor);
 *  4. cria a aula e a conversa, aceita a oferta, marca as outras como "lost", pedido → "matched";
 *  5. aula eletrónica fica em pending_payment (o aluno paga na janela de pagamento e o valor vai para o
 *     escrow); aula em dinheiro fica logo agendada.
 *
 * Idempotente: se o mesmo formador repetir o pedido depois de ganhar, recebe a mesma aula.
 * Últimas barreiras na BD: índice único de oferta aceite por pedido e de aula por pedido.
 */
class AcceptOffer
{
    private const LOCK_SECONDS = 10;

    private const LOCK_WAIT_SECONDS = 5;

    public function __construct(private readonly SessionLifecycle $lifecycle, private readonly AuditLogger $audit) {}

    public function handle(RequestOffer $offer): TrainingSession
    {
        try {
            return Cache::lock('accept:request:'.$offer->training_request_id, self::LOCK_SECONDS)
                ->block(self::LOCK_WAIT_SECONDS, fn () => $this->accept($offer));
        } catch (LockTimeoutException) {
            throw new ApiException('try_again', 'Muitos pedidos ao mesmo tempo. Tente novamente.', 503);
        }
    }

    private function accept(RequestOffer $offer): TrainingSession
    {
        $result = DB::transaction(function () use ($offer): array {
            /** @var TrainingRequest $request */
            $request = TrainingRequest::query()->lockForUpdate()->findOrFail($offer->training_request_id);
            /** @var RequestOffer $offer */
            $offer = RequestOffer::query()->lockForUpdate()->findOrFail($offer->id);

            // Idempotência: este formador já ganhou esta aula
            if ($offer->state === OfferState::Accepted) {
                return ['session' => TrainingSession::query()->where('training_request_id', $request->id)->firstOrFail(), 'new' => false];
            }

            $this->ensureCanAccept($request, $offer);

            $session = TrainingSession::query()->create([
                'training_request_id' => $request->id,
                'student_id' => $request->student_id,
                'trainer_profile_id' => $offer->trainer_profile_id,
                'skill_id' => $request->skill_id,
                'title' => $request->title,
                'amount' => $request->amount,
                'commission' => $request->commission,
                'trainer_net' => $request->trainer_net,
                'scheduled_at' => $request->scheduled_at,
                'duration_minutes' => $request->duration_minutes,
                'ends_at' => $request->scheduled_at->copy()->addMinutes($request->duration_minutes),
                'payment_method' => $request->payment_method,
                'modality' => $request->modality,
                'municipality' => $request->municipality,
                'neighborhood' => $request->neighborhood,
                'latitude' => $request->latitude,
                'longitude' => $request->longitude,
            ]);
            Conversation::query()->create(['training_session_id' => $session->id]);

            $offer->transitionTo(OfferState::Accepted, ['responded_at' => now()]);

            $losers = $request->offers()
                ->whereKeyNot($offer->id)
                ->whereIn('state', OfferState::open())
                ->with('trainer')
                ->get();
            foreach ($losers as $loser) {
                $loser->transitionTo(OfferState::Lost, ['responded_at' => now()]);
            }

            $request->transitionTo(RequestState::Matched, [
                'matched_at' => now(),
                'assigned_trainer_profile_id' => $offer->trainer_profile_id,
                'next_wave_at' => null,
            ]);

            if ($session->isCash()) {
                $this->lifecycle->record($session, 'scheduled', null, ['payment_method' => 'cash'], null, SessionState::Scheduled);
            } else {
                $session->forceFill([
                    'state' => SessionState::PendingPayment,
                    'payment_due_at' => now()->addMinutes((int) config('longa.session_rules.payment_window_minutes')),
                ])->save();
                $this->lifecycle->record($session, 'payment_requested', null, ['amount' => $session->amount, 'due_at' => $session->payment_due_at?->toIso8601String()], null, SessionState::PendingPayment);
            }

            RequestMatched::dispatch($request, $session, $losers->map(fn (RequestOffer $o) => $o->trainer->user_id)->values()->all());

            return ['session' => $session, 'new' => true];
        });

        if ($result['new']) {
            $this->audit->record('requests.offer_accepted', $result['session'], ['offer_id' => $offer->id]);
        }

        return $result['session'];
    }

    private function ensureCanAccept(TrainingRequest $request, RequestOffer $offer): void
    {
        if ($request->state === RequestState::Matched) {
            throw new ApiException('request_already_matched', 'Outro formador aceitou este pedido primeiro.', 409);
        }

        if ($request->state === RequestState::Cancelled) {
            throw new ApiException('request_cancelled', 'O aluno cancelou este pedido.', 410);
        }

        if ($request->state !== RequestState::Searching || $request->expires_at?->isPast()) {
            throw new ApiException('request_closed', 'Este pedido já terminou.', 410);
        }

        if ($offer->state === OfferState::Declined) {
            throw new ApiException('offer_declined', 'Já recusou esta oferta.', 409);
        }

        if (! in_array($offer->state, OfferState::open(), true) || $offer->expires_at->lte(now())) {
            throw new ApiException('offer_expired', 'O prazo para aceitar terminou.', 410);
        }
    }
}
