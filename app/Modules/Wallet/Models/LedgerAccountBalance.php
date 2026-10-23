<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cache do saldo de uma conta (derivado dos lançamentos; reconstruível com longa:ledger:rebuild).
 * Só o LedgerService a atualiza, dentro da transação que cria os lançamentos.
 *
 * @property int $ledger_account_id
 * @property int $balance
 * @property int|null $last_entry_id
 */
class LedgerAccountBalance extends Model
{
    public const CREATED_AT = null;

    protected $primaryKey = 'ledger_account_id';

    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['balance' => 'integer'];
    }

    /**
     * @return BelongsTo<LedgerAccount, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'ledger_account_id');
    }
}
