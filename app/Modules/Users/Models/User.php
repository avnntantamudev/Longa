<?php

declare(strict_types=1);

namespace App\Modules\Users\Models;

use App\Modules\Users\Enums\Role;
use App\Modules\Users\Enums\UserStatus;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasAvatar;
use Filament\Models\Contracts\HasName;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\Contracts\HasAbilities;
use Laravel\Sanctum\HasApiTokens;
use NotificationChannels\WebPush\HasPushSubscriptions;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property int $id
 * @property string $phone
 * @property string|null $name
 * @property string|null $email
 * @property string|null $photo
 * @property string|null $pin_hash
 * @property \Illuminate\Support\Carbon|null $pin_set_at
 * @property \Illuminate\Support\Carbon|null $photo_updated_at
 * @property UserStatus $status
 * @property string $locale
 * @property \Illuminate\Support\Carbon|null $phone_verified_at
 * @property \Illuminate\Support\Carbon|null $last_login_at
 * @property \Illuminate\Support\Carbon|null $onboarded_at
 */
#[Fillable(['phone', 'name', 'email', 'locale', 'phone_verified_at', 'last_login_at', 'onboarded_at'])]
#[Hidden(['remember_token', 'pin_hash'])]
#[UseFactory(UserFactory::class)]
class User extends Authenticatable implements FilamentUser, HasAvatar, HasName
{
    /** @use HasApiTokens<HasAbilities> Token pessoal (API) ou TransientToken (sessão da PWA) */
    use HasApiTokens;

    /** @use HasFactory<UserFactory> */
    use HasFactory, HasPushSubscriptions, HasRoles, Notifiable, SoftDeletes;

    protected string $guard_name = 'web';

    protected $attributes = [
        'status' => 'active',
        'locale' => 'pt',
    ];

    protected function casts(): array
    {
        return [
            'status' => UserStatus::class,
            'phone_verified_at' => 'datetime',
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'onboarded_at' => 'datetime',
            'photo_updated_at' => 'datetime',
        ];
    }

    /**
     * @return HasOne<ClientProfile, $this>
     */
    public function clientProfile(): HasOne
    {
        return $this->hasOne(ClientProfile::class);
    }

    /**
     * @return HasOne<ProviderProfile, $this>
     */
    public function providerProfile(): HasOne
    {
        return $this->hasOne(ProviderProfile::class);
    }

    public function isSuspended(): bool
    {
        return $this->status === UserStatus::Suspended;
    }

    public function hasProfile(Role $role): bool
    {
        return $this->hasRole($role->value);
    }

    /**
     * Encaminha notificações SMS para o telefone do utilizador.
     */
    public function routeNotificationForSms(): string
    {
        return $this->phone;
    }

    /**
     * Backoffice (Filament): só administradores ativos.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->status === UserStatus::Active && $this->hasRole(Role::Admin->value);
    }

    public function getFilamentName(): string
    {
        return $this->name ?? $this->phone;
    }

    /**
     * URL relativo da foto (512 px ou 128 px), ou null se não tiver foto.
     */
    public function photoUrl(int $size = 512): ?string
    {
        return $this->photo === null ? null : "/fotos/{$this->photo}-{$size}.webp";
    }

    /**
     * @return array{photo_url: string|null, photo_thumb_url: string|null}
     */
    public function photoUrls(): array
    {
        return ['photo_url' => $this->photoUrl(512), 'photo_thumb_url' => $this->photoUrl(128)];
    }

    public function getFilamentAvatarUrl(): ?string
    {
        return $this->photoUrl(128);
    }
}
