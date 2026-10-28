<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Users\Models\ProviderProfile;
use App\Modules\Users\Models\User;
use App\Modules\Wallet\Http\Resources\WalletMovementResource;
use App\Modules\Wallet\Services\WalletSummary;
use App\Support\Api\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Carteira do formador (só leitura).
 *
 * @tags Carteira
 */
class WalletController extends Controller
{
    /**
     * Saldos: disponível, pendente (aulas pagas por confirmar), total ganho e comissões em dívida (Kz).
     */
    public function show(Request $request, WalletSummary $wallet): JsonResponse
    {
        return response()->json(['data' => $wallet->summary($this->provider($request))]);
    }

    /**
     * Movimentos da carteira (paginação por cursor).
     */
    public function movements(Request $request, WalletSummary $wallet): AnonymousResourceCollection
    {
        $perPage = min(50, max(1, (int) $request->integer('per_page', 20)));

        return WalletMovementResource::collection($wallet->movements($this->provider($request), $perPage));
    }

    private function provider(Request $request): ProviderProfile
    {
        /** @var User $user */
        $user = $request->user();

        return $user->providerProfile ?? throw new ApiException('forbidden', 'Só formadores têm carteira.', 403);
    }
}
