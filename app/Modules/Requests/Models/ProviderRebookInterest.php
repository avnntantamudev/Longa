<?php

declare(strict_types=1);

namespace App\Modules\Requests\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Um cliente quis voltar a chamar um técnico sem VIP (um registo por cliente e técnico).
 *
 * @property int $id
 * @property int $provider_profile_id
 * @property int $client_id
 * @property int|null $service_request_id
 * @property \Illuminate\Support\Carbon $created_at
 */
#[Fillable(['provider_profile_id', 'client_id', 'service_request_id'])]
class ProviderRebookInterest extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    /**
     * @return Builder<self>
     */
    public static function forProvider(int $providerProfileId): Builder
    {
        return self::query()->where('provider_profile_id', $providerProfileId);
    }
}
