<?php

declare(strict_types=1);

namespace App\Modules\Requests\Actions;

use App\Modules\Requests\Enums\OfferState;
use App\Modules\Requests\Enums\RequestState;
use App\Modules\Requests\Events\OfferExpired;
use App\Modules\Requests\Events\RequestCancelled;
use App\Modules\Requests\Models\TrainingRequest;
use App\Modules\Users\Models\User;
use App\Support\Api\ApiException;
use App\Support\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * O aluno cancela enquanto procura formador. As ofertas abertas ficam "lost".
 * Um pedido já aceite não se cancela aqui (o cancelamento de aulas é da Fase 3).
 */
class CancelTrainingRequest
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(TrainingRequest $request, User $student, ?string $reason): TrainingRequest
    {
        return DB::transaction(function () use ($request, $student, $reason) {
            /** @var TrainingRequest $locked */
            $locked = TrainingRequest::query()->lockForUpdate()->findOrFail($request->id);

            if ($locked->state === RequestState::Matched) {
                throw new ApiException('request_already_matched', 'Um formador já aceitou este pedido. Veja a aula marcada.', 409);
            }

            $locked->transitionTo(RequestState::Cancelled, [
                'cancelled_by' => $student->id,
                'cancel_reason' => $reason,
                'cancelled_at' => now(),
                'next_wave_at' => null,
            ]);

            $open = $locked->offers()->whereIn('state', OfferState::open())->with('trainer')->get();
            foreach ($open as $offer) {
                $offer->transitionTo(OfferState::Lost, ['responded_at' => now()]);
                OfferExpired::dispatch($offer);
            }

            RequestCancelled::dispatch($locked, $open->map(fn ($o) => $o->trainer->user_id)->values()->all());
            $this->audit->record('requests.cancelled', $locked, array_filter(['reason' => $reason]), $student->id);

            return $locked;
        });
    }
}
