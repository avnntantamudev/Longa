<?php

declare(strict_types=1);

namespace App\Modules\Admin\Filament\Resources\Disputes;

use App\Modules\Admin\Filament\Resources\Disputes\Pages\ListDisputes;
use App\Modules\Admin\Filament\Resources\Disputes\Pages\ViewDispute;
use App\Modules\Admin\Filament\Support\SessionSections;
use App\Modules\Sessions\Models\Dispute;
use App\Modules\Sessions\Models\DisputeAttachment;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\HtmlString;
use UnitEnum;

class DisputeResource extends Resource
{
    protected static ?string $model = Dispute::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static string|UnitEnum|null $navigationGroup = 'Operações';

    protected static ?string $modelLabel = 'disputa';

    protected static ?string $pluralModelLabel = 'disputas';

    protected static ?string $slug = 'disputas';

    protected static ?int $navigationSort = 1;

    public static function getNavigationBadge(): ?string
    {
        $open = Dispute::query()->where('status', Dispute::OPEN)->count();

        return $open > 0 ? (string) $open : null;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['session.client', 'session.provider.user', 'opener']);
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
                TextColumn::make('session.title')->label('Serviço')->searchable(),
                TextColumn::make('reason')->label('Motivo')->formatStateUsing(fn ($state, Dispute $record) => $record->reasonLabel()),
                TextColumn::make('session.amount')->label('Valor')->formatStateUsing(fn (int $state) => SessionSections::kz($state)),
                TextColumn::make('opener.name')->label('Aberta por'),
                TextColumn::make('status')->label('Estado')->badge()
                    ->formatStateUsing(fn (string $state) => $state === Dispute::OPEN ? 'Aberta' : 'Resolvida')
                    ->color(fn (string $state) => $state === Dispute::OPEN ? 'warning' : 'success'),
                TextColumn::make('created_at')->label('Aberta em')->dateTime('d/m/Y H:i')->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->label('Estado')
                    ->options([Dispute::OPEN => 'Abertas', Dispute::RESOLVED => 'Resolvidas'])
                    ->default(Dispute::OPEN),
            ])
            ->recordActions([ViewAction::make()]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make('Disputa')
                ->columns(3)
                ->schema([
                    TextEntry::make('reason')->label('Motivo')->formatStateUsing(fn ($state, Dispute $record) => $record->reasonLabel()),
                    TextEntry::make('opener.name')->label('Aberta por'),
                    TextEntry::make('created_at')->label('Aberta em')->dateTime('d/m/Y H:i'),
                    TextEntry::make('description')->label('Descrição')->columnSpanFull(),
                    TextEntry::make('attachments')->label('Evidências')->columnSpanFull()
                        ->state(fn (Dispute $record) => self::attachmentLinks($record))
                        ->placeholder('Sem anexos'),
                    TextEntry::make('resolution')->label('Decisão')->badge()->placeholder('Por decidir'),
                    TextEntry::make('provider_share')->label('Parte do técnico (bruto)')->placeholder('—')
                        ->formatStateUsing(fn (?int $state) => $state === null ? '—' : SessionSections::kz($state)),
                    TextEntry::make('resolution_note')->label('Nota da decisão')->placeholder('—'),
                ]),
            SessionSections::details('session.'),
            SessionSections::timeline('session.'),
            SessionSections::ledger('session.'),
        ]);
    }

    /**
     * Links assinados de 10 minutos (o ficheiro nunca fica público).
     */
    private static function attachmentLinks(Dispute $dispute): ?HtmlString
    {
        if ($dispute->attachments->isEmpty()) {
            return null;
        }

        $minutes = (int) config('longa.disputes.attachment_link_minutes');
        $links = $dispute->attachments->map(function (DisputeAttachment $a) use ($minutes) {
            $url = URL::temporarySignedRoute('disputes.attachment', now()->addMinutes($minutes), ['attachment' => $a->id], absolute: false);

            return '<a class="underline" target="_blank" rel="noopener" href="'.e($url).'">'.e($a->original_name).'</a> ('.e(number_format($a->size / 1024, 0, ',', '.')).' KB)';
        });

        return new HtmlString($links->implode('<br>'));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDisputes::route('/'),
            'view' => ViewDispute::route('/{record}'),
        ];
    }
}
