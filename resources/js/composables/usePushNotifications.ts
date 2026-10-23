import { api } from '@/lib/api';
import { computed, ref } from 'vue';

/**
 * Web Push (VAPID): pede permissão só quando o utilizador carrega no botão, subscreve no service
 * worker e regista a subscrição no servidor.
 *
 * iOS/iPadOS: só funciona com a PWA instalada no ecrã principal (iOS 16.4+). Ver docs/notifications.md.
 */
export type PushState = 'unsupported' | 'needs-install' | 'default' | 'denied' | 'granted' | 'subscribed';

const state = ref<PushState>('default');

function base64UrlToUint8Array(base64Url: string): Uint8Array<ArrayBuffer> {
    const padding = '='.repeat((4 - (base64Url.length % 4)) % 4);
    const raw = atob((base64Url + padding).replace(/-/g, '+').replace(/_/g, '/'));
    const bytes = new Uint8Array(new ArrayBuffer(raw.length));
    for (let i = 0; i < raw.length; i++) {
        bytes[i] = raw.charCodeAt(i);
    }
    return bytes;
}

function isIos(): boolean {
    return /iphone|ipad|ipod/i.test(navigator.userAgent);
}

function isStandalone(): boolean {
    return window.matchMedia('(display-mode: standalone)').matches || (navigator as Navigator & { standalone?: boolean }).standalone === true;
}

function deviceName(): string {
    const ua = navigator.userAgent;
    const os = /Android/.test(ua) ? 'Android' : /iPhone|iPad/.test(ua) ? 'iOS' : /Windows/.test(ua) ? 'Windows' : /Mac OS/.test(ua) ? 'macOS' : 'Dispositivo';
    const browser = /Edg\//.test(ua) ? 'Edge' : /Chrome\//.test(ua) ? 'Chrome' : /Firefox\//.test(ua) ? 'Firefox' : /Safari\//.test(ua) ? 'Safari' : '';
    return browser ? `${browser} · ${os}` : os;
}

async function refresh(): Promise<void> {
    if (!('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window)) {
        state.value = isIos() && !isStandalone() ? 'needs-install' : 'unsupported';
        return;
    }
    if (Notification.permission === 'denied') {
        state.value = 'denied';
        return;
    }
    const registration = await navigator.serviceWorker.getRegistration();
    const subscription = await registration?.pushManager.getSubscription();
    state.value = subscription ? 'subscribed' : Notification.permission === 'granted' ? 'granted' : 'default';
}

async function enable(): Promise<boolean> {
    const key = import.meta.env.VITE_VAPID_PUBLIC_KEY;
    if (!key) {
        console.warn('VITE_VAPID_PUBLIC_KEY em falta: push desativado.');
        return false;
    }

    const permission = await Notification.requestPermission();
    if (permission !== 'granted') {
        await refresh();
        return false;
    }

    const registration = await navigator.serviceWorker.ready;
    const subscription =
        (await registration.pushManager.getSubscription()) ??
        (await registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: base64UrlToUint8Array(key) }));

    const json = subscription.toJSON();
    await api.post('/push-subscriptions', {
        endpoint: json.endpoint,
        keys: json.keys,
        content_encoding: (PushManager as unknown as { supportedContentEncodings?: string[] }).supportedContentEncodings?.[0] ?? 'aes128gcm',
        device: deviceName(),
    });
    state.value = 'subscribed';
    return true;
}

async function disable(): Promise<void> {
    const registration = await navigator.serviceWorker.getRegistration();
    const subscription = await registration?.pushManager.getSubscription();
    if (subscription) {
        await api.delete(`/push-subscriptions?endpoint=${encodeURIComponent(subscription.endpoint)}`).catch(() => undefined);
        await subscription.unsubscribe();
    }
    await refresh();
}

export function usePushNotifications() {
    void refresh();

    return {
        state,
        canEnable: computed(() => state.value === 'default' || state.value === 'granted'),
        enable,
        disable,
        refresh,
    };
}
