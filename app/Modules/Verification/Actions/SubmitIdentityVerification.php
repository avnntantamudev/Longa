<?php

declare(strict_types=1);

namespace App\Modules\Verification\Actions;

use App\Modules\Users\Enums\VerificationStatus;
use App\Modules\Users\Models\User;
use App\Modules\Verification\Models\Verification;
use App\Support\Api\ApiException;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * O formador submete o BI (nº + fotografias/PDF). Os ficheiros vão para o disco privado
 * e o nº do BI é cifrado. O mesmo BI não pode ser usado por duas contas.
 */
class SubmitIdentityVerification
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  list<UploadedFile>  $files
     */
    public function handle(User $user, string $documentNumber, array $files): Verification
    {
        $profile = $user->providerProfile
            ?? throw new ApiException('profile_missing', 'Só formadores podem submeter verificação.', 409);

        if ($user->photo === null) {
            throw new ApiException('photo_required', 'Carregue a sua foto antes de pedir a verificação: é comparada com o BI.', 409);
        }

        $hash = Verification::hashDocument(Verification::normalizeDocument($documentNumber));

        $usedElsewhere = Verification::query()
            ->where('document_hash', $hash)
            ->where('user_id', '!=', $user->id)
            ->where('status', '!=', Verification::STATUS_REJECTED)
            ->exists();

        if ($usedElsewhere) {
            $this->audit->record('verification.duplicate_document', null, [], $user->id);

            throw new ApiException('document_already_used', 'Este BI já está associado a outra conta. Contacte o suporte.', 409);
        }

        if (Verification::query()->where('user_id', $user->id)->where('type', Verification::TYPE_IDENTITY)->where('status', Verification::STATUS_PENDING)->exists()) {
            throw new ApiException('verification_pending', 'Já tem uma verificação em análise.', 409);
        }

        $paths = array_map(
            fn (UploadedFile $file) => $file->store("verifications/{$user->id}", 'local'),
            $files,
        );

        return DB::transaction(function () use ($user, $profile, $documentNumber, $paths) {
            $verification = new Verification(['user_id' => $user->id, 'type' => Verification::TYPE_IDENTITY, 'documents' => array_values(array_filter($paths))]);
            $verification->setDocumentNumber($documentNumber);
            $verification->save();

            $profile->verification_status = VerificationStatus::Pending;
            $profile->save();

            $this->audit->record('verification.submitted', $verification, [], $user->id);

            return $verification;
        });
    }
}
