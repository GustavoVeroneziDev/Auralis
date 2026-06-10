// Auralis Service Worker — v1.0
// Minimalista: só existe para habilitar o PWA install prompt.
// Não faz cache agressivo para não interferir com atualizações do sistema.

const CACHE_NAME = 'auralis-v1';

self.addEventListener('install', (event) => {
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(clients.claim());
});

// Fetch: passa tudo direto para a rede (sem cache).
// Quando quiser adicionar cache offline, é aqui que implementa.
self.addEventListener('fetch', (event) => {
    event.respondWith(fetch(event.request));
});