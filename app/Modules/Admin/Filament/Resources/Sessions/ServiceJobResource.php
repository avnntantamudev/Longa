<?php

declare(strict_types=1);

namespace App\Modules\Admin\Filament\Resources\Sessions;

use App\Modules\Admin\Filament\Resources\Sessions\Pages\ListServiceJobs;
use App\Modules\Admin\Filament\Resources\Sessions\Pages\ViewServiceJob;
use App\Modules\Admin\Filament\Support\SessionSections;
use App\Modules\Requests\Enums\RequestState;
use App\Modules\Requests\Models\ServiceRequest;
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
 * Serviços (só leitura): estado, linha do tempo e movimentos de dinheiro.
 */
class ServiceJobResource extends Resource
{
    protected static ?string $model = ServiceRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAcademicCap;

    protected static string|UnitEnum|null $navigationGroup = 'Operações';

    protected static ?string $modelLabel = 'serviço';

    protected static ?string $pluralModelLabel = 'serviços';

    protected static ?string $slug = 'servicos';

    protected static ?int $navigationSort = 2;

    public static function getEloquentQuery(): Builder
    {
        // Serviços = pedidos com técnico atribuído
        return parent::getEloquentQuery()->whereNotNull('provider_profile_id')->with(['client', 'provider.user', 'escrowHold']);
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
                TextColumn::make('title')->label('Serviço')->searchable(),
                TextColumn::make('client.name')->label('Cliente'),
                TextColumn::make('provider.user.name')->label('Técnico'),
                TextColumn::make('amount')->label('Valor')->formatStateUsing(fn (int $state) => SessionSections::kz($state)),
                TextColumn::make('state')->label('Estado')->badge()->formatStateUsing(fn (RequestState $state) => $state->label()),
                TextColumn::make('scheduled_at')->label('Data')->dateTime('d/m/Y H:i')->sortable(),
            ])
            ->filters([
                SelectFilter::make('state')->label('Estado')
                    ->options(collect(RequestState::cases())->mapWithKeys(fn (RequestState $s) => [$s->value => $s->label()])->all()),
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
