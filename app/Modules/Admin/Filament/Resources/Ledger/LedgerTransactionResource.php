<?php

declare(strict_types=1);

namespace App\Modules\Admin\Filament\Resources\Ledger;

use App\Modules\Admin\Filament\Concerns\AdminOnly;
use App\Modules\Admin\Filament\Resources\Ledger\Pages\ListLedgerTransactions;
use App\Modules\Admin\Filament\Resources\Ledger\Pages\ViewLedgerTransaction;
use App\Modules\Admin\Filament\Support\SessionSections;
use App\Modules\Wallet\Models\LedgerTransaction;
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
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Ledger só de leitura: não há criação, edição nem apagar (a BD também o impede).
 */
class LedgerTransactionResource extends Resource
{
    use AdminOnly;

    protected static ?string $model = LedgerTransaction::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static string|UnitEnum|null $navigationGroup = 'Finanças';

    protected static ?string $modelLabel = 'transação';

    protected static ?string $pluralModelLabel = 'ledger';

    protected static ?string $slug = 'ledger';

    protected static ?int $navigationSort = 1;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('entries.account');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('id')->label('#'),
                TextColumn::make('type')->label('Tipo')->badge(),
                TextColumn::make('total_debit')->label('Montante')->formatStateUsing(fn (int $state) => SessionSections::kz($state)),
                TextColumn::make('idempotency_key')->label('Chave')->searchable()->limit(40),
                TextColumn::make('created_at')->label('Quando')->dateTime('d/m/Y H:i:s'),
            ])
            ->filters([
                SelectFilter::make('type')->label('Tipo')->options(fn () => LedgerTransaction::query()->distinct()->orderBy('type')->pluck('type', 'type')->all()),
            ])
            ->recordActions([ViewAction::make()]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make('Transação')->columns(3)->schema([
                TextEntry::make('type')->label('Tipo')->badge(),
                TextEntry::make('idempotency_key')->label('Chave de idempotência'),
                TextEntry::make('created_at')->label('Quando')->dateTime('d/m/Y H:i:s'),
                TextEntry::make('reference_type')->label('Referência')->placeholder('—')
                    ->formatStateUsing(fn (?string $state, LedgerTransaction $record) => class_basename((string) $state).' #'.$record->reference_id),
                TextEntry::make('created_by')->label('Por')->placeholder('Sistema'),
                TextEntry::make('reverses_transaction_id')->label('Estorna')->placeholder('—'),
                KeyValueEntry::make('metadata')->label('Metadados')->columnSpanFull(),
            ]),
            Section::make('Lançamentos')->schema([
                RepeatableEntry::make('entries')->hiddenLabel()->columns(2)->schema([
                    TextEntry::make('account.code')->label('Conta'),
                    TextEntry::make('amount')->label('Valor (+ débito / − crédito)')
                        ->formatStateUsing(fn (int $state) => ($state > 0 ? '+' : '').SessionSections::kz($state)),
                ]),
            ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLedgerTransactions::route('/'),
            'view' => ViewLedgerTransaction::route('/{record}'),
        ];
    }
}
