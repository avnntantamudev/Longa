<?php

declare(strict_types=1);

namespace App\Modules\Users\Models;

use App\Modules\Users\Enums\Role;
use App\Modules\Users\Enums\UserStatus;
use Database\Factories\UserFactory;
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
 * @property UserStatus $status
 * @property string $locale
 * @property \Illuminate\Support\Carbon|null $phone_verified_at
 * @property \Illuminate\Support\Carbon|null $last_login_at
 * @property \Illuminate\Support\Carbon|null $onboarded_at
 */
#[Fillable(['phone', 'name', 'email', 'locale', 'phone_verified_at', 'last_login_at', 'onboarded_at'])]
#[Hidden(['remember_token'])]
#[UseFactory(UserFactory::class)]
class User extends Authenticatable
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
        ];
    }

    /**
     * @return HasOne<StudentProfile, $this>
     */
    public function studentProfile(): HasOne
    {
        return $this->hasOne(StudentProfile::class);
    }

    /**
     * @return HasOne<TrainerProfile, $this>
     */
    public function trainerProfile(): HasOne
    {
        return $this->hasOne(TrainerProfile::class);
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
}
