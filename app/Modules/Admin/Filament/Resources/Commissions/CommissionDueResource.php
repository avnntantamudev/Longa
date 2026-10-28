<?php

declare(strict_types=1);

namespace App\Modules\Admin\Filament\Resources\Commissions;

use App\Modules\Admin\Filament\Resources\Commissions\Pages\ListCommissionsDue;
use App\Modules\Admin\Filament\Support\SessionSections;
use App\Modules\Payments\Services\CommissionDebts;
use App\Modules\Users\Models\ProviderProfile;
use App\Modules\Users\Models\User;
use App\Modules\Wallet\Ledger\Accounts;
use App\Modules\Wallet\Ledger\LedgerException;
use App\Modules\Wallet\Models\LedgerAccount;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use UnitEnum;

/**
 * Comissões de aulas pagas em mão que o formador ainda deve ao BISCA.
 * "Marcar recebido" regista o dinheiro entregue ao admin (transação cash_commission_received).
 */
class CommissionDueResource extends Resource
{
    protected static ?string $model = LedgerAccount::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'Finanças';

    protected static ?string $modelLabel = 'comissão a receber';

    protected static ?string $pluralModelLabel = 'comissões a receber';

    protected static ?string $slug = 'comissoes-a-receber';

    protected static ?int $navigationSort = 2;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('type', 'provider_commission_due')
            ->whereHas('balance', fn (Builder $q) => $q->where('balance', '<', 0))
            ->with('balance');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('provider')->label('Formador')
                    ->state(fn (LedgerAccount $record) => self::provider($record)?->user->name ?? '—'),
                TextColumn::make('phone')->label('Telefone')
                    ->state(fn (LedgerAccount $record) => self::provider($record)?->user->phone ?? '—'),
                TextColumn::make('balance.balance')->label('Em dívida')
                    ->formatStateUsing(fn (int $state) => SessionSections::kz(-$state)),
            ])
            ->recordActions([
                Action::make('receive')
                    ->label('Marcar recebido')
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('success')
                    ->modalHeading('Registar comissão recebida em mão')
                    ->modalDescription('Confirme que recebeu este dinheiro do formador. O registo fica no ledger e não pode ser apagado.')
                    ->fillForm(fn (LedgerAccount $record) => ['amount' => -($record->balance->balance ?? 0), 'key' => (string) Str::uuid()])
                    ->schema([
                        Hidden::make('key'),
                        TextInput::make('amount')->label('Valor recebido (Kz)')->integer()->required()->minValue(1)
                            ->maxValue(fn (LedgerAccount $record) => -($record->balance->balance ?? 0)),
                        Textarea::make('note')->label('Nota (opcional)')->maxLength(500),
                    ])
                    ->action(function (LedgerAccount $record, array $data) {
                        [, $providerId] = Accounts::parse($record->code);
                        /** @var User $admin */
                        $admin = Auth::user();

                        try {
                            app(CommissionDebts::class)->receive((int) $providerId, (int) $data['amount'], (string) $data['key'], $admin, $data['note'] ?? null);
                        } catch (LedgerException $e) {
                            Notification::make()->danger()->title('Não registado')->body($e->getMessage())->send();

                            return;
                        }

                        Notification::make()->success()->title('Comissão registada como recebida')->send();
                    }),
            ]);
    }

    private static function provider(LedgerAccount $account): ?ProviderProfile
    {
        [, $id] = Accounts::parse($account->code);

        return ProviderProfile::query()->with('user')->find($id);
    }

    public static function getPages(): array
    {
        return ['index' => ListCommissionsDue::route('/')];
    }
}
