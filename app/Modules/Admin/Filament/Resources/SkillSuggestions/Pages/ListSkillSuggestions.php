<?php

declare(strict_types=1);

namespace App\Modules\Admin\Filament\Resources\SkillSuggestions\Pages;

use App\Modules\Admin\Filament\Resources\SkillSuggestions\SkillSuggestionResource;
use Filament\Resources\Pages\ListRecords;

class ListSkillSuggestions extends ListRecords
{
    protected static string $resource = SkillSuggestionResource::class;
}
