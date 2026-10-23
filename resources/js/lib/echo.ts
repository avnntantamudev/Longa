import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

/**
 * Ligação ao Reverb (WebSockets), criada só quando é precisa.
 * Os canais privados são autorizados em /api/v1/broadcasting/auth com o cookie da sessão
 * (Sanctum SPA) e o cabeçalho X-XSRF-TOKEN.
 * Teste do canal público: `listenToPing(console.log)` e no servidor `php artisan longa:ping`.
 */
let instance: Echo<'reverb'> | null = null;

function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
    return match?.[1] ? decodeURIComponent(match[1]) : '';
}

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
        authorizer: (channel: { name: string }) => ({
            authorize: (socketId: string, callback: (error: Error | null, data: { auth: string; channel_data?: string } | null) => void) => {
                fetch('/api/v1/broadcasting/auth', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-XSRF-TOKEN': xsrfToken(),
                    },
                    body: JSON.stringify({ socket_id: socketId, channel_name: channel.name }),
                })
                    .then(async (response) => {
                        if (!response.ok) {
                            throw new Error(`Canal recusado (${response.status})`);
                        }
                        callback(null, await response.json());
                    })
                    .catch((error: Error) => callback(error, null));
            },
        }),
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
