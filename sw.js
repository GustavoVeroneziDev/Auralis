// Auralis Service Worker — v1.2
// Habilita o PWA install prompt e recebe/exibe Web Push (contas a vencer etc).
// Não faz cache agressivo para não interferir com atualizações do sistema.

const CACHE_NAME = 'auralis-v1';

self.addEventListener('install', (event) => {
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(clients.claim());
});

// Fetch: navegações vão direto ao servidor (evita erros de promise rejeitado).
// Requisições de assets passam pela rede com fallback gracioso.
self.addEventListener('fetch', (event) => {
    if (event.request.mode === 'navigate') return;
    event.respondWith(
        fetch(event.request).catch(() => new Response('', { status: 503, statusText: 'Offline' }))
    );
});

// Push: exibe a notificação nativa do sistema com os dados enviados pelo servidor.
self.addEventListener('push', (event) => {
    let dados = { title: 'Auralis', body: 'Você tem uma novidade.', url: '/agenda.php' };
    if (event.data) {
        try { dados = Object.assign(dados, event.data.json()); } catch (e) {}
    }

    event.waitUntil(
        self.registration.showNotification(dados.title, {
            body: dados.body,
            icon: '/geral/img/icon-192.png',
            badge: '/geral/img/icon-192.png',
            data: { url: dados.url },
            tag: dados.tag || undefined,
        })
    );
});

// Clique na notificação: foca uma aba já aberta do Auralis ou abre uma nova na URL indicada.
self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const url = (event.notification.data && event.notification.data.url) || '/dashboard.php';

    event.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true }).then((lista) => {
            for (const client of lista) {
                if ('focus' in client) {
                    client.navigate(url);
                    return client.focus();
                }
            }
            if (clients.openWindow) return clients.openWindow(url);
        })
    );
});
