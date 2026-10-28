<?php

declare(strict_types=1);

namespace App\Modules\Admin\Filament\Resources\Sessions;

use App\Modules\Admin\Filament\Resources\Sessions\Pages\ListServiceJobs;
use App\Modules\Admin\Filament\Resources\Sessions\Pages\ViewServiceJob;
use App\Modules\Admin\Filament\Support\SessionSections;
use App\Modules\Sessions\Enums\SessionState;
use App\Modules\Sessions\Models\ServiceJob;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Aulas (só leitura): estado, linha do tempo e movimentos de dinheiro.
 */
class ServiceJobResource extends Resource
{
    protected static ?string $model = ServiceJob::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAcademicCap;

    protected static string|UnitEnum|null $navigationGroup = 'Operações';

    protected static ?string $modelLabel = 'aula';

    protected static ?string $pluralModelLabel = 'aulas';

    protected static ?string $slug = 'aulas';

    protected static ?int $navigationSort = 2;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['client', 'provider.user', 'escrowHold']);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('scheduled_at', 'desc')
            ->columns([
                TextColumn::make('id')->label('#'),
                TextColumn::make('title')->label('Aula')->searchable(),
                TextColumn::make('client.name')->label('Aluno'),
                TextColumn::make('provider.user.name')->label('Formador'),
                TextColumn::make('amount')->label('Valor')->formatStateUsing(fn (int $state) => SessionSections::kz($state)),
                TextColumn::make('state')->label('Estado')->badge()->formatStateUsing(fn (SessionState $state) => $state->label()),
                TextColumn::make('scheduled_at')->label('Data')->dateTime('d/m/Y H:i')->sortable(),
            ])
            ->filters([
                SelectFilter::make('state')->label('Estado')
                    ->options(collect(SessionState::cases())->mapWithKeys(fn (SessionState $s) => [$s->value => $s->label()])->all()),
                SelectFilter::make('payment_method')->label('Pagamento')->options(['electronic' => 'Eletrónico', 'cash' => 'Em mão']),
            ])
            ->recordActions([ViewAction::make()]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            SessionSections::details(),
            SessionSections::timeline(),
            SessionSections::ledger(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListServiceJobs::route('/'),
            'view' => ViewServiceJob::route('/{record}'),
        ];
    }
}
