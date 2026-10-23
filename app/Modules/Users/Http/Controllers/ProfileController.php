<?php

declare(strict_types=1);

namespace App\Modules\Users\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Users\Actions\CompleteOnboarding;
use App\Modules\Users\Actions\UpdateTrainerProfile;
use App\Modules\Users\Http\Requests\OnboardingRequest;
use App\Modules\Users\Http\Requests\StudentProfileRequest;
use App\Modules\Users\Http\Requests\TrainerDetailsRequest;
use App\Modules\Users\Http\Requests\TrainerLocationRequest;
use App\Modules\Users\Http\Requests\TrainerSkillsRequest;
use App\Modules\Users\Http\Requests\UpdateMeRequest;
use App\Modules\Users\Http\Resources\TrainerProfileResource;
use App\Modules\Users\Http\Resources\UserResource;
use App\Modules\Users\Models\User;
use App\Support\Api\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Registo (escolha de perfis) e edição dos perfis do próprio utilizador.
 *
 * @tags Perfis
 */
class ProfileController extends Controller
{
    /**
     * Concluir o registo.
     *
     * Define o nome, os perfis (aluno, formador, empresa) e regista a aceitação dos termos e da privacidade.
     */
    public function onboarding(OnboardingRequest $request, CompleteOnboarding $action): UserResource
    {
        return new UserResource($action->handle($this->user($request), (string) $request->validated('name'), $request->roles()));
    }

    /**
     * Atualizar nome e e-mail.
     */
    public function updateMe(UpdateMeRequest $request): UserResource
    {
        $user = $this->user($request);
        $user->fill($request->validated())->save();

        return new UserResource($user);
    }

    /**
     * Atualizar o perfil de aluno.
     */
    public function updateStudent(StudentProfileRequest $request): JsonResponse
    {
        $profile = $this->user($request)->studentProfile
            ?? throw new ApiException('profile_missing', 'Ainda não tem o perfil de aluno.', 409);

        $profile->fill($request->validated())->save();

        return response()->json(['data' => $profile->only(['municipality', 'neighborhood', 'education_level'])]);
    }

    /**
     * Ver o próprio perfil de formador.
     */
    public function showTrainer(Request $request): TrainerProfileResource
    {
        $profile = $this->user($request)->trainerProfile
            ?? throw new ApiException('profile_missing', 'Ainda não tem o perfil de formador.', 409);

        return new TrainerProfileResource($profile->load('skills'));
    }

    /**
     * Formador: apresentação, experiência, modalidades e preço.
     */
    public function trainerDetails(TrainerDetailsRequest $request, UpdateTrainerProfile $action): TrainerProfileResource
    {
        /** @var array{bio?: string|null, years_experience?: int|null, modalities?: list<string>, rate_amount?: int|null, rate_unit?: string|null} $data */
        $data = $request->validated();

        return new TrainerProfileResource($action->details($this->user($request), $data));
    }

    /**
     * Formador: áreas de formação.
     */
    public function trainerSkills(TrainerSkillsRequest $request, UpdateTrainerProfile $action): TrainerProfileResource
    {
        return new TrainerProfileResource($action->skills($this->user($request), $request->skillIds()));
    }

    /**
     * Formador: local e raio de atuação.
     */
    public function trainerLocation(TrainerLocationRequest $request, UpdateTrainerProfile $action): TrainerProfileResource
    {
        return new TrainerProfileResource($action->location($this->user($request), $request->locationData()));
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
