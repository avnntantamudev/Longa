<?php

declare(strict_types=1);

namespace App\Modules\Admin\Filament\Resources\Withdrawals\Pages;

use App\Modules\Admin\Filament\Resources\Withdrawals\WithdrawalResource;
use Filament\Resources\Pages\ListRecords;

class ListWithdrawals extends ListRecords
{
    protected static string $resource = WithdrawalResource::class;
}
