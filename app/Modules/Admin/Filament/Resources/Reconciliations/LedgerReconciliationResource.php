<?php

declare(strict_types=1);

namespace App\Modules\Admin\Filament\Resources\Reconciliations;

use App\Modules\Admin\Filament\Resources\Reconciliations\Pages\ListLedgerReconciliations;
use App\Modules\Admin\Filament\Resources\Reconciliations\Pages\ViewLedgerReconciliation;
use App\Modules\Wallet\Models\LedgerReconciliation;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class LedgerReconciliationResource extends Resource
{
    protected static ?string $model = LedgerReconciliation::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Finanças';

    protected static ?string $modelLabel = 'reconciliação';

    protected static ?string $pluralModelLabel = 'reconciliações';

    protected static ?string $slug = 'reconciliacoes';

    protected static ?int $navigationSort = 3;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('checked_at')->label('Verificada em')->dateTime('d/m/Y H:i:s'),
                TextColumn::make('status')->label('Resultado')->badge()
                    ->formatStateUsing(fn (string $state) => $state === LedgerReconciliation::OK ? 'OK' : 'Diferenças')
                    ->color(fn (string $state) => $state === LedgerReconciliation::OK ? 'success' : 'danger'),
                TextColumn::make('issues')->label('Problemas')->state(fn (LedgerReconciliation $record) => count($record->issues)),
                TextColumn::make('duration_ms')->label('Duração (ms)'),
            ])
            ->recordActions([ViewAction::make()]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make('Resultado')->columns(3)->schema([
                TextEntry::make('checked_at')->label('Verificada em')->dateTime('d/m/Y H:i:s'),
                TextEntry::make('status')->label('Resultado')->badge(),
                TextEntry::make('duration_ms')->label('Duração (ms)'),
                KeyValueEntry::make('totals')->label('Saldos')->columnSpanFull(),
            ]),
            Section::make('Problemas')->schema([
                RepeatableEntry::make('issues')->hiddenLabel()->columns(3)->placeholder('Nenhum')->schema([
                    TextEntry::make('severity')->label('Gravidade')->badge()
                        ->color(fn (string $state) => $state === 'critical' ? 'danger' : 'warning'),
                    TextEntry::make('check')->label('Verificação'),
                    TextEntry::make('message')->label('Detalhe'),
                ]),
            ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLedgerReconciliations::route('/'),
            'view' => ViewLedgerReconciliation::route('/{record}'),
        ];
    }
}
