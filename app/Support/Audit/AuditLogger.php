<?php

declare(strict_types=1);

namespace App\Support\Audit;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Regista ações sensíveis na tabela audit_logs e no canal de log "audit".
 * Nunca guardar segredos (códigos OTP, tokens, palavras-passe, nº de BI) em $metadata.
 */
class AuditLogger
{
    public function __construct(private readonly Request $request) {}

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function record(string $action, ?Model $subject = null, array $metadata = [], ?int $userId = null): AuditLog
    {
        $userId ??= $this->request->user()?->getAuthIdentifier();

        $log = AuditLog::query()->create([
            'user_id' => $userId,
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'ip' => $this->request->ip(),
            'user_agent' => Str::limit((string) $this->request->userAgent(), 500, ''),
            'request_id' => $this->request->attributes->get('request_id'),
            'metadata' => $metadata === [] ? null : $metadata,
        ]);

        Log::channel('audit')->info($action, [
            'audit_id' => $log->id,
            'user_id' => $userId,
            'subject' => $subject ? $subject->getMorphClass().':'.$subject->getKey() : null,
            'metadata' => $metadata,
        ]);

        return $log;
    }
}
