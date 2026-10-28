<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Payments\Actions\ConfirmCashPayment;
use App\Modules\Payments\Services\CommissionDebts;
use App\Modules\Sessions\Http\Resources\SessionResource;
use App\Modules\Sessions\Models\TrainingSession;
use App\Modules\Users\Models\TrainerProfile;
use App\Modules\Users\Models\User;
use App\Modules\Wallet\Ledger\LedgerException;
use App\Support\Api\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Aulas pagas em dinheiro.
 *
 * @tags Pagamentos
 */
class CashController extends Controller
{
    /**
     * Confirmar que pagou ao formador em mão (aluno).
     *
     * Requer `Idempotency-Key`. Só depois de a aula estar concluída por QR. A comissão do BISCA fica em dívida
     * do formador (descontada do saldo disponível dele, se houver).
     */
    public function confirm(Request $request, TrainingSession $session, ConfirmCashPayment $action): SessionResource
    {
        Gate::authorize('actAsStudent', $session);

        /** @var User $user */
        $user = $request->user();

        return new SessionResource($action->handle($session, $user)->load(['trainer.user', 'student', 'skill', 'conversation']));
    }

    /**
     * Admin: registar comissão recebida de um formador.
     *
     * Requer `Idempotency-Key` (usado também como chave da transação no ledger).
     */
    public function receive(Request $request, TrainerProfile $trainer, CommissionDebts $debts): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'integer', 'min:1'],
            'note' => ['nullable', 'string', 'max:255'],
        ], [], ['amount' => 'valor', 'note' => 'nota']);

        /** @var User $admin */
        $admin = $request->user();

        try {
            $transaction = $debts->receive($trainer->id, (int) $data['amount'], 'admin:'.$admin->id.':'.$request->header('Idempotency-Key'), $admin, $data['note'] ?? null);
        } catch (LedgerException $e) {
            throw new ApiException('invalid_amount', $e->getMessage(), 422);
        }

        return response()->json(['data' => [
            'transaction_id' => $transaction->id,
            'outstanding' => $debts->outstanding($trainer->id),
        ]], 201);
    }
}
