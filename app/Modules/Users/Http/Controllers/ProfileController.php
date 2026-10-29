<?php

declare(strict_types=1);

namespace App\Modules\Users\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Users\Actions\CompleteOnboarding;
use App\Modules\Users\Actions\UpdateProviderProfile;
use App\Modules\Users\Http\Requests\ClientProfileRequest;
use App\Modules\Users\Http\Requests\OnboardingRequest;
use App\Modules\Users\Http\Requests\ProviderCategoriesRequest;
use App\Modules\Users\Http\Requests\ProviderDetailsRequest;
use App\Modules\Users\Http\Requests\ProviderLocationRequest;
use App\Modules\Users\Http\Requests\UpdateMeRequest;
use App\Modules\Users\Http\Resources\ProviderProfileResource;
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
     * Define o nome, os perfis (cliente, técnico, empresa) e regista a aceitação dos termos e da privacidade.
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
     * Atualizar o perfil de cliente.
     */
    public function updateClient(ClientProfileRequest $request): JsonResponse
    {
        $profile = $this->user($request)->clientProfile
            ?? throw new ApiException('profile_missing', 'Ainda não tem o perfil de cliente.', 409);

        $profile->fill($request->validated())->save();

        return response()->json(['data' => $profile->only(['municipality', 'neighborhood', 'education_level'])]);
    }

    /**
     * Ver o próprio perfil de técnico.
     */
    public function showProvider(Request $request): ProviderProfileResource
    {
        $profile = $this->user($request)->providerProfile
            ?? throw new ApiException('profile_missing', 'Ainda não tem o perfil de técnico.', 409);

        return new ProviderProfileResource($profile->load('categories'));
    }

    /**
     * Técnico: apresentação, experiência, modalidades e preço.
     */
    public function providerDetails(ProviderDetailsRequest $request, UpdateProviderProfile $action): ProviderProfileResource
    {
        /** @var array{bio?: string|null, years_experience?: int|null, modalities?: list<string>, rate_amount?: int|null, rate_unit?: string|null} $data */
        $data = $request->validated();

        return new ProviderProfileResource($action->details($this->user($request), $data));
    }

    /**
     * Técnico: áreas de serviço.
     */
    public function providerCategories(ProviderCategoriesRequest $request, UpdateProviderProfile $action): ProviderProfileResource
    {
        return new ProviderProfileResource($action->categories($this->user($request), $request->categoryIds(), $request->hourlyRates()));
    }

    /**
     * Técnico: local e raio de atuação.
     */
    public function providerLocation(ProviderLocationRequest $request, UpdateProviderProfile $action): ProviderProfileResource
    {
        return new ProviderProfileResource($action->location($this->user($request), $request->locationData()));
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
