<?php

declare(strict_types=1);

namespace App\Modules\Users\Services;

use App\Modules\Users\Models\Consent;
use App\Modules\Users\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Regista consentimentos com a versão em vigor, data, IP e user agent. Termos e privacidade: versão do ficheiro
 * mais recente (LegalDocuments); os outros: config/longa.php → consents.
 * Não duplica um consentimento já dado para a mesma versão.
 */
class ConsentRecorder
{
    public const TERMS = 'terms';

    public const PRIVACY = 'privacy';

    public const LOCATION = 'location';

    public const COMPANY_REPORTS = 'company_reports';

    public function __construct(private readonly Request $request, private readonly LegalDocuments $legal) {}

    /**
     * @param  list<string>  $types
     */
    public function record(User $user, array $types): void
    {
        foreach ($types as $type) {
            $version = LegalDocuments::isDocument($type) ? $this->legal->currentVersion($type) : (string) config("longa.consents.{$type}");

            $exists = Consent::query()
                ->where('user_id', $user->id)
                ->where('type', $type)
                ->where('version', $version)
                ->whereNull('revoked_at')
                ->exists();

            if (! $exists) {
                $consent = new Consent([
                    'type' => $type,
                    'version' => $version,
                    'accepted_at' => now(),
                    'ip' => $this->request->ip(),
                    'user_agent' => Str::limit((string) $this->request->userAgent(), 500, ''),
                ]);
                $consent->user()->associate($user);
                $consent->save();
            }
        }
    }
}
