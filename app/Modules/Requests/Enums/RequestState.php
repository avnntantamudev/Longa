<?php

declare(strict_types=1);

namespace App\Modules\Requests\Enums;

use App\Support\StateMachine\StateEnum;

/**
 * Estado único do pedido (BISCA, Fase 2.2). Cada mudança fica em service_request_transitions.
 *
 *  created → searching
 *  searching → accepted | no_provider | cancelled
 *  no_provider → searching (nova tentativa) | cancelled
 *  accepted → on_the_way | cancelled
 *  on_the_way → diagnosing (só por orçamento) | in_progress (fixo e hora/pacote) | cancelled
 *  diagnosing → quote_sent
 *  quote_sent → quote_accepted | diagnosing (revisão) | cancelled
 *  quote_accepted → in_progress
 *  in_progress → completed | disputed
 *  completed → paid | disputed
 *  disputed → paid | cancelled (com reembolso)
 *
 * A regra do modo de preço (diagnóstico só nos orçamentos) está em ServiceRequest::transitionAllowed().
 */
enum RequestState: string implements StateEnum
{
    case Created = 'created';
    case Searching = 'searching';
    case NoProvider = 'no_provider';
    case Accepted = 'accepted';
    case OnTheWay = 'on_the_way';
    case Diagnosing = 'diagnosing';
    case QuoteSent = 'quote_sent';
    case QuoteAccepted = 'quote_accepted';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Paid = 'paid';
    case Disputed = 'disputed';
    case Cancelled = 'cancelled';

    public function transitions(): array
    {
        return match ($this) {
            self::Created => [self::Searching],
            self::Searching => [self::Accepted, self::NoProvider, self::Cancelled],
            self::NoProvider => [self::Searching, self::Cancelled],
            self::Accepted => [self::OnTheWay, self::Cancelled],
            self::OnTheWay => [self::Diagnosing, self::InProgress, self::Cancelled],
            self::Diagnosing => [self::QuoteSent],
            self::QuoteSent => [self::QuoteAccepted, self::Diagnosing, self::Cancelled],
            self::QuoteAccepted => [self::InProgress],
            self::InProgress => [self::Completed, self::Disputed],
            self::Completed => [self::Paid, self::Disputed],
            self::Disputed => [self::Paid, self::Cancelled],
            self::Paid, self::Cancelled => [],
        };
    }

    public function canTransitionTo(StateEnum $to): bool
    {
        return in_array($to, $this->transitions(), true);
    }

    public function isFinal(): bool
    {
        return $this->transitions() === [];
    }

    /**
     * Estados que contam para o limite de pedidos ativos do cliente (ainda sem técnico).
     *
     * @return list<self>
     */
    public static function active(): array
    {
        return [self::Created, self::Searching];
    }

    /**
     * Estados de quando o pedido já não está à procura e ainda não tem técnico ou já foi encerrado sem ele.
     *
     * @return list<self>
     */
    public static function withoutProvider(): array
    {
        return [self::Created, self::Searching, self::NoProvider];
    }

    /**
     * Estados exclusivos dos serviços por orçamento.
     *
     * @return list<self>
     */
    public static function quoteOnly(): array
    {
        return [self::Diagnosing, self::QuoteSent, self::QuoteAccepted];
    }

    public function label(): string
    {
        return match ($this) {
            self::Created => 'Criado',
            self::Searching => 'À procura de técnico',
            self::NoProvider => 'Sem técnico disponível',
            self::Accepted => 'Aceite',
            self::OnTheWay => 'A caminho',
            self::Diagnosing => 'Em diagnóstico',
            self::QuoteSent => 'Orçamento enviado',
            self::QuoteAccepted => 'Orçamento aceite',
            self::InProgress => 'Em execução',
            self::Completed => 'Concluído',
            self::Paid => 'Pago',
            self::Disputed => 'Em disputa',
            self::Cancelled => 'Cancelado',
        };
    }
}
