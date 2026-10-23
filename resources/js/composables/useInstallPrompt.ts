import { computed, ref } from 'vue';

/**
 * "Adicionar ao ecrã inicial".
 *  - Android/Chrome/Edge: guarda o evento beforeinstallprompt e mostra o diálogo nativo quando pedido.
 *  - iOS/Safari: não há evento; mostra instruções (Partilhar → Adicionar ao ecrã principal).
 * A recusa é lembrada durante 14 dias.
 */
interface BeforeInstallPromptEvent extends Event {
    prompt(): Promise<void>;
    userChoice: Promise<{ outcome: 'accepted' | 'dismissed' }>;
}

const DISMISS_KEY = 'longa:install-dismissed-at';
const DISMISS_DAYS = 14;

const deferredPrompt = ref<BeforeInstallPromptEvent | null>(null);
const installed = ref(false);
const dismissed = ref(readDismissed());
let listening = false;

function readDismissed(): boolean {
    try {
        const at = Number(localStorage.getItem(DISMISS_KEY) ?? 0);
        return at > 0 && Date.now() - at < DISMISS_DAYS * 24 * 60 * 60 * 1000;
    } catch {
        return false;
    }
}

function isStandalone(): boolean {
    return (
        window.matchMedia('(display-mode: standalone)').matches ||
        (navigator as Navigator & { standalone?: boolean }).standalone === true
    );
}

function isIos(): boolean {
    return /iphone|ipad|ipod/i.test(navigator.userAgent) && !/crios|fxios/i.test(navigator.userAgent);
}

function listen(): void {
    if (listening || typeof window === 'undefined') {
        return;
    }
    listening = true;
    installed.value = isStandalone();

    window.addEventListener('beforeinstallprompt', (event) => {
        event.preventDefault();
        deferredPrompt.value = event as BeforeInstallPromptEvent;
    });

    window.addEventListener('appinstalled', () => {
        installed.value = true;
        deferredPrompt.value = null;
    });
}

export function useInstallPrompt() {
    listen();

    const canPrompt = computed(() => deferredPrompt.value !== null);
    const showIosHint = computed(() => !installed.value && !canPrompt.value && isIos());
    const visible = computed(() => !installed.value && !dismissed.value && (canPrompt.value || showIosHint.value));

    async function install(): Promise<void> {
        const event = deferredPrompt.value;
        if (!event) {
            return;
        }
        await event.prompt();
        const { outcome } = await event.userChoice;
        deferredPrompt.value = null;
        if (outcome === 'dismissed') {
            dismiss();
        }
    }

    function dismiss(): void {
        dismissed.value = true;
        try {
            localStorage.setItem(DISMISS_KEY, String(Date.now()));
        } catch {
            // Armazenamento indisponível (modo privado): a recusa vale só para esta sessão
        }
    }

    return { visible, canPrompt, showIosHint, install, dismiss };
}
