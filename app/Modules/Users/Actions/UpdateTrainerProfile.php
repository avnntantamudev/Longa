<?php

declare(strict_types=1);

namespace App\Modules\Users\Actions;

use App\Modules\Users\Models\TrainerProfile;
use App\Modules\Users\Models\User;
use App\Modules\Users\Services\ConsentRecorder;
use App\Support\Api\ApiException;
use Illuminate\Support\Facades\DB;

/**
 * Onboarding e edição do perfil do formador, por secções:
 *  - details: bio, experiência, modalidades, preço de referência;
 *  - skills: áreas de formação;
 *  - location: município/bairro, coordenadas e raio de atuação (exige consentimento de localização).
 *
 * O onboarding fica concluído quando há bio, pelo menos uma área e local definidos.
 */
class UpdateTrainerProfile
{
    public function __construct(private readonly ConsentRecorder $consents) {}

    /**
     * @param  array{bio?: string|null, years_experience?: int|null, modalities?: list<string>, rate_amount?: int|null, rate_unit?: string|null}  $data
     */
    public function details(User $user, array $data): TrainerProfile
    {
        $profile = $this->profileOf($user);
        $profile->fill($data)->save();

        return $this->refreshCompletion($profile);
    }

    /**
     * @param  list<int>  $skillIds
     */
    public function skills(User $user, array $skillIds): TrainerProfile
    {
        $profile = $this->profileOf($user);
        $profile->skills()->sync($skillIds);

        return $this->refreshCompletion($profile);
    }

    /**
     * @param  array{municipality: string, neighborhood: string, latitude: float, longitude: float, service_radius_m: int}  $data
     */
    public function location(User $user, array $data): TrainerProfile
    {
        return DB::transaction(function () use ($user, $data) {
            $profile = $this->profileOf($user);
            $profile->fill($data)->save();
            $this->consents->record($user, [ConsentRecorder::LOCATION]);

            return $this->refreshCompletion($profile);
        });
    }

    private function profileOf(User $user): TrainerProfile
    {
        return $user->trainerProfile
            ?? throw new ApiException('profile_missing', 'Ainda não tem o perfil de formador. Escolha-o primeiro no registo.', 409);
    }

    public function refreshCompletion(TrainerProfile $profile): TrainerProfile
    {
        $complete = filled($profile->bio) && $profile->hasLocation() && $profile->skills()->exists() && $profile->user->photo !== null;

        if ($complete && $profile->onboarding_completed_at === null) {
            $profile->onboarding_completed_at = now();
            $profile->save();
        }

        return $profile->load('skills');
    }
}
