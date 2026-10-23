<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Lançamento (imutável): valor em Kz com sinal (+ débito, − crédito) numa conta.
 *
 * @property int $id
 * @property int $ledger_transaction_id
 * @property int $ledger_account_id
 * @property int $amount
 * @property \Illuminate\Support\Carbon $created_at
 * @property-read LedgerAccount $account
 * @property-read LedgerTransaction $transaction
 */
class LedgerEntry extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Lançamentos do ledger são imutáveis.'));
        static::deleting(fn () => throw new LogicException('Lançamentos do ledger são imutáveis.'));
    }

    /**
     * @return BelongsTo<LedgerAccount, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'ledger_account_id');
    }

    /**
     * @return BelongsTo<LedgerTransaction, $this>
     */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(LedgerTransaction::class, 'ledger_transaction_id');
    }
}
