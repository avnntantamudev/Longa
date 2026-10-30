<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Payments\Actions\PayForSession;
use App\Modules\Payments\Enums\PaymentMethod;
use App\Modules\Payments\Models\PaymentPhase;
use App\Modules\Payments\Services\PhasedPayments;
use App\Modules\Requests\Models\ServiceRequest;
use App\Modules\Sessions\Http\Resources\SessionResource;
use App\Modules\Users\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * @tags Pagamentos
 */
class PaymentController extends Controller
{
    /**
     * Pagar o serviço (cliente).
     *
     * Requer o cabeçalho `Idempotency-Key`. O valor fica retido (escrow) até o serviço ser confirmado por QR.
     * Gateway simulado nesta fase. Erros: `payment_failed` (402), `payment_window_expired` (410).
     */
    public function pay(Request $request, ServiceRequest $session, PayForSession $action): SessionResource
    {
        Gate::authorize('pay', $session);
        $data = $request->validate(['method' => ['required', Rule::in(PaymentMethod::digitalValues())]], [], ['method' => 'meio de pagamento']);

        /** @var User $user */
        $user = $request->user();

        return new SessionResource($action->handle($session, $user, $data['method'])->load(['provider.user', 'client', 'category', 'conversation']));
    }

    /**
     * Confirmar uma fase do serviço (cliente; pagamento por fases). A fase é libertada ao técnico com a comissão
     * e a seguinte fica a pagar. A última confirma-se com a confirmação do serviço.
     */
    public function confirmPhase(Request $request, PaymentPhase $phase, PhasedPayments $phases): SessionResource
    {
        Gate::authorize('actAsClient', $phase->request);

        /** @var User $user */
        $user = $request->user();
        $phases->confirm($phase, $user);

        return new SessionResource($phase->request->fresh(['provider.user', 'client', 'category', 'conversation']) ?? $phase->request);
    }
}
