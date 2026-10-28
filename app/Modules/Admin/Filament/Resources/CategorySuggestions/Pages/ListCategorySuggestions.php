<?php

declare(strict_types=1);

namespace App\Modules\Admin\Filament\Resources\CategorySuggestions\Pages;

use App\Modules\Admin\Filament\Resources\CategorySuggestions\CategorySuggestionResource;
use Filament\Resources\Pages\ListRecords;

class ListCategorySuggestions extends ListRecords
{
    protected static string $resource = CategorySuggestionResource::class;
}
