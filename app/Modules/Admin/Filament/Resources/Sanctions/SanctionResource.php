<?php

declare(strict_types=1);

namespace App\Modules\Admin\Filament\Resources\Sanctions;

use App\Modules\Admin\Filament\Resources\Sanctions\Pages\ListSanctions;
use App\Modules\Ratings\Models\ProviderSanction;
use App\Modules\Ratings\Services\RatingService;
use App\Modules\Users\Models\User;
use App\Support\Api\ApiException;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
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
 * Sanções por avaliações (aviso, suspensão, remoção). As remoções ficam pendentes até a equipa (admin ou operador)
 * confirmar ou cancelar, com nota e auditoria.
 */
class SanctionResource extends Resource
{
    protected static ?string $model = ProviderSanction::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldExclamation;

    protected static string|UnitEnum|null $navigationGroup = 'Operações';

    protected static ?string $modelLabel = 'sanção';

    protected static ?string $pluralModelLabel = 'sanções por avaliações';

    protected static ?string $slug = 'sancoes';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['provider.user', 'decider']);
    }

    public static function getNavigationBadge(): ?string
    {
        $count = ProviderSanction::query()->where('status', ProviderSanction::PENDING_REVIEW)->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        $statuses = [
            ProviderSanction::ACTIVE => 'Ativa', ProviderSanction::ENDED => 'Terminada', ProviderSanction::PENDING_REVIEW => 'À espera da equipa',
            ProviderSanction::CONFIRMED => 'Confirmada', ProviderSanction::CANCELLED => 'Cancelada',
        ];

        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('provider.user.name')->label('Técnico')->searchable(),
                TextColumn::make('level')->label('Sanção')->badge()->formatStateUsing(fn (string $state, ProviderSanction $record) => $record->label()),
                TextColumn::make('average')->label('Média')->numeric(1),
                TextColumn::make('ratings_count')->label('Avaliações'),
                TextColumn::make('reason')->label('Motivo')->wrap(),
                TextColumn::make('status')->label('Estado')->formatStateUsing(fn (string $state) => $statuses[$state] ?? $state),
                TextColumn::make('ends_at')->label('Até')->dateTime('d/m/Y H:i')->placeholder('—'),
                TextColumn::make('created_at')->label('Data')->dateTime('d/m/Y H:i')->sortable(),
            ])
            ->filters([
                SelectFilter::make('level')->label('Sanção')->options([ProviderSanction::WARNING => 'Aviso', ProviderSanction::SUSPENSION => 'Suspensão', ProviderSanction::REMOVAL => 'Remoção']),
                SelectFilter::make('status')->label('Estado')->options($statuses),
            ])
            ->recordActions([self::review('confirm', true), self::review('cancel', false)]);
    }

    private static function review(string $name, bool $confirm): Action
    {
        return Action::make($name)
            ->label($confirm ? 'Confirmar remoção' : 'Cancelar remoção')
            ->color($confirm ? 'danger' : 'gray')
            ->visible(fn (ProviderSanction $record) => $record->status === ProviderSanction::PENDING_REVIEW)
            ->modalDescription($confirm ? 'O técnico deixa de poder receber pedidos.' : 'O técnico continua ativo.')
            ->schema([Textarea::make('note')->label('Nota (fica no histórico)')->required()->minLength(5)->maxLength(500)])
            ->action(function (ProviderSanction $record, array $data) use ($confirm) {
                /** @var User $reviewer */
                $reviewer = Auth::user();
                try {
                    app(RatingService::class)->reviewRemoval($record, $reviewer, $confirm, (string) $data['note']);
                } catch (ApiException $e) {
                    Notification::make()->danger()->title('Não aplicado')->body($e->getMessage())->send();

                    return;
                }
                Notification::make()->success()->title($confirm ? 'Remoção confirmada' : 'Remoção cancelada')->send();
            });
    }

    public static function getPages(): array
    {
        return ['index' => ListSanctions::route('/')];
    }
}
