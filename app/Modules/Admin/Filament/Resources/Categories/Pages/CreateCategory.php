<?php

declare(strict_types=1);

namespace App\Modules\Admin\Filament\Resources\Categories\Pages;

use App\Modules\Admin\Filament\Resources\Categories\CategoryResource;
use App\Support\Audit\AuditLogger;
use Filament\Resources\Pages\CreateRecord;

class CreateCategory extends CreateRecord
{
    protected static string $resource = CategoryResource::class;

    protected function afterCreate(): void
    {
        app(AuditLogger::class)->record('categories.created', $this->getRecord(), ['name' => $this->data['name'] ?? null]);
    }
}
