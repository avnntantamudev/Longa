<?php

declare(strict_types=1);

namespace App\Modules\Admin\Filament\Resources\Ledger\Pages;

use App\Modules\Admin\Filament\Resources\Ledger\LedgerTransactionResource;
use Filament\Resources\Pages\ViewRecord;

class ViewLedgerTransaction extends ViewRecord
{
    protected static string $resource = LedgerTransactionResource::class;
}
