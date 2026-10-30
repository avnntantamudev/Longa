<?php

declare(strict_types=1);

namespace App\Modules\Admin\Filament\Resources\Requests;

use App\Modules\Admin\Filament\Resources\Requests\Pages\ListServiceRequests;
use App\Modules\Admin\Filament\Support\SessionSections;
use App\Modules\Requests\Enums\RequestState;
use App\Modules\Requests\Models\ServiceRequest;
use App\Modules\Users\Enums\PricingMode;
use App\Modules\Users\Models\Category;
use BackedEnum;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Todos os pedidos em tempo real (a lista atualiza-se sozinha), com filtros por estado, zona e categoria.
 */
class ServiceRequestResource extends Resource
{
    protected static ?string $model = ServiceRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxStack;

    protected static string|UnitEnum|null $navigationGroup = 'Operações';

    protected static ?string $modelLabel = 'pedido';

    protected static ?string $pluralModelLabel = 'pedidos';

    protected static ?string $slug = 'pedidos';

    protected static ?int $navigationSort = 1;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['client', 'category', 'provider.user']);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->poll('10s')
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('id')->label('#'),
                TextColumn::make('title')->label('Pedido')->searchable()->limit(40),
                TextColumn::make('category.name')->label('Categoria'),
                TextColumn::make('client.name')->label('Cliente'),
                TextColumn::make('provider.user.name')->label('Técnico')->placeholder('—'),
                TextColumn::make('state')->label('Estado')->badge()->formatStateUsing(fn (RequestState $state) => $state->label()),
                TextColumn::make('amount')->label('Valor')->formatStateUsing(fn (int $state) => SessionSections::kz($state)),
                TextColumn::make('municipality')->label('Zona')->formatStateUsing(fn (string $state, ServiceRequest $record) => "{$record->neighborhood}, {$state}"),
                TextColumn::make('created_at')->label('Criado')->dateTime('d/m/Y H:i')->sortable(),
            ])
            ->filters([
                SelectFilter::make('state')->label('Estado')->multiple()
                    ->options(collect(RequestState::cases())->mapWithKeys(fn (RequestState $s) => [$s->value => $s->label()])->all()),
                SelectFilter::make('category_id')->label('Categoria')->searchable()
                    ->options(fn () => Category::query()->orderBy('name')->pluck('name', 'id')->all()),
                SelectFilter::make('pricing_mode')->label('Modo de preço')
                    ->options(collect(PricingMode::cases())->mapWithKeys(fn (PricingMode $m) => [$m->value => $m->label()])->all()),
                SelectFilter::make('province')->label('Província')
                    ->options(fn () => array_combine((array) config('locations.provinces'), (array) config('locations.provinces'))),
                Filter::make('zone')
                    ->schema([TextInput::make('zone')->label('Município ou bairro')])
                    ->query(fn (Builder $query, array $data) => $query->when($data['zone'] ?? null, fn (Builder $q, $zone) => $q->where(
                        fn (Builder $w) => $w->where('municipality', 'like', "%{$zone}%")->orWhere('neighborhood', 'like', "%{$zone}%"),
                    ))),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListServiceRequests::route('/')];
    }
}
