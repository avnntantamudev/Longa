<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Conta do livro-razão. Ver docs/ledger.md para o significado de cada tipo e do sinal do saldo.
 *
 * @property int $id
 * @property string $code
 * @property string $type
 * @property string|null $owner_type
 * @property int|null $owner_id
 * @property string $currency
 * @property-read LedgerAccountBalance|null $balance
 */
#[Fillable(['code', 'type', 'owner_type', 'owner_id', 'currency'])]
class LedgerAccount extends Model
{
    public const UPDATED_AT = null;

    /**
     * @return HasOne<LedgerAccountBalance, $this>
     */
    public function balance(): HasOne
    {
        return $this->hasOne(LedgerAccountBalance::class);
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
    public function owner(): MorphTo
    {
        return $this->morphTo();
    }
}
