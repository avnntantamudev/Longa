<?php

declare(strict_types=1);

namespace App\Modules\Admin\Filament\Resources\FlaggedMessages\Pages;

use App\Modules\Admin\Filament\Resources\FlaggedMessages\FlaggedMessageResource;
use Filament\Resources\Pages\ListRecords;

class ListFlaggedMessages extends ListRecords
{
    protected static string $resource = FlaggedMessageResource::class;
}
