/*
 * Clubarr's service worker: only so Clubarr can be added to the home screen
 * as an app. It keeps nothing offline and lets every request go to the
 * server as normal (nothing personal is ever cached here).
 */
self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (event) => event.waitUntil(self.clients.claim()));
self.addEventListener('fetch', () => {});
