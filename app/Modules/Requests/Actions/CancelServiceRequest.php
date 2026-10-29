<?php

declare(strict_types=1);

namespace App\Modules\Requests\Actions;

use App\Modules\Requests\Enums\OfferState;
use App\Modules\Requests\Enums\RequestState;
use App\Modules\Requests\Events\OfferExpired;
use App\Modules\Requests\Events\RequestCancelled;
use App\Modules\Requests\Models\ServiceRequest;
use App\Modules\Users\Models\User;
use App\Support\Api\ApiException;
use App\Support\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * O cliente cancela enquanto procura técnico. As ofertas abertas ficam "lost".
 * Um pedido já aceite não se cancela aqui (o cancelamento de serviços é da Fase 3).
 */
class CancelServiceRequest
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(ServiceRequest $request, User $client, ?string $reason): ServiceRequest
    {
        return DB::transaction(function () use ($request, $client, $reason) {
            /** @var ServiceRequest $locked */
            $locked = ServiceRequest::query()->lockForUpdate()->findOrFail($request->id);

            if ($locked->state === RequestState::Accepted) {
                throw new ApiException('request_already_matched', 'Um técnico já aceitou este pedido. Veja o serviço marcado.', 409);
            }

            $locked->by($client)->transitionTo(RequestState::Cancelled, [
                'cancelled_by' => $client->id,
                'cancel_reason' => $reason,
                'cancelled_at' => now(),
                'next_wave_at' => null,
            ]);

            $open = $locked->offers()->whereIn('state', OfferState::open())->with('provider')->get();
            foreach ($open as $offer) {
                $offer->transitionTo(OfferState::Lost, ['responded_at' => now()]);
                OfferExpired::dispatch($offer);
            }

            RequestCancelled::dispatch($locked, $open->map(fn ($o) => $o->provider->user_id)->values()->all());
            $this->audit->record('requests.cancelled', $locked, array_filter(['reason' => $reason]), $client->id);

            return $locked;
        });
    }
}
