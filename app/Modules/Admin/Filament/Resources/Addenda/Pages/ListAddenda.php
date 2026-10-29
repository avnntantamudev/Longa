<?php

declare(strict_types=1);

namespace App\Modules\Admin\Filament\Resources\Addenda\Pages;

use App\Modules\Admin\Filament\Resources\Addenda\AddendumResource;
use Filament\Resources\Pages\ListRecords;

class ListAddenda extends ListRecords
{
    protected static string $resource = AddendumResource::class;
}
