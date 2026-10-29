/**
 * Estado único do pedido (app/Modules/Requests/Enums/RequestState.php).
 */
export type RequestState =
    | 'created'
    | 'searching'
    | 'no_provider'
    | 'accepted'
    | 'on_the_way'
    | 'diagnosing'
    | 'quote_sent'
    | 'quote_accepted'
    | 'in_progress'
    | 'completed'
    | 'paid'
    | 'disputed'
    | 'cancelled';

const WITHOUT_PROVIDER: RequestState[] = ['created', 'searching', 'no_provider', 'cancelled'];

/** O pedido já tem técnico atribuído (aceite ou mais à frente). */
export function hasProvider(state: RequestState): boolean {
    return !WITHOUT_PROVIDER.includes(state);
}
