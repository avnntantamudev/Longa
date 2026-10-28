<?php

declare(strict_types=1);

namespace App\Modules\Companies\Actions;

use App\Modules\Companies\Models\CompanyEmployee;
use App\Modules\Users\Models\User;
use App\Modules\Users\Services\ConsentRecorder;
use App\Support\Api\ApiException;
use App\Support\Audit\AuditLogger;

/**
 * O colaborador aceita, recusa ou retira a autorização para a empresa acompanhar os seus serviços.
 * Só o próprio (mesmo telefone) decide; cada decisão fica no registo de consentimentos e na auditoria.
 */
class EmployeeConsent
{
    public const ACCEPTED = 'accepted';

    public const DECLINED = 'declined';

    public const REVOKED = 'revoked';

    public function __construct(private readonly AuditLogger $audit, private readonly ConsentRecorder $consents) {}

    public function decide(CompanyEmployee $employee, User $user, string $decision): CompanyEmployee
    {
        if ($employee->phone === null || $employee->phone !== $user->phone) {
            throw new ApiException('forbidden', 'Este pedido não é para si.', 403);
        }

        if ($decision === self::REVOKED && $employee->consent_status !== self::ACCEPTED) {
            throw new ApiException('consent_not_given', 'Ainda não autorizou esta empresa.', 409);
        }

        $employee->forceFill([
            'user_id' => $user->id,
            'consent_status' => $decision,
            'consent_at' => now(),
        ])->save();

        if ($decision === self::ACCEPTED) {
            $this->consents->record($user, [ConsentRecorder::COMPANY_REPORTS]);
        }
        $this->audit->record("companies.employee_consent_{$decision}", $employee, ['company_id' => $employee->company_id], $user->id);

        return $employee;
    }
}
