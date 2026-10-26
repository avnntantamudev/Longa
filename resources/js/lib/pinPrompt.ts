import { reactive } from 'vue';

/**
 * Pedido de PIN antes de operações com dinheiro. O cliente da API chama `requestPin()` quando recebe
 * 423 `pin_required`; o componente PinPrompt (no AppLayout) mostra o teclado e resolve a promessa
 * depois de o servidor confirmar o PIN.
 */
export const pinPrompt = reactive({
    open: false,
    resolve: null as (() => void) | null,
    reject: null as ((reason?: unknown) => void) | null,
});

let pending: Promise<void> | null = null;

export function requestPin(): Promise<void> {
    // Vários pedidos ao mesmo tempo partilham o mesmo teclado
    pending ??= new Promise<void>((resolve, reject) => {
        pinPrompt.open = true;
        pinPrompt.resolve = resolve;
        pinPrompt.reject = reject;
    }).finally(() => {
        pinPrompt.open = false;
        pinPrompt.resolve = null;
        pinPrompt.reject = null;
        pending = null;
    });

    return pending;
}
