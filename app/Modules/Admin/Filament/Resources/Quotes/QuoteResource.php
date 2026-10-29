<?php

declare(strict_types=1);

namespace App\Modules\Admin\Filament\Resources\Quotes;

use App\Modules\Admin\Filament\Resources\Quotes\Pages\ListQuotes;
use App\Modules\Admin\Filament\Support\SessionSections;
use App\Modules\Requests\Models\Quote;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Orçamentos (só leitura), com o alerta dos que estão muito fora do intervalo de referência da categoria
 * (tolerância em Definições → Orçamentos e adendas).
 */
class QuoteResource extends Resource
{
    protected static ?string $model = Quote::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static string|UnitEnum|null $navigationGroup = 'Operações';

    protected static ?string $modelLabel = 'orçamento';

    protected static ?string $pluralModelLabel = 'orçamentos';

    protected static ?string $slug = 'orcamentos';

    protected static ?int $navigationSort = 3;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['request.category.price', 'request.category.parent.price', 'provider.user']);
    }

    /** Alerta: orçamentos fora do intervalo ainda à espera de resposta */
    public static function getNavigationBadge(): ?string
    {
        $count = Quote::query()->where('outside_reference', true)->where('status', Quote::SENT)->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        $statuses = [
            Quote::SENT => 'Enviado', Quote::ACCEPTED => 'Aceite', Quote::REFUSED => 'Recusado',
            Quote::REVISION_REQUESTED => 'Revisão pedida', Quote::EXPIRED => 'Expirado', Quote::SUPERSEDED => 'Substituído',
        ];

        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('id')->label('#'),
                TextColumn::make('service_request_id')->label('Pedido'),
                TextColumn::make('request.category.name')->label('Categoria'),
                TextColumn::make('provider.user.name')->label('Técnico'),
                TextColumn::make('total')->label('Total')->formatStateUsing(fn (int $state) => SessionSections::kz($state)),
                TextColumn::make('reference')->label('Referência')
                    ->state(function (Quote $record): string {
                        $price = $record->request->category->effectivePrice();

                        return $price?->reference_min !== null && $price->reference_max !== null
                            ? SessionSections::kz($price->reference_min).' – '.SessionSections::kz($price->reference_max)
                            : '—';
                    }),
                IconColumn::make('outside_reference')->label('Fora do intervalo')->boolean()
                    ->trueIcon(Heroicon::OutlinedExclamationTriangle)->trueColor('warning')->falseIcon(null),
                TextColumn::make('status')->label('Estado')->badge()->formatStateUsing(fn (string $state) => $statuses[$state] ?? $state),
                TextColumn::make('revision')->label('Revisão'),
                TextColumn::make('created_at')->label('Enviado')->dateTime('d/m/Y H:i')->sortable(),
            ])
            ->filters([
                TernaryFilter::make('outside_reference')->label('Fora do intervalo de referência'),
                SelectFilter::make('status')->label('Estado')->options($statuses),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListQuotes::route('/')];
    }
}
