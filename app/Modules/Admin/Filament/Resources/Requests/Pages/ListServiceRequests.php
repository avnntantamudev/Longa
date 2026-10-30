<?php

declare(strict_types=1);

namespace App\Modules\Admin\Filament\Resources\Requests\Pages;

use App\Modules\Admin\Filament\Resources\Requests\ServiceRequestResource;
use Filament\Resources\Pages\ListRecords;

class ListServiceRequests extends ListRecords
{
    protected static string $resource = ServiceRequestResource::class;
}
