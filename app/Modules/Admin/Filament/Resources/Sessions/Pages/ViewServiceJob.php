<?php

declare(strict_types=1);

namespace App\Modules\Admin\Filament\Resources\Sessions\Pages;

use App\Modules\Admin\Filament\Resources\Sessions\ServiceJobResource;
use Filament\Resources\Pages\ViewRecord;

class ViewServiceJob extends ViewRecord
{
    protected static string $resource = ServiceJobResource::class;
}
