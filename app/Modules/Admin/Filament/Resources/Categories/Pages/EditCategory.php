<?php

declare(strict_types=1);

namespace App\Modules\Admin\Filament\Resources\Categories\Pages;

use App\Modules\Admin\Filament\Resources\Categories\CategoryResource;
use App\Support\Audit\AuditLogger;
use Filament\Resources\Pages\EditRecord;

/**
 * Sem apagar: desativar a categoria tira-a das listas e mantém o histórico dos pedidos.
 */
class EditCategory extends EditRecord
{
    protected static string $resource = CategoryResource::class;

    protected function afterSave(): void
    {
        app(AuditLogger::class)->record('categories.updated', $this->getRecord(), ['changes' => $this->getRecord()->getChanges()]);
    }
}
