<?php

declare(strict_types=1);

namespace App\Modules\Verification\Models;

use App\Modules\Users\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pedido de verificação (BI, certificado, verificação presencial).
 *
 * O nº do BI é cifrado na base de dados (cast "encrypted") e escondido na serialização;
 * `document_hash` permite detetar o mesmo BI em contas diferentes sem o decifrar.
 *
 * @property int $id
 * @property int $user_id
 * @property string $type
 * @property string $status
 * @property string|null $document_number
 * @property string|null $document_hash
 * @property list<string>|null $documents
 * @property string|null $notes
 * @property int|null $reviewed_by
 * @property \Illuminate\Support\Carbon|null $reviewed_at
 * @property string|null $rejection_reason
 * @property \Illuminate\Support\Carbon $created_at
 * @property-read User $user
 */
#[Fillable(['user_id', 'type', 'documents', 'notes'])]
#[Hidden(['document_number', 'document_hash', 'documents'])]
class Verification extends Model
{
    public const TYPE_IDENTITY = 'identity_document';

    public const TYPE_SKILL_CERTIFICATE = 'skill_certificate';

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    protected $attributes = ['status' => self::STATUS_PENDING];

    protected function casts(): array
    {
        return [
            'document_number' => 'encrypted',
            'documents' => 'array',
            'reviewed_at' => 'datetime',
        ];
    }

    /**
     * Guarda o nº do BI cifrado e o respetivo hash para pesquisa de duplicados.
     */
    public function setDocumentNumber(string $number): void
    {
        $normalized = self::normalizeDocument($number);
        $this->document_number = $normalized;
        $this->document_hash = self::hashDocument($normalized);
    }

    public static function normalizeDocument(string $number): string
    {
        return strtoupper((string) preg_replace('/\s+/', '', $number));
    }

    public static function hashDocument(string $normalized): string
    {
        return hash_hmac('sha256', $normalized, (string) config('app.key'));
    }

    /**
     * BI mascarado para apresentação (ex.: •••••••••LA042).
     */
    public function maskedDocumentNumber(): ?string
    {
        if ($this->document_number === null) {
            return null;
        }

        return str_repeat('•', max(0, strlen($this->document_number) - 5)).substr($this->document_number, -5);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
