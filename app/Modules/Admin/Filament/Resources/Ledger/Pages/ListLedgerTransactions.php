<?php

declare(strict_types=1);

namespace App\Modules\Admin\Filament\Resources\Ledger\Pages;

use App\Modules\Admin\Filament\Resources\Ledger\LedgerTransactionResource;
use App\Modules\Users\Models\ProviderProfile;
use App\Modules\Users\Models\User;
use App\Modules\Wallet\Services\WalletOperations;
use App\Support\Api\ApiException;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class ListLedgerTransactions extends ListRecords
{
    protected static string $resource = LedgerTransactionResource::class;

    /**
     * Ajuste à carteira de um técnico: sempre um movimento novo (positivo credita, negativo debita), com motivo e
     * auditoria. Os movimentos anteriores nunca são alterados.
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('adjust')->label('Ajuste à carteira')->icon('heroicon-o-adjustments-horizontal')
                ->modalDescription('Cria um movimento de ajuste. Para anular um movimento, use o estorno (os movimentos nunca se apagam).')
                ->schema([
                    Select::make('provider_profile_id')->label('Técnico')->required()->searchable()
                        ->getSearchResultsUsing(fn (string $search) => ProviderProfile::query()->join('users', 'users.id', '=', 'provider_profiles.user_id')
                            ->where('users.name', 'like', "%{$search}%")->orWhere('users.phone', 'like', "%{$search}%")
                            ->limit(20)->pluck('users.name', 'provider_profiles.id')->all())
                        ->getOptionLabelUsing(fn ($value) => ProviderProfile::query()->find($value)?->user->name),
                    TextInput::make('amount')->label('Valor (Kz; negativo debita)')->integer()->required()->notIn([0]),
                    Textarea::make('reason')->label('Motivo')->required()->minLength(5)->maxLength(500),
                ])
                ->action(function (array $data) {
                    /** @var User $admin */
                    $admin = Auth::user();
                    try {
                        app(WalletOperations::class)->adjust(
                            ProviderProfile::query()->findOrFail((int) $data['provider_profile_id']),
                            (int) $data['amount'],
                            (string) $data['reason'],
                            $admin,
                            (string) Str::uuid(),
                        );
                    } catch (ApiException $e) {
                        Notification::make()->danger()->title('Ajuste não aplicado')->body($e->getMessage())->send();

                        return;
                    }
                    Notification::make()->success()->title('Ajuste registado')->send();
                }),
        ];
    }
}
