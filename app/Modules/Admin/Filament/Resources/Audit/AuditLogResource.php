<?php

declare(strict_types=1);

namespace App\Modules\Admin\Filament\Resources\Audit;

use App\Models\AuditLog;
use App\Modules\Admin\Filament\Concerns\AdminOnly;
use App\Modules\Admin\Filament\Resources\Audit\Pages\ListAuditLogs;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Registo de auditoria (só leitura; só admin): ações da equipa e operações financeiras.
 */
class AuditLogResource extends Resource
{
    use AdminOnly;

    protected static ?string $model = AuditLog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|UnitEnum|null $navigationGroup = 'Finanças';

    protected static ?string $modelLabel = 'registo de auditoria';

    protected static ?string $pluralModelLabel = 'auditoria';

    protected static ?string $slug = 'auditoria';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('created_at')->label('Quando')->dateTime('d/m/Y H:i:s'),
                TextColumn::make('action')->label('Ação')->searchable(),
                TextColumn::make('user_id')->label('Utilizador')->placeholder('Sistema')->formatStateUsing(fn ($state) => "#{$state}"),
                TextColumn::make('subject_type')->label('Objeto')->placeholder('—')
                    ->formatStateUsing(fn (string $state, AuditLog $record) => class_basename($state)." #{$record->subject_id}"),
                TextColumn::make('metadata')->label('Detalhes')->limit(120)->wrap()
                    ->state(fn (AuditLog $record) => $record->metadata === null ? null : json_encode($record->metadata, JSON_UNESCAPED_UNICODE))
                    ->placeholder('—'),
                TextColumn::make('ip')->label('IP')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Filter::make('period')
                    ->schema([
                        TextInput::make('action')->label('Ação começa por'),
                        DatePicker::make('from')->label('De'),
                        DatePicker::make('to')->label('Até'),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['action'] ?? null, fn (Builder $q, $action) => $q->where('action', 'like', "{$action}%"))
                        ->when($data['from'] ?? null, fn (Builder $q, $from) => $q->whereDate('created_at', '>=', $from))
                        ->when($data['to'] ?? null, fn (Builder $q, $to) => $q->whereDate('created_at', '<=', $to))),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListAuditLogs::route('/')];
    }
}
