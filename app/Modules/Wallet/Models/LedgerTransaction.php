<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use LogicException;

/**
 * Transação do livro-razão (imutável). Criada só pelo LedgerService.
 *
 * @property int $id
 * @property string $type
 * @property string $idempotency_key
 * @property string $status
 * @property string|null $reference_type
 * @property int|null $reference_id
 * @property int|null $reverses_transaction_id
 * @property int $total_debit
 * @property int $total_credit
 * @property array<string, mixed>|null $metadata
 * @property int|null $created_by
 * @property \Illuminate\Support\Carbon $created_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, LedgerEntry> $entries
 */
class LedgerTransaction extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['metadata' => 'array', 'total_debit' => 'integer', 'total_credit' => 'integer'];
    }

    protected static function booted(): void
    {
        // Defesa na aplicação, além dos triggers da base de dados
        static::updating(fn () => throw new LogicException('Transações do ledger são imutáveis: crie um estorno.'));
        static::deleting(fn () => throw new LogicException('Transações do ledger são imutáveis: crie um estorno.'));
    }

    /**
     * @return HasMany<LedgerEntry, $this>
     */
    public function entries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function reference(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<self, $this>
     */
    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_transaction_id');
    }
}
