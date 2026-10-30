<?php

declare(strict_types=1);

namespace App\Modules\Admin\Filament\Resources\Withdrawals;

use App\Modules\Admin\Filament\Concerns\AdminOnly;
use App\Modules\Admin\Filament\Resources\Withdrawals\Pages\ListWithdrawals;
use App\Modules\Admin\Filament\Support\SessionSections;
use App\Modules\Users\Models\User;
use App\Modules\Wallet\Models\Withdrawal;
use App\Modules\Wallet\Services\WalletOperations;
use App\Support\Api\ApiException;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

/**
 * Levantamentos dos técnicos (só admin): pagar (com a referência da transferência) ou recusar (o valor volta ao
 * saldo do técnico por estorno). Cada decisão gera os movimentos e fica na auditoria.
 */
class WithdrawalResource extends Resource
{
    use AdminOnly;

    protected static ?string $model = Withdrawal::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'Finanças';

    protected static ?string $modelLabel = 'levantamento';

    protected static ?string $pluralModelLabel = 'levantamentos';

    protected static ?string $slug = 'levantamentos';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['provider.user', 'decider']);
    }

    public static function getNavigationBadge(): ?string
    {
        $count = Withdrawal::query()->where('status', Withdrawal::REQUESTED)->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('id')->label('#'),
                TextColumn::make('provider.user.name')->label('Técnico')->searchable(),
                TextColumn::make('amount')->label('Valor')->formatStateUsing(fn (int $state) => SessionSections::kz($state)),
                TextColumn::make('destination')->label('Destino'),
                TextColumn::make('status')->label('Estado')->badge()->formatStateUsing(fn (string $state, Withdrawal $record) => $record->label()),
                TextColumn::make('decider.name')->label('Decidido por')->placeholder('—'),
                TextColumn::make('created_at')->label('Pedido')->dateTime('d/m/Y H:i')->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->label('Estado')->options([Withdrawal::REQUESTED => 'Pedido', Withdrawal::PAID => 'Pago', Withdrawal::REJECTED => 'Recusado']),
            ])
            ->recordActions([
                Action::make('pay')->label('Marcar como pago')->color('success')
                    ->visible(fn (Withdrawal $record) => $record->status === Withdrawal::REQUESTED)
                    ->schema([TextInput::make('reference')->label('Referência da transferência')->required()->maxLength(120)])
                    ->action(fn (Withdrawal $record, array $data) => self::apply(fn (User $admin) => app(WalletOperations::class)->payWithdrawal($record, $admin, (string) $data['reference']), 'Levantamento pago')),
                Action::make('reject')->label('Recusar')->color('danger')
                    ->visible(fn (Withdrawal $record) => $record->status === Withdrawal::REQUESTED)
                    ->schema([Textarea::make('note')->label('Motivo (o técnico vê)')->required()->minLength(5)->maxLength(500)])
                    ->action(fn (Withdrawal $record, array $data) => self::apply(fn (User $admin) => app(WalletOperations::class)->rejectWithdrawal($record, $admin, (string) $data['note']), 'Levantamento recusado; o valor voltou ao saldo')),
            ]);
    }

    /**
     * @param  callable(User): mixed  $operation
     */
    private static function apply(callable $operation, string $success): void
    {
        /** @var User $admin */
        $admin = Auth::user();
        try {
            $operation($admin);
        } catch (ApiException $e) {
            Notification::make()->danger()->title('Não aplicado')->body($e->getMessage())->send();

            return;
        }
        Notification::make()->success()->title($success)->send();
    }

    public static function getPages(): array
    {
        return ['index' => ListWithdrawals::route('/')];
    }
}
