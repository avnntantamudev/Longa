<?php

declare(strict_types=1);

namespace App\Modules\Users\Actions;

use App\Modules\Users\Models\ProviderProfile;
use App\Modules\Users\Models\User;
use App\Modules\Users\Services\ConsentRecorder;
use App\Support\Api\ApiException;
use Illuminate\Support\Facades\DB;

/**
 * Onboarding e edição do perfil do técnico, por secções:
 *  - details: bio, experiência, modalidades, preço de referência;
 *  - categories: áreas de serviço;
 *  - location: município/bairro, coordenadas e raio de atuação (exige consentimento de localização).
 *
 * O onboarding fica concluído quando há bio, pelo menos uma área e local definidos.
 */
class UpdateProviderProfile
{
    public function __construct(private readonly ConsentRecorder $consents) {}

    /**
     * @param  array{bio?: string|null, years_experience?: int|null, modalities?: list<string>, rate_amount?: int|null, rate_unit?: string|null}  $data
     */
    public function details(User $user, array $data): ProviderProfile
    {
        $profile = $this->profileOf($user);
        $profile->fill($data)->save();

        return $this->refreshCompletion($profile);
    }

    /**
     * @param  list<int>  $categoryIds
     * @param  array<int, int>  $hourlyRates  preço por hora por categoria (categorias por hora/pacote)
     */
    public function categories(User $user, array $categoryIds, array $hourlyRates = []): ProviderProfile
    {
        $profile = $this->profileOf($user);
        $profile->categories()->sync(array_combine($categoryIds, array_map(
            fn (int $id) => ['hourly_rate' => $hourlyRates[$id] ?? null],
            $categoryIds,
        )));

        return $this->refreshCompletion($profile);
    }

    /**
     * @param  array{province: string, municipality: string, neighborhood: string, latitude: float, longitude: float, service_radius_m: int}  $data
     */
    public function location(User $user, array $data): ProviderProfile
    {
        return DB::transaction(function () use ($user, $data) {
            $profile = $this->profileOf($user);
            $profile->fill($data)->save();
            $this->consents->record($user, [ConsentRecorder::LOCATION]);

            return $this->refreshCompletion($profile);
        });
    }

    private function profileOf(User $user): ProviderProfile
    {
        return $user->providerProfile
            ?? throw new ApiException('profile_missing', 'Ainda não tem o perfil de técnico. Escolha-o primeiro no registo.', 409);
    }

    public function refreshCompletion(ProviderProfile $profile): ProviderProfile
    {
        $complete = filled($profile->bio) && $profile->hasLocation() && $profile->categories()->exists() && $profile->user->photo !== null;

        if ($complete && $profile->onboarding_completed_at === null) {
            $profile->onboarding_completed_at = now();
            $profile->save();
        }

        return $profile->load('categories');
    }
}
