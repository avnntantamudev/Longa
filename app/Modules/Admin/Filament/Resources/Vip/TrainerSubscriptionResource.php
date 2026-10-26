<?php

declare(strict_types=1);

namespace App\Modules\Admin\Filament\Resources\Vip;

use App\Modules\Admin\Filament\Resources\Vip\Pages\ListTrainerSubscriptions;
use App\Modules\Admin\Filament\Support\SessionSections;
use App\Modules\Payments\Models\TrainerSubscription;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Subscrições VIP (só leitura; registar pagamentos em mão e cortesias na ação do topo).
 */
class TrainerSubscriptionResource extends Resource
{
    protected static ?string $model = TrainerSubscription::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedStar;

    protected static string|UnitEnum|null $navigationGroup = 'Finanças';

    protected static ?string $modelLabel = 'subscrição VIP';

    protected static ?string $pluralModelLabel = 'VIP';

    protected static ?string $slug = 'vip';

    protected static ?int $navigationSort = 4;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('trainer.user');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('trainer.user.name')->label('Formador')->searchable(),
                TextColumn::make('trainer.user.phone')->label('Telefone'),
                TextColumn::make('status')->label('Estado')->badge()
                    ->formatStateUsing(fn (string $state) => ['active' => 'Ativa', 'pending' => 'Pagamento em curso', 'failed' => 'Pagamento falhou'][$state] ?? $state)
                    ->color(fn (string $state) => ['active' => 'success', 'pending' => 'warning', 'failed' => 'danger'][$state] ?? 'gray'),
                TextColumn::make('source')->label('Origem')
                    ->formatStateUsing(fn (string $state) => ['electronic' => 'Pagamento na app', 'cash' => 'Em mão', 'courtesy' => 'Cortesia'][$state] ?? $state),
                TextColumn::make('amount')->label('Valor')->formatStateUsing(fn (int $state) => SessionSections::kz($state)),
                TextColumn::make('starts_at')->label('Início')->date('d/m/Y'),
                TextColumn::make('ends_at')->label('Fim')->date('d/m/Y')->sortable(),
                TextColumn::make('note')->label('Nota')->limit(30)->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('status')->label('Estado')->options(['active' => 'Ativas', 'pending' => 'Em curso', 'failed' => 'Falhadas']),
                Filter::make('current')->label('VIP em vigor')->default()
                    ->query(fn (Builder $q) => $q->where('status', 'active')->where('ends_at', '>', now())),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListTrainerSubscriptions::route('/')];
    }
}
