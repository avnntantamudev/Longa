<?php

declare(strict_types=1);

namespace App\Modules\Admin\Filament\Resources\Commissions\Pages;

use App\Modules\Admin\Filament\Resources\Commissions\CommissionDueResource;
use Filament\Resources\Pages\ListRecords;

class ListCommissionsDue extends ListRecords
{
    protected static string $resource = CommissionDueResource::class;
}
