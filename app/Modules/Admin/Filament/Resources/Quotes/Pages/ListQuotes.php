<?php

declare(strict_types=1);

namespace App\Modules\Admin\Filament\Resources\Quotes\Pages;

use App\Modules\Admin\Filament\Resources\Quotes\QuoteResource;
use Filament\Resources\Pages\ListRecords;

class ListQuotes extends ListRecords
{
    protected static string $resource = QuoteResource::class;
}
