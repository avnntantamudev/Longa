import type { PendingVisit } from '@inertiajs/core';
import { router } from '@inertiajs/vue3';
import { computed, readonly, ref } from 'vue';

/**
 * Estado global de rede da PWA:
 *  - online/offline do dispositivo;
 *  - estado do pedido em curso: "a enviar", "falhou" (com "tentar de novo").
 *
 * Cobre automaticamente as visitas Inertia. Para outros pedidos (ex.: fetch à API),
 * envolva a chamada com `track(() => fetch(...))`.
 */
export type RequestStatus = 'idle' | 'sending' | 'failed';

const online = ref(typeof navigator === 'undefined' ? true : navigator.onLine);
const status = ref<RequestStatus>('idle');
const failureMessage = ref<string | null>(null);

let retryAction: (() => unknown) | null = null;
let lastVisit: PendingVisit | null = null;
let sendingTimer: ReturnType<typeof setTimeout> | undefined;
let installed = false;

// Evita que a barra "a enviar" pisque em pedidos rápidos
const SENDING_DELAY_MS = 400;

function markSending(): void {
    clearTimeout(sendingTimer);
    sendingTimer = setTimeout(() => {
        if (status.value !== 'failed') {
            status.value = 'sending';
        }
    }, SENDING_DELAY_MS);
}

function markIdle(): void {
    clearTimeout(sendingTimer);
    if (status.value === 'sending') {
        status.value = 'idle';
    }
}

function markFailed(message: string, retry: () => unknown): void {
    clearTimeout(sendingTimer);
    status.value = 'failed';
    failureMessage.value = message;
    retryAction = retry;
}

function failureText(): string {
    return online.value
        ? 'Não foi possível concluir o pedido.'
        : 'Sem ligação à internet. O pedido não foi enviado.';
}

function install(): void {
    if (installed || typeof window === 'undefined') {
        return;
    }
    installed = true;

    window.addEventListener('online', () => (online.value = true));
    window.addEventListener('offline', () => (online.value = false));

    router.on('start', (event) => {
        const visit = event.detail.visit;
        // Pedidos em segundo plano (prefetch, polling) não mostram estado
        if (visit.prefetch) {
            return;
        }
        lastVisit = visit;
        status.value = status.value === 'failed' ? 'idle' : status.value;
        markSending();
    });

    router.on('finish', () => markIdle());

    router.on('networkError', (event) => {
        // Repete a visita que falhou (mesmo URL, método e dados)
        const visit = lastVisit;
        markFailed(failureText(), () =>
            visit ? router.visit(visit.url.href, { method: visit.method, data: visit.data }) : router.reload(),
        );
        console.warn('Falha de rede numa visita Inertia', event.detail.error);
        // Impede o comportamento por defeito (exceção não tratada)
        return false;
    });

    router.on('httpException', (event) => {
        const { status: code } = event.detail.response;
        if (code >= 500 || code === 0) {
            markFailed('O servidor não respondeu como esperado.', () => router.reload());
            return false;
        }
        return undefined;
    });
}

/**
 * Acompanha um pedido que não passa pelo Inertia (ex.: fetch à API).
 * Em caso de falha, "Tentar de novo" volta a executar `request`.
 */
async function track<T>(request: () => Promise<T>): Promise<T> {
    markSending();
    try {
        const result = await request();
        markIdle();
        return result;
    } catch (error) {
        markFailed(failureText(), () => track(request));
        throw error;
    }
}

function retry(): void {
    const action = retryAction;
    dismiss();
    action?.();
}

function dismiss(): void {
    status.value = 'idle';
    failureMessage.value = null;
    retryAction = null;
}

export function useNetworkState() {
    install();

    return {
        online: readonly(online),
        status: readonly(status),
        failureMessage: readonly(failureMessage),
        isSending: computed(() => status.value === 'sending'),
        hasFailed: computed(() => status.value === 'failed'),
        track,
        retry,
        dismiss,
    };
}
