<?php

declare(strict_types=1);

namespace App\Modules\Users\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Zona onde o técnico trabalha, além da base do perfil: um ponto com raio, ou um município/província inteiros.
 *
 * @property int $id
 * @property int $provider_profile_id
 * @property string|null $label
 * @property string $province
 * @property string|null $municipality
 * @property float|null $latitude
 * @property float|null $longitude
 * @property int|null $radius_m
 */
#[Fillable(['provider_profile_id', 'label', 'province', 'municipality', 'latitude', 'longitude', 'radius_m'])]
class ProviderZone extends Model
{
    protected function casts(): array
    {
        return ['latitude' => 'float', 'longitude' => 'float', 'radius_m' => 'integer'];
    }

    /**
     * @return BelongsTo<ProviderProfile, $this>
     */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(ProviderProfile::class, 'provider_profile_id');
    }
}
