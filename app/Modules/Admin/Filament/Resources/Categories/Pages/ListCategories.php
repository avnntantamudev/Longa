<?php

declare(strict_types=1);

namespace App\Modules\Admin\Filament\Resources\Categories\Pages;

use App\Modules\Admin\Filament\Resources\Categories\CategoryResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCategories extends ListRecords
{
    protected static string $resource = CategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Nova categoria')];
    }
}
