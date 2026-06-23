// Auralis Service Worker — v1.1
// Minimalista: só existe para habilitar o PWA install prompt.
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
