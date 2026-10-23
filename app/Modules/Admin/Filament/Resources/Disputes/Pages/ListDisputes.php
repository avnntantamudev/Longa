<?php

declare(strict_types=1);

namespace App\Modules\Admin\Filament\Resources\Disputes\Pages;

use App\Modules\Admin\Filament\Resources\Disputes\DisputeResource;
use Filament\Resources\Pages\ListRecords;

class ListDisputes extends ListRecords
{
    protected static string $resource = DisputeResource::class;
}
