import { getEcho } from '@/lib/echo';
import { onBeforeUnmount, ref } from 'vue';

/**
 * Subscreve eventos de um canal privado enquanto o componente está montado.
 * Se o Reverb não estiver disponível (sem rede, sem configuração), `connected` fica false e a página
 * deve recorrer a atualização periódica (polling) — o servidor continua a ser a fonte de verdade.
 *
 * Ex.: usePrivateChannel(`request.${id}`, { 'request.matched': (e) => ... })
 */
export function usePrivateChannel(name: string, handlers: Record<string, (payload: never) => void>) {
    const connected = ref(false);
    const echo = getEcho();

    if (echo) {
        const channel = echo.private(name);
        for (const [event, handler] of Object.entries(handlers)) {
            // O ponto inicial indica nomes definidos com broadcastAs() no servidor
            channel.listen(`.${event}`, handler as (payload: unknown) => void);
        }
        channel.subscribed(() => (connected.value = true));
        channel.error(() => (connected.value = false));
    }

    onBeforeUnmount(() => echo?.leave(name));

    return { connected };
}

/**
 * Atualização periódica de segurança (para quando o tempo real não chega). Pára ao desmontar.
 */
export function usePolling(callback: () => unknown, intervalMs: number) {
    const timer = setInterval(() => {
        if (document.visibilityState === 'visible') {
            callback();
        }
    }, intervalMs);
    onBeforeUnmount(() => clearInterval(timer));
}
