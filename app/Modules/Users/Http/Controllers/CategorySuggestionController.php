<?php

declare(strict_types=1);

namespace App\Modules\Users\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Users\Actions\CategorySuggestions;
use App\Modules\Users\Models\CategorySuggestion;
use App\Modules\Users\Models\ProviderProfile;
use App\Modules\Users\Models\User;
use App\Support\Api\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Áreas sugeridas pelo formador ("Outra área").
 *
 * @tags Perfil
 */
class CategorySuggestionController extends Controller
{
    /**
     * As minhas áreas sugeridas e o estado de cada uma.
     */
    public function index(Request $request): JsonResponse
    {
        $items = CategorySuggestion::query()->where('provider_profile_id', $this->provider($request)->id)->with('category')->latest()->get();

        return response()->json(['data' => $items->map(fn (CategorySuggestion $s) => $this->present($s))]);
    }

    /**
     * Sugerir uma área que não está na lista.
     *
     * Fica em análise até o BISCA decidir. Se já existir uma área com esse nome, é ligada logo ao perfil.
     * Erros: `too_many_suggestions` (409).
     */
    public function store(Request $request, CategorySuggestions $suggestions): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'min:3', 'max:80']], [
            'name.min' => 'Escreva o nome da área (pelo menos 3 caracteres).',
        ]);

        $suggestion = $suggestions->suggest($this->provider($request), $data['name']);

        return response()->json(['data' => $this->present($suggestion->load('category'))], $suggestion->wasRecentlyCreated ? 201 : 200);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(CategorySuggestion $s): array
    {
        return [
            'id' => $s->id,
            'name' => $s->name,
            'status' => $s->status,
            'category' => $s->category === null ? null : ['id' => $s->category->id, 'name' => $s->category->name],
            'review_note' => $s->review_note,
            'created_at' => $s->created_at->toIso8601String(),
        ];
    }

    private function provider(Request $request): ProviderProfile
    {
        /** @var User $user */
        $user = $request->user();

        return $user->providerProfile ?? throw new ApiException('profile_missing', 'Ainda não tem o perfil de formador.', 409);
    }
}
