/*
 * Web Push no service worker da LONGA (importado pelo sw.js gerado pelo Workbox).
 * - "push": mostra a notificação enviada pelo servidor (laravel-notification-channels/webpush).
 * - "notificationclick": abre o URL da notificação (ex.: /formador/ofertas/123), reutilizando
 *   uma janela da app já aberta quando existe.
 */

self.addEventListener('push', (event) => {
    if (!event.data) {
        return;
    }

    let payload;
    try {
        payload = event.data.json();
    } catch {
        payload = { title: 'LONGA', body: event.data.text() };
    }

    const { title = 'LONGA', ...options } = payload;
    event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const url = new URL(event.notification.data?.url ?? '/', self.location.origin).href;

    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clients) => {
            for (const client of clients) {
                if (new URL(client.url).origin === self.location.origin && 'focus' in client) {
                    return client.navigate(url).then((c) => (c ?? client).focus());
                }
            }
            return self.clients.openWindow(url);
        }),
    );
});
