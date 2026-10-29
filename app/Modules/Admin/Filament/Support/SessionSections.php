<?php

declare(strict_types=1);

namespace App\Modules\Admin\Filament\Support;

use App\Modules\Requests\Enums\RequestState;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;

/**
 * Blocos de informação de um serviço reutilizados nas páginas do backoffice.
 * $prefix permite usar os mesmos blocos a partir de uma disputa ("session.").
 */
class SessionSections
{
    public static function details(string $prefix = ''): Section
    {
        return Section::make('Serviço')
            ->columns(3)
            ->schema([
                TextEntry::make($prefix.'title')->label('Título'),
                TextEntry::make($prefix.'state')->label('Estado')->badge()
                    ->formatStateUsing(fn (RequestState $state) => $state->label()),
                TextEntry::make($prefix.'amount')->label('Valor')->formatStateUsing(fn (int $state) => self::kz($state)),
                TextEntry::make($prefix.'client.name')->label('Cliente')->placeholder('—'),
                TextEntry::make($prefix.'provider.user.name')->label('Técnico')->placeholder('—'),
                TextEntry::make($prefix.'payment_method')->label('Pagamento')
                    ->formatStateUsing(fn (string $state) => $state === 'cash' ? 'Em mão' : 'Eletrónico'),
                TextEntry::make($prefix.'scheduled_at')->label('Agendada para')->dateTime('d/m/Y H:i'),
                TextEntry::make($prefix.'started_at')->label('Iniciada')->dateTime('d/m/Y H:i')->placeholder('—'),
                TextEntry::make($prefix.'ended_at')->label('Terminada')->dateTime('d/m/Y H:i')->placeholder('—'),
                TextEntry::make($prefix.'escrowHold.state')->label('Retenção')->badge()->placeholder('Sem retenção'),
            ]);
    }

    public static function timeline(string $prefix = ''): Section
    {
        return Section::make('Linha do tempo')
            ->collapsible()
            ->schema([
                RepeatableEntry::make($prefix.'events')
                    ->hiddenLabel()
                    ->columns(4)
                    ->schema([
                        TextEntry::make('created_at')->label('Quando')->dateTime('d/m/Y H:i:s'),
                        TextEntry::make('type')->label('Evento'),
                        TextEntry::make('to_state')->label('Estado')->placeholder('—')
                            ->formatStateUsing(fn (?string $state, $record) => trim(($record->from_state ? $record->from_state.' → ' : '').$state)),
                        TextEntry::make('actor_id')->label('Por')->placeholder('Sistema')
                            ->formatStateUsing(fn (?int $state) => $state === null ? 'Sistema' : "Utilizador #{$state}"),
                    ]),
            ]);
    }

    public static function ledger(string $prefix = ''): Section
    {
        return Section::make('Movimentos no ledger')
            ->collapsible()
            ->schema([
                RepeatableEntry::make($prefix.'ledgerTransactions')
                    ->hiddenLabel()
                    ->columns(3)
                    ->schema([
                        TextEntry::make('created_at')->label('Quando')->dateTime('d/m/Y H:i:s'),
                        TextEntry::make('type')->label('Tipo')->badge(),
                        TextEntry::make('entries')->label('Lançamentos')->listWithLineBreaks()
                            ->state(fn ($record) => $record->entries->map(fn ($e) => $e->account->code.': '.($e->amount > 0 ? '+' : '').number_format($e->amount, 0, ',', '.'))->all()),
                    ]),
            ]);
    }

    public static function kz(int $amount): string
    {
        return number_format($amount, 0, ',', '.').' Kz';
    }
}
