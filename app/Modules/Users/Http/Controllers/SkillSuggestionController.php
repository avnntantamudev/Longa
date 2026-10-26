<?php

declare(strict_types=1);

namespace App\Modules\Users\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Users\Actions\SkillSuggestions;
use App\Modules\Users\Models\SkillSuggestion;
use App\Modules\Users\Models\TrainerProfile;
use App\Modules\Users\Models\User;
use App\Support\Api\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Áreas sugeridas pelo formador ("Outra área").
 *
 * @tags Perfil
 */
class SkillSuggestionController extends Controller
{
    /**
     * As minhas áreas sugeridas e o estado de cada uma.
     */
    public function index(Request $request): JsonResponse
    {
        $items = SkillSuggestion::query()->where('trainer_profile_id', $this->trainer($request)->id)->with('skill')->latest()->get();

        return response()->json(['data' => $items->map(fn (SkillSuggestion $s) => $this->present($s))]);
    }

    /**
     * Sugerir uma área que não está na lista.
     *
     * Fica em análise até a LONGA decidir. Se já existir uma área com esse nome, é ligada logo ao perfil.
     * Erros: `too_many_suggestions` (409).
     */
    public function store(Request $request, SkillSuggestions $suggestions): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'min:3', 'max:80']], [
            'name.min' => 'Escreva o nome da área (pelo menos 3 caracteres).',
        ]);

        $suggestion = $suggestions->suggest($this->trainer($request), $data['name']);

        return response()->json(['data' => $this->present($suggestion->load('skill'))], $suggestion->wasRecentlyCreated ? 201 : 200);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(SkillSuggestion $s): array
    {
        return [
            'id' => $s->id,
            'name' => $s->name,
            'status' => $s->status,
            'skill' => $s->skill === null ? null : ['id' => $s->skill->id, 'name' => $s->skill->name],
            'review_note' => $s->review_note,
            'created_at' => $s->created_at->toIso8601String(),
        ];
    }

    private function trainer(Request $request): TrainerProfile
    {
        /** @var User $user */
        $user = $request->user();

        return $user->trainerProfile ?? throw new ApiException('profile_missing', 'Ainda não tem o perfil de formador.', 409);
    }
}
