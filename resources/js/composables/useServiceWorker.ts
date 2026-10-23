import { useRegisterSW } from 'virtual:pwa-register/vue';

/**
 * Regista o service worker (gerado pelo vite-plugin-pwa em /sw.js) e informa quando há
 * uma nova versão. A atualização só é aplicada quando o utilizador aceita, para não
 * interromper um formulário a meio.
 */
export function useServiceWorker() {
    const { needRefresh, offlineReady, updateServiceWorker } = useRegisterSW({
        immediate: true,
        onRegisteredSW(_url, registration) {
            // Verifica atualizações a cada hora enquanto a app está aberta
            if (registration) {
                setInterval(() => registration.update(), 60 * 60 * 1000);
            }
        },
        onRegisterError(error) {
            console.warn('Falha ao registar o service worker', error);
        },
    });

    return {
        needRefresh,
        offlineReady,
        update: () => updateServiceWorker(true),
        dismiss: () => {
            needRefresh.value = false;
            offlineReady.value = false;
        },
    };
}
