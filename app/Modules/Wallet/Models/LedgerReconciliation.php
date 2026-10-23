<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Resultado de uma verificação do ledger (longa:ledger:reconcile, de hora a hora).
 *
 * @property int $id
 * @property string $status
 * @property list<array{check: string, severity: string, message: string, data: array<string, mixed>}> $issues
 * @property array<string, int> $totals
 * @property int $duration_ms
 * @property \Illuminate\Support\Carbon $checked_at
 */
class LedgerReconciliation extends Model
{
    public const OK = 'ok';

    public const DISCREPANCY = 'discrepancy';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['issues' => 'array', 'totals' => 'array', 'checked_at' => 'datetime'];
    }

    public function isOk(): bool
    {
        return $this->status === self::OK;
    }
}
