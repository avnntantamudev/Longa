<?php

declare(strict_types=1);

namespace App\Modules\Admin\Filament\Support;

use App\Modules\Requests\Models\Quote;
use App\Modules\Requests\Models\QuoteAddendum;
use App\Modules\Requests\Models\ServiceRequest;
use App\Modules\Requests\Models\ServiceRequestMedia;
use App\Modules\Requests\Models\ServiceRequestTransition;
use App\Modules\Sessions\Models\Message;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\HtmlString;

/**
 * Tudo o que a equipa precisa para decidir uma disputa (BISCA, Fase 3.5): histórico de estados, respostas e fotos
 * do pedido, orçamento aceite, adendas e o chat completo (com o texto original das mensagens sinalizadas).
 * Os ficheiros abrem por links assinados temporários.
 */
final class DisputeEvidence
{
    /**
     * @return list<Section>
     */
    public static function sections(string $prefix = 'session'): array
    {
        $request = fn ($record): ServiceRequest => data_get($record, $prefix);

        return [
            Section::make('Histórico de estados')->collapsible()->schema([
                TextEntry::make('history')->hiddenLabel()->state(fn ($record) => self::history($request($record))),
            ]),
            Section::make('Pedido: respostas e fotos')->collapsible()->schema([
                TextEntry::make('form')->hiddenLabel()->state(fn ($record) => self::form($request($record)))->placeholder('Sem respostas nem fotos'),
            ]),
            Section::make('Orçamento aceite e adendas')->collapsible()->schema([
                TextEntry::make('quote')->hiddenLabel()->state(fn ($record) => self::quote($request($record)))->placeholder('Sem orçamento (preço fixo ou por hora)'),
            ]),
            Section::make('Chat')->collapsible()->schema([
                TextEntry::make('chat')->hiddenLabel()->state(fn ($record) => self::chat($request($record)))->placeholder('Sem mensagens'),
            ]),
        ];
    }

    private static function history(ServiceRequest $request): HtmlString
    {
        $rows = $request->transitions()->with('actor')->get()->map(fn (ServiceRequestTransition $t) => e($t->created_at->format('d/m/Y H:i'))
            .' · '.e($t->from_state?->label() ?? '—').' → <strong>'.e($t->to_state->label()).'</strong>'
            .' · '.e($t->actor->name ?? 'Sistema').($t->reason ? ' ('.e($t->reason).')' : ''));

        return new HtmlString($rows->implode('<br>'));
    }

    private static function form(ServiceRequest $request): ?HtmlString
    {
        $summary = $request->formSummary();
        $lines = array_map(fn (array $a) => '<strong>'.e($a['label']).':</strong> '.e((string) $a['value']), $summary['answers']);
        $minutes = (int) config('longa.requests.media.link_minutes');
        foreach ($request->media as $i => $media) {
            /** @var ServiceRequestMedia $media */
            $url = URL::temporarySignedRoute('requests.media', now()->addMinutes($minutes), ['media' => $media->id], absolute: false);
            $lines[] = '<a class="underline" target="_blank" rel="noopener" href="'.e($url).'">'.($media->kind === 'video' ? 'Vídeo' : 'Foto '.($i + 1)).'</a>';
        }

        return $lines === [] ? null : new HtmlString(implode('<br>', $lines));
    }

    private static function quote(ServiceRequest $request): ?HtmlString
    {
        /** @var Quote|null $quote */
        $quote = $request->quotes()->where('status', Quote::ACCEPTED)->first();
        if ($quote === null) {
            return null;
        }

        $lines = [
            '<strong>Orçamento (revisão '.$quote->revision.'):</strong> mão de obra '.e(SessionSections::kz($quote->labour_amount))
                .' + materiais '.e(SessionSections::kz($quote->materials_amount)).' = '.e(SessionSections::kz($quote->total))
                .'; taxa de visita descontada '.e(SessionSections::kz($quote->visit_fee_deducted)).'; prazo '.$quote->execution_days.' dia(s)',
        ];
        if ($quote->notes) {
            $lines[] = e($quote->notes);
        }
        foreach ($request->addenda()->orderBy('id')->get() as $addendum) {
            /** @var QuoteAddendum $addendum */
            $lines[] = '<strong>Adenda #'.$addendum->id.':</strong> '.e($addendum->description).' · '.e(SessionSections::kz($addendum->amount))
                .' ('.$addendum->percent_of_quote.'% do orçamento) · '.e($addendum->label());
        }

        return new HtmlString(implode('<br>', $lines));
    }

    private static function chat(ServiceRequest $request): ?HtmlString
    {
        $messages = Message::query()->whereHas('conversation', fn ($q) => $q->where('service_request_id', $request->id))->with('sender')->orderBy('id')->get();
        if ($messages->isEmpty()) {
            return null;
        }

        $minutes = (int) config('longa.chat.attachment_link_minutes');
        $rows = $messages->map(function (Message $m) use ($minutes) {
            $text = $m->flagged ? '<mark>'.e((string) $m->flagged_body).'</mark> <em>(sinalizada)</em>' : e((string) $m->body);
            if ($m->hasAttachment()) {
                $url = URL::temporarySignedRoute('chat.attachment', now()->addMinutes($minutes), ['message' => $m->id], absolute: false);
                $text .= ' <a class="underline" target="_blank" rel="noopener" href="'.e($url).'">'.($m->isPhoto() ? 'foto' : 'PDF').'</a>';
            }

            return e($m->created_at->format('d/m H:i')).' <strong>'.e($m->sender->name ?? '—').':</strong> '.$text;
        });

        return new HtmlString($rows->implode('<br>'));
    }
}
