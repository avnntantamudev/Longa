<?php

declare(strict_types=1);

namespace App\Modules\Requests\Actions;

use App\Modules\Notifications\Notifications\RebookInterestNotification;
use App\Modules\Requests\Enums\RequestState;
use App\Modules\Requests\Models\ProviderRebookInterest;
use App\Modules\Requests\Models\ServiceRequest;
use App\Modules\Requests\Services\VipFeatureAccess;
use App\Modules\Users\Models\User;
use App\Support\Api\ApiException;

/**
 * O cliente quis voltar a chamar o técnico de um serviço pago, mas o técnico não é VIP (não aceita pedidos
 * diretos). Fica registado (uma vez por cliente e técnico) e o técnico é avisado de quantos clientes o querem.
 */
class RecordRebookInterest
{
    public function __construct(private readonly VipFeatureAccess $access) {}

    /**
     * @return int quantos clientes diferentes já quiseram voltar a chamar este técnico
     */
    public function handle(ServiceRequest $request, User $client): int
    {
        if ($request->client_id !== $client->id || $request->state !== RequestState::Paid || $request->provider_profile_id === null) {
            throw new ApiException('rebook_not_available', 'Só pode voltar a chamar o técnico de um serviço seu já pago.', 409);
        }
        if ($this->access->acceptsDirect($request->provider_profile_id)) {
            throw new ApiException('rebook_use_direct', 'Este técnico aceita pedidos diretos: use "Pedir de novo".', 409);
        }

        $inserted = ProviderRebookInterest::query()->insertOrIgnore([
            'provider_profile_id' => $request->provider_profile_id,
            'client_id' => $client->id,
            'service_request_id' => $request->id,
            'created_at' => now(),
        ]);
        $total = ProviderRebookInterest::forProvider($request->provider_profile_id)->count();

        // Só avisa na primeira vez de cada cliente (carregar de novo não gera notificações repetidas)
        if ($inserted > 0) {
            $request->provider->user->notify(new RebookInterestNotification($total));
        }

        return $total;
    }
}
