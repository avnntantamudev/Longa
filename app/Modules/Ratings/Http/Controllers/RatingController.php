<?php

declare(strict_types=1);

namespace App\Modules\Ratings\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Ratings\Models\Rating;
use App\Modules\Ratings\Services\RatingService;
use App\Modules\Requests\Models\ServiceRequest;
use App\Modules\Users\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Avaliações: o cliente avalia o técnico e o técnico avalia o cliente, depois de o pedido estar pago.
 */
class RatingController extends Controller
{
    /**
     * Avaliar o pedido (1 a 5 estrelas e comentário). Uma avaliação por pessoa; repetir devolve a mesma.
     */
    public function store(Request $request, ServiceRequest $session, RatingService $ratings): JsonResponse
    {
        Gate::authorize('view', $session);
        $data = $request->validate([
            'stars' => ['required', 'integer', 'min:1', 'max:5'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $rating = $ratings->rate($session, $user, (int) $data['stars'], $data['comment'] ?? null);

        return response()->json(['data' => self::present($rating)], $rating->wasRecentlyCreated ? 201 : 200);
    }

    /**
     * Avaliações do pedido (participantes).
     */
    public function index(ServiceRequest $session): JsonResponse
    {
        Gate::authorize('view', $session);

        return response()->json(['data' => $session->ratings()->get()->map(fn (Rating $r) => self::present($r))->all()]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function present(Rating $rating): array
    {
        return [
            'id' => $rating->id,
            /** client_to_provider | provider_to_client */
            'direction' => $rating->direction,
            'stars' => $rating->stars,
            'comment' => $rating->comment,
            'created_at' => $rating->created_at->toIso8601String(),
        ];
    }
}
