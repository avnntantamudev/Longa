<?php

declare(strict_types=1);

namespace App\Modules\Admin\Filament\Resources\FlaggedMessages;

use App\Modules\Admin\Filament\Resources\FlaggedMessages\Pages\ListFlaggedMessages;
use App\Modules\Sessions\Models\Message;
use App\Modules\Users\Models\User;
use App\Support\Audit\AuditLogger;
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
 * Mensagens do chat sinalizadas (contactos pessoais ou tentativas de combinar fora da plataforma). A equipa vê o
 * texto original (a outra parte só viu a versão mascarada) e marca-as como revistas, com nota.
 */
class FlaggedMessageResource extends Resource
{
    protected static ?string $model = Message::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFlag;

    protected static string|UnitEnum|null $navigationGroup = 'Operações';

    protected static ?string $modelLabel = 'mensagem sinalizada';

    protected static ?string $pluralModelLabel = 'mensagens sinalizadas';

    protected static ?string $slug = 'mensagens-sinalizadas';

    private const REASONS = ['phone' => 'Telefone', 'email' => 'E-mail', 'link' => 'Link de contacto', 'off_platform' => 'Fora da plataforma'];

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('flagged', true)->with(['sender', 'conversation']);
    }

    public static function getNavigationBadge(): ?string
    {
        $count = Message::query()->where('flagged', true)->where('flag_status', Message::FLAG_PENDING)->count();

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
                TextColumn::make('conversation.service_request_id')->label('Pedido'),
                TextColumn::make('sender.name')->label('Quem escreveu'),
                TextColumn::make('flagged_body')->label('Texto original')->wrap()->limit(200),
                TextColumn::make('flag_reasons')->label('Motivos')->badge()
                    ->formatStateUsing(fn (string $state) => self::REASONS[$state] ?? $state),
                TextColumn::make('flag_status')->label('Estado')->formatStateUsing(fn (?string $state) => $state === Message::FLAG_REVIEWED ? 'Revista' : 'Por rever'),
                TextColumn::make('created_at')->label('Data')->dateTime('d/m/Y H:i')->sortable(),
            ])
            ->filters([
                SelectFilter::make('flag_status')->label('Estado')->options([Message::FLAG_PENDING => 'Por rever', Message::FLAG_REVIEWED => 'Revista'])->default(Message::FLAG_PENDING),
            ])
            ->recordActions([
                Action::make('review')->label('Marcar como revista')
                    ->visible(fn (Message $record) => $record->flag_status === Message::FLAG_PENDING)
                    ->schema([Textarea::make('note')->label('Nota (ex.: aviso enviado ao técnico)')->required()->minLength(3)->maxLength(500)])
                    ->action(function (Message $record, array $data) {
                        /** @var User $reviewer */
                        $reviewer = Auth::user();
                        $record->forceFill(['flag_status' => Message::FLAG_REVIEWED, 'reviewed_by' => $reviewer->id, 'reviewed_at' => now(), 'review_note' => $data['note']])->save();
                        app(AuditLogger::class)->record('chat.flag_reviewed', $record, ['note' => $data['note']], $reviewer->id);
                        Notification::make()->success()->title('Mensagem revista')->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListFlaggedMessages::route('/')];
    }
}
