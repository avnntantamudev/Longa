<?php

declare(strict_types=1);

namespace App\Modules\Verification\Actions;

use App\Modules\Users\Enums\VerificationStatus;
use App\Modules\Users\Models\User;
use App\Modules\Verification\Models\Verification;
use App\Support\Api\ApiException;
use App\Support\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Admin aprova ou rejeita uma verificação pendente; o estado do formador acompanha a decisão.
 */
class ReviewVerification
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function approve(Verification $verification, User $admin): Verification
    {
        return $this->decide($verification, $admin, Verification::STATUS_APPROVED, null);
    }

    public function reject(Verification $verification, User $admin, string $reason): Verification
    {
        return $this->decide($verification, $admin, Verification::STATUS_REJECTED, $reason);
    }

    private function decide(Verification $verification, User $admin, string $status, ?string $reason): Verification
    {
        return DB::transaction(function () use ($verification, $admin, $status, $reason) {
            /** @var Verification $locked */
            $locked = Verification::query()->lockForUpdate()->findOrFail($verification->id);

            if ($locked->status !== Verification::STATUS_PENDING) {
                throw new ApiException('verification_already_reviewed', 'Esta verificação já foi analisada.', 409);
            }

            $locked->forceFill([
                'status' => $status,
                'reviewed_by' => $admin->id,
                'reviewed_at' => now(),
                'rejection_reason' => $reason,
            ])->save();

            $profile = $locked->user->providerProfile;
            if ($profile !== null && $locked->type === Verification::TYPE_IDENTITY) {
                $profile->verification_status = $status === Verification::STATUS_APPROVED ? VerificationStatus::Verified : VerificationStatus::Rejected;
                $profile->save();
            }

            $this->audit->record("verification.{$status}", $locked, array_filter(['reason' => $reason]), $admin->id);

            return $locked;
        });
    }
}
