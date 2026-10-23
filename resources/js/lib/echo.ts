import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

/**
 * Ligação ao Reverb (WebSockets), criada só quando é precisa.
 * Teste: chame `listenToPing(console.log)` numa página e no servidor corra `php artisan longa:ping`.
 */
let instance: Echo<'reverb'> | null = null;

export function getEcho(): Echo<'reverb'> | null {
    if (instance) {
        return instance;
    }

    const key = import.meta.env.VITE_REVERB_APP_KEY;
    if (!key) {
        return null;
    }

    window.Pusher = Pusher;
    const scheme = import.meta.env.VITE_REVERB_SCHEME ?? 'https';
    const port = Number(import.meta.env.VITE_REVERB_PORT ?? (scheme === 'https' ? 443 : 80));

    instance = new Echo({
        broadcaster: 'reverb',
        key,
        wsHost: import.meta.env.VITE_REVERB_HOST ?? window.location.hostname,
        wsPort: port,
        wssPort: port,
        forceTLS: scheme === 'https',
        enabledTransports: ['ws', 'wss'],
    });
    window.Echo = instance;

    return instance;
}

/** Subscreve o canal público de teste "longa.ping". Devolve a função para cancelar. */
export function listenToPing(callback: (payload: { message: string; sent_at: string }) => void): () => void {
    const echo = getEcho();
    if (!echo) {
        console.warn('Reverb não configurado (VITE_REVERB_APP_KEY em falta).');
        return () => {};
    }
    echo.channel('longa.ping').listen('.ping', callback);
    return () => echo.leaveChannel('longa.ping');
}
