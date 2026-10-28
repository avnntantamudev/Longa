<?php

declare(strict_types=1);

namespace App\Modules\Users\Console;

use App\Modules\Auth\Support\PhoneNumber;
use App\Modules\Users\Enums\Role;
use App\Modules\Users\Models\User;
use App\Support\Audit\AuditLogger;
use Illuminate\Console\Command;

/**
 * Dá ou tira um papel da equipa (admin ou operador) a um utilizador já registado, pelo telefone.
 * Fica no registo de auditoria. Ex.: php artisan longa:team 923456789 operator
 */
class TeamMemberCommand extends Command
{
    protected $signature = 'longa:team {phone : Telefone do utilizador} {role=operator : admin ou operator} {--remove : Tirar o papel}';

    protected $description = 'Dar ou tirar o papel de admin/operador da equipa';

    public function handle(AuditLogger $audit): int
    {
        $role = Role::tryFrom((string) $this->argument('role'));
        if (! in_array($role, Role::team(), true)) {
            $this->error('Papel inválido: use admin ou operator.');

            return self::INVALID;
        }

        $phone = PhoneNumber::normalize((string) $this->argument('phone'));
        $user = $phone === null ? null : User::query()->where('phone', $phone)->first();
        if ($user === null) {
            $this->error('Não existe nenhum utilizador com esse telefone (tem de se registar primeiro na app).');

            return self::FAILURE;
        }

        if ($this->option('remove')) {
            $user->removeRole($role->value);
        } else {
            $user->assignRole($role->value);
        }
        $audit->record($this->option('remove') ? 'team.role_removed' : 'team.role_assigned', $user, ['role' => $role->value]);

        $this->info(sprintf('%s: %s %s.', $user->name ?? $phone, $this->option('remove') ? 'deixou de ser' : 'passou a ser', mb_strtolower($role->label())));

        return self::SUCCESS;
    }
}
