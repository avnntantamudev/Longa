<?php

declare(strict_types=1);

namespace App\Modules\Admin\Filament\Resources\Sanctions\Pages;

use App\Modules\Admin\Filament\Resources\Sanctions\SanctionResource;
use Filament\Resources\Pages\ListRecords;

class ListSanctions extends ListRecords
{
    protected static string $resource = SanctionResource::class;
}
