<?php

declare(strict_types=1);

namespace App\Modules\Users\Models;

use App\Modules\Users\Enums\VerificationStatus;
use App\Modules\Verification\Models\VerificationDocument;
use Database\Factories\ProviderProfileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $user_id
 * @property string|null $bio
 * @property int|null $years_experience
 * @property list<string>|null $modalities
 * @property int|null $rate_amount
 * @property string|null $rate_unit
 * @property string $province
 * @property string|null $municipality
 * @property string|null $neighborhood
 * @property float|null $latitude
 * @property float|null $longitude
 * @property int $service_radius_m
 * @property VerificationStatus $verification_status
 * @property int $score
 * @property list<string>|null $badges
 * @property bool $is_available
 * @property \Illuminate\Support\Carbon|null $onboarding_completed_at
 * @property float|null $last_latitude
 * @property float|null $last_longitude
 * @property \Illuminate\Support\Carbon|null $last_location_at
 * @property float $rating_average
 * @property int $rating_count
 * @property-read User $user
 */
#[Fillable([
    'bio', 'years_experience', 'modalities', 'rate_amount', 'rate_unit', 'province', 'municipality', 'neighborhood',
    'latitude', 'longitude', 'service_radius_m', 'is_available', 'onboarding_completed_at',
])]
// A coluna espacial gerada não é útil fora da base de dados
#[Hidden(['location'])]
#[UseFactory(ProviderProfileFactory::class)]
class ProviderProfile extends Model
{
    /** @use HasFactory<ProviderProfileFactory> */
    use HasFactory;

    protected $attributes = [
        'service_radius_m' => 5000,
        'verification_status' => 'unverified',
        'score' => 50,
        'is_available' => false,
    ];

    protected function casts(): array
    {
        return [
            'modalities' => 'array',
            'badges' => 'array',
            'latitude' => 'float',
            'longitude' => 'float',
            'is_available' => 'boolean',
            'verification_status' => VerificationStatus::class,
            'onboarding_completed_at' => 'datetime',
            'last_latitude' => 'float',
            'last_longitude' => 'float',
            'last_location_at' => 'datetime',
            'rating_average' => 'float',
            'rating_count' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsToMany<Category, $this>
     */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class);
    }

    /**
     * @return HasMany<ProviderZone, $this>
     */
    public function zones(): HasMany
    {
        return $this->hasMany(ProviderZone::class);
    }

    /**
     * @return HasMany<VerificationDocument, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(VerificationDocument::class);
    }

    /** Guarda a última posição conhecida do técnico (enviada pela app enquanto está online). */
    public function recordLocation(float $latitude, float $longitude): void
    {
        $this->forceFill(['last_latitude' => round($latitude, 7), 'last_longitude' => round($longitude, 7), 'last_location_at' => now()])->save();
    }

    public function isVerified(): bool
    {
        return $this->verification_status === VerificationStatus::Verified;
    }

    public function hasLocation(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }
}
