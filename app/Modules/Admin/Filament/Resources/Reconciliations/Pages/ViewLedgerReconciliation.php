<?php

declare(strict_types=1);

namespace App\Modules\Admin\Filament\Resources\Reconciliations\Pages;

use App\Modules\Admin\Filament\Resources\Reconciliations\LedgerReconciliationResource;
use Filament\Resources\Pages\ViewRecord;

class ViewLedgerReconciliation extends ViewRecord
{
    protected static string $resource = LedgerReconciliationResource::class;
}
