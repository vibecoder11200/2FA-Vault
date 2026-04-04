// Service Worker for 2FA-Vault PWA
const CACHE_VERSION = 'v1';
const CACHE_NAME = `2fa-vault-${CACHE_VERSION}`;
const APP_SHELL_CACHE = `app-shell-${CACHE_VERSION}`;

// Files to cache for offline functionality
const APP_SHELL_FILES = [
  '/',
  '/css/app.css',
  '/js/app.js',
  '/manifest.json',
  '/icons/pwa-192x192.png',
  '/icons/pwa-512x512.png'
];

// Install event - pre-cache app shell
self.addEventListener('install', (event) => {
  console.log('[Service Worker] Installing...');
  
  event.waitUntil(
    caches.open(APP_SHELL_CACHE)
      .then((cache) => {
        console.log('[Service Worker] Caching app shell');
        return cache.addAll(APP_SHELL_FILES);
      })
      .then(() => {
        console.log('[Service Worker] App shell cached successfully');
        return self.skipWaiting();
      })
      .catch((error) => {
        console.error('[Service Worker] Cache failed:', error);
      })
  );
});

// Activate event - clean old caches
self.addEventListener('activate', (event) => {
  console.log('[Service Worker] Activating...');
  
  event.waitUntil(
    caches.keys()
      .then((cacheNames) => {
        return Promise.all(
          cacheNames.map((cacheName) => {
            if (cacheName !== APP_SHELL_CACHE && cacheName !== CACHE_NAME) {
              console.log('[Service Worker] Deleting old cache:', cacheName);
              return caches.delete(cacheName);
            }
          })
        );
      })
      .then(() => {
        console.log('[Service Worker] Activated successfully');
        return self.clients.claim();
      })
  );
});

// Fetch event - route-based caching strategy
self.addEventListener('fetch', (event) => {
  const { request } = event;
  const url = new URL(request.url);

  // Skip cross-origin requests
  if (url.origin !== location.origin) {
    return;
  }

  // API requests - network-first strategy
  if (url.pathname.startsWith('/api/')) {
    event.respondWith(
      fetch(request)
        .then((response) => {
          // Clone the response before caching
          const responseToCache = response.clone();
          caches.open(CACHE_NAME).then((cache) => {
            cache.put(request, responseToCache);
          });
          return response;
        })
        .catch(() => {
          // Fallback to cache if network fails
          return caches.match(request);
        })
    );
    return;
  }

  // App shell - cache-first strategy
  event.respondWith(
    caches.match(request)
      .then((cachedResponse) => {
        if (cachedResponse) {
          // Return cached version and update cache in background
          fetch(request).then((response) => {
            caches.open(APP_SHELL_CACHE).then((cache) => {
              cache.put(request, response);
            });
          }).catch(() => {
            // Ignore network errors when updating cache
          });
          return cachedResponse;
        }

        // Not in cache - fetch from network
        return fetch(request)
          .then((response) => {
            // Cache static assets
            if (request.method === 'GET' && 
                (request.url.endsWith('.css') || 
                 request.url.endsWith('.js') || 
                 request.url.endsWith('.woff') || 
                 request.url.endsWith('.woff2'))) {
              const responseToCache = response.clone();
              caches.open(APP_SHELL_CACHE).then((cache) => {
                cache.put(request, responseToCache);
              });
            }
            return response;
          });
      })
  );
});

// Handle messages from clients
self.addEventListener('message', (event) => {
  if (event.data && event.data.type === 'SKIP_WAITING') {
    self.skipWaiting();
  }
});
