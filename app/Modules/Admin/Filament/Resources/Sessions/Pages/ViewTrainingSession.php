<?php

declare(strict_types=1);

namespace App\Modules\Admin\Filament\Resources\Sessions\Pages;

use App\Modules\Admin\Filament\Resources\Sessions\TrainingSessionResource;
use Filament\Resources\Pages\ViewRecord;

class ViewTrainingSession extends ViewRecord
{
    protected static string $resource = TrainingSessionResource::class;
}
