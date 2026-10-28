<?php

declare(strict_types=1);

namespace App\Modules\Users\Actions;

use App\Modules\Users\Enums\Role;
use App\Modules\Users\Models\User;
use App\Modules\Users\Services\ConsentRecorder;
use App\Support\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Primeiro passo do registo: nome, perfis escolhidos (cliente, técnico, empresa) e aceitação
 * dos termos e da política de privacidade. Pode ser repetido para acrescentar perfis.
 */
class CompleteOnboarding
{
    public function __construct(private readonly ConsentRecorder $consents, private readonly AuditLogger $audit) {}

    /**
     * @param  list<Role>  $roles
     */
    public function handle(User $user, string $name, array $roles): User
    {
        return DB::transaction(function () use ($user, $name, $roles) {
            $user->forceFill(['name' => $name, 'onboarded_at' => $user->onboarded_at ?? now()])->save();

            foreach ($roles as $role) {
                $user->assignRole($role->value);

                match ($role) {
                    Role::Client => $user->clientProfile()->firstOrCreate([]),
                    Role::Provider => $user->providerProfile()->firstOrCreate([]),
                    // A empresa é criada no passo seguinte (dados da empresa)
                    default => null,
                };
            }

            $this->consents->record($user, [ConsentRecorder::TERMS, ConsentRecorder::PRIVACY]);
            $this->audit->record('users.onboarded', $user, ['roles' => array_map(fn (Role $r) => $r->value, $roles)], $user->id);

            return $user->refresh();
        });
    }
}
