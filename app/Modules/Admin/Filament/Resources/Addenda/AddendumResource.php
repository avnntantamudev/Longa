<?php

declare(strict_types=1);

namespace App\Modules\Admin\Filament\Resources\Addenda;

use App\Modules\Admin\Filament\Resources\Addenda\Pages\ListAddenda;
use App\Modules\Admin\Filament\Support\SessionSections;
use App\Modules\Requests\Models\QuoteAddendum;
use App\Modules\Requests\Services\AddendumFlow;
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
 * Adendas ao orçamento. As que passam o limite percentual do orçamento original (Definições) e já foram aprovadas
 * pelo cliente ficam "em revisão pela equipa": só o admin aprova ou recusa (muda o valor do serviço), com nota.
 */
class AddendumResource extends Resource
{
    protected static ?string $model = QuoteAddendum::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentPlus;

    protected static string|UnitEnum|null $navigationGroup = 'Operações';

    protected static ?string $modelLabel = 'adenda';

    protected static ?string $pluralModelLabel = 'adendas';

    protected static ?string $slug = 'adendas';

    protected static ?int $navigationSort = 4;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['quote', 'request.client', 'provider.user', 'reviewer']);
    }

    /** Adendas à espera da equipa */
    public static function getNavigationBadge(): ?string
    {
        $count = QuoteAddendum::query()->where('status', QuoteAddendum::PENDING_TEAM)->count();

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
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('id')->label('#'),
                TextColumn::make('service_request_id')->label('Pedido'),
                TextColumn::make('provider.user.name')->label('Técnico'),
                TextColumn::make('request.client.name')->label('Cliente'),
                TextColumn::make('description')->label('Trabalho extra')->limit(60)->wrap(),
                TextColumn::make('amount')->label('Valor')->formatStateUsing(fn (int $state) => SessionSections::kz($state)),
                TextColumn::make('quote.total')->label('Orçamento original')->formatStateUsing(fn (int $state) => SessionSections::kz($state)),
                TextColumn::make('percent_of_quote')->label('% do orçamento')->suffix('%'),
                TextColumn::make('status')->label('Estado')->badge()->formatStateUsing(fn (string $state, QuoteAddendum $record) => $record->label()),
                TextColumn::make('created_at')->label('Proposta')->dateTime('d/m/Y H:i')->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->label('Estado')->options([
                    QuoteAddendum::PENDING_TEAM => 'Em revisão pela equipa', QuoteAddendum::PENDING_CLIENT => 'À espera do cliente',
                    QuoteAddendum::APPROVED => 'Aprovada', QuoteAddendum::PAID => 'Paga', QuoteAddendum::REJECTED => 'Recusada',
                ]),
            ])
            ->recordActions([self::review('approve', true), self::review('reject', false)]);
    }

    private static function review(string $name, bool $approve): Action
    {
        return Action::make($name)
            ->label($approve ? 'Aprovar' : 'Recusar')
            ->color($approve ? 'success' : 'danger')
            ->visible(fn (QuoteAddendum $record) => $record->status === QuoteAddendum::PENDING_TEAM && Auth::user() instanceof User && Auth::user()->isAdmin())
            ->modalHeading($approve ? 'Aprovar o trabalho extra' : 'Recusar o trabalho extra')
            ->modalDescription(fn (QuoteAddendum $record) => $approve
                ? 'O valor do serviço sobe '.SessionSections::kz($record->amount).' ('.$record->percent_of_quote.'% do orçamento original). O cliente já aprovou.'
                : 'O trabalho extra não é feito e o valor do serviço não muda.')
            ->schema([Textarea::make('note')->label('Nota (fica no histórico)')->required()->minLength(5)->maxLength(500)])
            ->action(function (QuoteAddendum $record, array $data) use ($approve) {
                /** @var User $admin */
                $admin = Auth::user();
                try {
                    app(AddendumFlow::class)->teamReview($record, $admin, $approve, (string) $data['note']);
                } catch (ApiException $e) {
                    Notification::make()->danger()->title('Não aplicado')->body($e->getMessage())->send();

                    return;
                }
                Notification::make()->success()->title($approve ? 'Adenda aprovada' : 'Adenda recusada')->send();
            });
    }

    public static function getPages(): array
    {
        return ['index' => ListAddenda::route('/')];
    }
}
