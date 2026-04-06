// Service Worker for 2FA-Vault PWA
const CACHE_VERSION = 'v1';
const CACHE_NAME = `2fa-vault-${CACHE_VERSION}`;
const APP_SHELL_CACHE = `app-shell-${CACHE_VERSION}`;

// In-memory key storage for offline OTP generation
let vaultKey = null;
let encryptedAccounts = [];

// Files to cache for offline functionality
const APP_SHELL_FILES = [
  '/',
  '/css/app.css',
  '/js/app.js',
  '/manifest.json',
  '/icons/pwa-48x48.png',
  '/icons/pwa-72x72.png',
  '/icons/pwa-96x96.png',
  '/icons/pwa-128x128.png',
  '/icons/pwa-144x144.png',
  '/icons/pwa-152x152.png',
  '/icons/pwa-192x192.png',
  '/icons/pwa-384x384.png',
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

// Handle messages from clients (for vault key management)
self.addEventListener('message', (event) => {
  if (event.data && event.data.type === 'SKIP_WAITING') {
    self.skipWaiting();
  }

  // Save vault key for offline OTP generation
  if (event.data && event.data.type === 'SAVE_VAULT_KEY') {
    vaultKey = event.data.key;
    console.log('[Service Worker] Vault key saved for offline use');
    resetAutoLockTimeout();
  }

  // Clear vault key (lock vault)
  if (event.data && event.data.type === 'CLEAR_VAULT_KEY') {
    vaultKey = null;
    encryptedAccounts = [];
    console.log('[Service Worker] Vault key cleared');
    if (autoLockTimeout) {
      clearTimeout(autoLockTimeout);
      autoLockTimeout = null;
    }
  }

  // Save encrypted accounts for offline access
  if (event.data && event.data.type === 'SAVE_ENCRYPTED_ACCOUNTS') {
    encryptedAccounts = event.data.accounts;
    console.log('[Service Worker] Saved', encryptedAccounts.length, 'encrypted accounts for offline');
  }

  // Generate TOTP for offline account
  if (event.data && event.data.type === 'GENERATE_TOTP') {
    const { accountId } = event.data;
    const account = encryptedAccounts.find(acc => acc.id === accountId);

    if (account && account.secret && vaultKey) {
      try {
        // For encrypted accounts, the secret is already in JSON format
        let secretData;

        if (typeof account.secret === 'string' && account.secret.startsWith('{')) {
          // Parse encrypted secret JSON
          secretData = JSON.parse(account.secret);

          // Import decrypt function (will be available from crypto.js)
          import('./js/services/crypto.js').then(({ CryptoService }) => {
            const decryptedSecret = CryptoService.decrypt(account.secret, vaultKey);
            const totp = generateTOTP(decryptedSecret, account);

            event.ports[0].postMessage({
              type: 'TOTP_RESULT',
              accountId: accountId,
              totp: totp,
              error: null
            });
          });
        } else {
          // Plaintext secret (should not happen in production with E2EE)
          const totp = generateTOTP(account.secret, account);
          event.ports[0].postMessage({
            type: 'TOTP_RESULT',
            accountId: accountId,
            totp: totp,
            error: null
          });
        }
      } catch (error) {
        event.ports[0].postMessage({
          type: 'TOTP_RESULT',
          accountId: accountId,
          totp: null,
          error: error.message
        });
      }
    } else {
      event.ports[0].postMessage({
        type: 'TOTP_RESULT',
        accountId: accountId,
        totp: null,
        error: 'Account not found or vault locked'
      });
    }
  }

  // Heartbeat to reset auto-lock timeout
  if (event.data && event.data.type === 'HEARTBEAT') {
    resetAutoLockTimeout();
  }
});

// Auto-lock timeout (5 minutes of inactivity)
let autoLockTimeout = null;

function resetAutoLockTimeout() {
  if (autoLockTimeout) {
    clearTimeout(autoLockTimeout);
  }

  autoLockTimeout = setTimeout(() => {
    vaultKey = null;
    encryptedAccounts = [];
    console.log('[Service Worker] Vault auto-locked after 5 minutes of inactivity');
    notifyClients('VAUT_LOCKED');
  }, 5 * 60 * 1000); // 5 minutes
}

function notifyClients(type) {
  self.clients.matchAll().then(clients => {
    clients.forEach(client => {
      client.postMessage({ type });
    });
  });
}

// TOTP Generation Helper (should use otpauth library)
function generateTOTP(secret, account) {
  // This would use the otpauth library in production
  // For now, return a placeholder
  try {
    // Convert base32 secret to buffer
    const key = base32Decode(secret);
    const epoch = Math.floor(Date.now() / 1000);
    const time = Math.floor(epoch / (account.period || 30));
    const counter = Buffer.alloc(8);
    counter.writeBigUInt64BE(BigInt(time));

    // HMAC-SHA1 or HMAC-SHA512
    // Then truncate to 6-8 digits

    // Placeholder return
    return '000000'; // Replace with actual TOTP generation
  } catch (error) {
    console.error('[Service Worker] TOTP generation error:', error);
    return '------';
  }
}

function base32Decode(str) {
  const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
  let bits = 0;
  let value = 0;
  const output = new Uint8Array((str.length * 5 / 8) | 0);

  for (let i = 0; i < str.length; i++) {
    const char = str[i].toUpperCase();
    const val = alphabet.indexOf(char);

    if (val === -1) continue;

    value = (value << 5) | val;
    bits += 5;

    if (bits >= 8) {
      output[(i * 5 / 8) | 0] |= (value >>> (bits - 8));
      bits -= 8;
    }
  }

  return output;
}
