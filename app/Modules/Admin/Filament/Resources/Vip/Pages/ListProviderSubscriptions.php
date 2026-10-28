<?php

declare(strict_types=1);

namespace App\Modules\Admin\Filament\Resources\Vip\Pages;

use App\Modules\Admin\Filament\Resources\Vip\ProviderSubscriptionResource;
use App\Modules\Payments\Services\VipSubscriptions;
use App\Modules\Users\Models\ProviderProfile;
use App\Modules\Users\Models\User;
use App\Support\Settings\PlatformSettings;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Auth;

class ListProviderSubscriptions extends ListRecords
{
    protected static string $resource = ProviderSubscriptionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('grant')
                ->label('Registar VIP')
                ->modalHeading('Registar VIP sem pagamento na app')
                ->modalDescription(fn (PlatformSettings $s) => 'Em mão: entra no ledger o preço atual ('.number_format($s->int('vip_price'), 0, ',', '.').' Kz). Cortesia: sem dinheiro. Em ambos, soma '.$s->int('vip_duration_days').' dias ao VIP em vigor.')
                ->schema([
                    Select::make('provider_profile_id')->label('Formador')->required()->searchable()
                        ->getSearchResultsUsing(fn (string $search) => ProviderProfile::query()->join('users', 'users.id', '=', 'provider_profiles.user_id')
                            ->where(fn ($q) => $q->where('users.name', 'like', "%{$search}%")->orWhere('users.phone', 'like', "%{$search}%"))
                            ->limit(20)->pluck('users.name', 'provider_profiles.id')->all())
                        ->getOptionLabelUsing(fn ($value) => ProviderProfile::query()->with('user')->find($value)?->user->name),
                    Radio::make('source')->label('Tipo')->required()->options(['cash' => 'Pagamento recebido em mão', 'courtesy' => 'Cortesia']),
                    TextInput::make('note')->label('Nota (fica na auditoria)')->required()->minLength(5)->maxLength(255),
                ])
                ->action(function (array $data) {
                    /** @var User $admin */
                    $admin = Auth::user();
                    $provider = ProviderProfile::query()->findOrFail($data['provider_profile_id']);
                    $subscription = app(VipSubscriptions::class)->grant($provider, $admin, (string) $data['source'], (string) $data['note']);
                    Notification::make()->success()->title('VIP registado até '.$subscription->ends_at?->format('d/m/Y'))->send();
                }),
        ];
    }
}
