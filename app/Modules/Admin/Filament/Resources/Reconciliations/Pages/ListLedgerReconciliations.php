<?php

declare(strict_types=1);

namespace App\Modules\Admin\Filament\Resources\Reconciliations\Pages;

use App\Modules\Admin\Filament\Resources\Reconciliations\LedgerReconciliationResource;
use App\Modules\Wallet\Services\LedgerReconciler;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListLedgerReconciliations extends ListRecords
{
    protected static string $resource = LedgerReconciliationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('run')
                ->label('Verificar agora')
                ->action(function () {
                    $report = app(LedgerReconciler::class)->run();
                    $report->isOk()
                        ? Notification::make()->success()->title('Ledger reconciliado')->send()
                        : Notification::make()->danger()->title('Foram encontradas diferenças')->send();
                }),
        ];
    }
}
