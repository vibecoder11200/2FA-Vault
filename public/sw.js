// Service Worker for 2FA-Vault PWA (E4/E7 rewrite)
//
// Security rules enforced by this worker:
//  - /api/ responses are NEVER cached (they can carry plaintext OTPs). API
//    requests are network-only; on failure a clean offline JSON is returned.
//  - The vault key lives in memory only (never in CacheStorage) and is wiped
//    by the 5-minute inactivity auto-lock or CLEAR_VAULT_KEY.
//  - skipWaiting runs ONLY when the user consents (SKIP_WAITING message from
//    the UpdatePrompt) — no unconditional takeover, no stale-build trap.
//
// Path handling is subdirectory-safe: every URL is derived from the SW scope
// (this file is served from the app base), never from hardcoded '/'.

const DEBUG = new URLSearchParams(self.location.search).has('sw-debug');
const log = (...args) => { if (DEBUG) console.log('[SW]', ...args); };

// Populated at install from the build-generated precachelist.json
let buildId = 'unknown';
let shellFiles = [];

const scopeUrl = new URL(self.registration.scope);
const base = scopeUrl.pathname.replace(/\/$/, ''); // '' for root, '/sub' otherwise
const abs = (p) => new URL((base + p).replace(/\/{2,}/g, '/'), scopeUrl.origin).href;

const PRECACHE_LIST_URL = () => abs('/build/precachelist.json');
const shellCacheName = () => `2fa-vault-shell-${buildId}`;

// In-memory key storage for offline OTP generation (never persisted)
let vaultKey = null;
let encryptedAccounts = [];

// ---------- Install: precache the app shell from the build manifest ----------
self.addEventListener('install', (event) => {
    event.waitUntil(
        (async () => {
            try {
                const res = await fetch(PRECACHE_LIST_URL(), { cache: 'no-store' });
                if (!res.ok) throw new Error('precachelist fetch failed: ' + res.status);
                const manifest = await res.json();
                buildId = manifest.version || String(Date.now());
                shellFiles = (manifest.assets || []).map(p => abs(p));
                const cache = await caches.open(shellCacheName());
                // addAll is all-or-nothing; add individually so one 404 cannot
                // abort the whole install (E7 lesson).
                await Promise.all(shellFiles.map(url => cache.add(url).catch(e => log('skip', url, e.message))));
                log('installed, build', buildId, shellFiles.length, 'assets');
            } catch (error) {
                // No precachelist (e.g. fresh dev checkout) — install empty;
                // runtime caching will still populate the shell.
                buildId = 'fallback-' + Date.now();
                log('install without precachelist', error);
            }
            // Intentionally NOT calling skipWaiting() here — activation waits
            // for user consent via the UpdatePrompt.
        })()
    );
});

// ---------- Activate: purge stale caches ----------
self.addEventListener('activate', (event) => {
    event.waitUntil(
        (async () => {
            const keep = shellCacheName();
            const names = await caches.keys();
            await Promise.all(names.map(n => (n.startsWith('2fa-vault-') && n !== keep) ? caches.delete(n) : null));
            await self.clients.claim();
            log('activated, build', buildId);
        })()
    );
});

// ---------- Fetch ----------
self.addEventListener('fetch', (event) => {
    const { request } = event;
    if (request.method !== 'GET') return;

    const url = new URL(request.url);
    if (url.origin !== scopeUrl.origin) return;

    const pathname = url.pathname;

    // API + auth surfaces: network-only. Never cached, never served from cache.
    if (pathname.startsWith(base + '/api/') || pathname.startsWith(base + '/oauth') || pathname === base + '/refresh-csrf') {
        event.respondWith(
            fetch(request).catch(() => new Response(
                JSON.stringify({ message: 'offline' }),
                { status: 503, headers: { 'Content-Type': 'application/json' } }
            ))
        );
        return;
    }

    // Navigations: network-first with the cached SPA shell as offline fallback.
    if (request.mode === 'navigate') {
        event.respondWith(
            (async () => {
                try {
                    return await fetch(request);
                } catch {
                    const cache = await caches.open(shellCacheName());
                    const shell = (await cache.match(abs('/'))) || (await cache.match(abs('/index.html')));
                    if (shell) return shell;
                    return new Response('Offline', { status: 503, headers: { 'Content-Type': 'text/plain' } });
                }
            })()
        );
        return;
    }

    // Static assets (hashed /build/*, icons, fonts): cache-first, revalidate
    // in background. /build/ URLs are content-hashed so this is safe.
    if (pathname.startsWith(base + '/build/') || pathname.startsWith(base + '/icons/') || /\.(css|js|woff2?|png|svg|json)$/.test(pathname)) {
        event.respondWith(
            (async () => {
                const cache = await caches.open(shellCacheName());
                const cached = await cache.match(request);
                if (cached) {
                    fetch(request).then(res => { if (res && res.ok) cache.put(request, res.clone()); }).catch(() => {});
                    return cached;
                }
                try {
                    const res = await fetch(request);
                    if (res && res.ok) cache.put(request, res.clone());
                    return res;
                } catch {
                    return new Response('', { status: 504 });
                }
            })()
        );
    }
    // Everything else: straight to network (no handler installed).
});

// ---------- Background Sync (offline mutation queue) ----------
self.addEventListener('sync', (event) => {
    if (event.tag === 'vault-sync') {
        event.waitUntil(processSyncQueue());
    }
});

const SYNC_DB_NAME    = '2fauth-offline';
const SYNC_DB_VERSION = 1;
const SYNC_STORE_NAME = 'syncQueue';

function openSyncDB() {
    return new Promise((resolve, reject) => {
        const req = indexedDB.open(SYNC_DB_NAME, SYNC_DB_VERSION);
        req.onsuccess = () => resolve(req.result);
        req.onerror   = () => reject(req.error);
        req.onupgradeneeded = () => {};
    });
}

async function processSyncQueue() {
    let db;
    try { db = await openSyncDB(); } catch { return; }

    const pending = await new Promise((resolve, reject) => {
        const tx  = db.transaction([SYNC_STORE_NAME], 'readonly');
        const req = tx.objectStore(SYNC_STORE_NAME).getAll();
        req.onsuccess = () => resolve((req.result || []).filter(i => i.status === 'pending'));
        req.onerror   = () => reject(req.error);
    }).catch(() => []);

    let processed = 0;
    for (const item of pending) {
        const key = item.id ?? item.timestamp;
        try {
            await _updateSyncItem(db, key, { status: 'syncing' });
            const ok = await _executeSyncOp(item);
            if (ok) {
                await _deleteSyncItem(db, key);
                processed++;
            } else {
                const retries = (item.retryCount ?? 0) + 1;
                await _updateSyncItem(db, key, { retryCount: retries, status: retries >= (item.maxRetries ?? 3) ? 'failed' : 'pending' });
            }
        } catch {
            const retries = (item.retryCount ?? 0) + 1;
            await _updateSyncItem(db, key, { retryCount: retries, status: retries >= (item.maxRetries ?? 3) ? 'failed' : 'pending' }).catch(() => {});
        }
    }

    const clients = await self.clients.matchAll({ type: 'window' });
    clients.forEach(c => c.postMessage({ type: 'SYNC_COMPLETE', processed, remaining: pending.length - processed }));
}

function _updateSyncItem(db, key, updates) {
    return new Promise((resolve, reject) => {
        const tx    = db.transaction([SYNC_STORE_NAME], 'readwrite');
        const store = tx.objectStore(SYNC_STORE_NAME);
        const get   = store.get(key);
        get.onsuccess = () => {
            if (!get.result) return resolve();
            const put = store.put({ ...get.result, ...updates }, key);
            put.onsuccess = () => resolve();
            put.onerror   = () => reject(put.error);
        };
        get.onerror = () => reject(get.error);
    });
}

function _deleteSyncItem(db, key) {
    return new Promise((resolve, reject) => {
        const tx  = db.transaction([SYNC_STORE_NAME], 'readwrite');
        const req = tx.objectStore(SYNC_STORE_NAME).delete(key);
        req.onsuccess = () => resolve();
        req.onerror   = () => reject(req.error);
    });
}

async function _executeSyncOp(item) {
    const headers = { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
    const opts    = (method, body) => ({ method, headers, credentials: 'include', ...(body ? { body: JSON.stringify(body) } : {}) });
    const api = (p) => abs('/api/v1' + p);
    try {
        let res;
        switch (item.action) {
            case 'CREATE_ACCOUNT':  res = await fetch(api('/twofaccounts'), opts('POST', item.data)); break;
            case 'UPDATE_ACCOUNT':  res = await fetch(api('/twofaccounts/' + item.data.id), opts('PUT', item.data)); break;
            case 'DELETE_ACCOUNT':  res = await fetch(api('/twofaccounts/' + item.data.id), opts('DELETE')); break;
            case 'UPDATE_COUNTER':  res = await fetch(api('/twofaccounts/' + item.data.id + '/counter'), opts('PATCH', { counter: item.data.counter })); break;
            default: return true; // Unknown — discard
        }
        return res.ok;
    } catch { return false; }
}

// ---------- Client messages (vault key protocol, consented updates) ----------
self.addEventListener('message', (event) => {
    if (event.data && event.data.type === 'SKIP_WAITING') {
        self.skipWaiting();
        return;
    }

    if (event.data && event.data.type === 'SAVE_VAULT_KEY') {
        vaultKey = event.data.key;
        resetAutoLockTimeout();
        return;
    }

    if (event.data && event.data.type === 'CLEAR_VAULT_KEY') {
        vaultKey = null;
        encryptedAccounts = [];
        if (autoLockTimeout) {
            clearTimeout(autoLockTimeout);
            autoLockTimeout = null;
        }
        return;
    }

    if (event.data && event.data.type === 'SAVE_ENCRYPTED_ACCOUNTS') {
        encryptedAccounts = event.data.accounts || [];
        return;
    }

    if (event.data && event.data.type === 'GENERATE_TOTP') {
        event.waitUntil(handleGenerateTotp(event));
        return;
    }

    if (event.data && event.data.type === 'HEARTBEAT') {
        resetAutoLockTimeout();
        return;
    }

    if (event.data && event.data.type === 'PROCESS_SYNC_QUEUE') {
        event.waitUntil(processSyncQueue());
    }
});

// Auto-lock timeout (5 minutes of inactivity) — memory-only key
let autoLockTimeout = null;

function resetAutoLockTimeout() {
    if (autoLockTimeout) {
        clearTimeout(autoLockTimeout);
    }

    autoLockTimeout = setTimeout(() => {
        vaultKey = null;
        encryptedAccounts = [];
        notifyClients('VAULT_LOCKED');
    }, 5 * 60 * 1000);
}

function notifyClients(type) {
    self.clients.matchAll().then(clients => {
        clients.forEach(client => {
            client.postMessage({ type });
        });
    });
}

async function handleGenerateTotp(event) {
    const { accountId } = event.data;
    const account = encryptedAccounts.find(acc => acc.id === accountId);
    const port = event.ports[0];

    if (!port) {
        return;
    }

    if (!account || !account.secret || !vaultKey) {
        port.postMessage({ type: 'TOTP_RESULT', accountId, totp: null, error: 'Account not found or vault locked' });
        return;
    }

    try {
        const secret = await getAccountSecret(account);
        const totp = await generateTOTP(secret, account);
        port.postMessage({ type: 'TOTP_RESULT', accountId, totp, error: null });
    } catch (error) {
        port.postMessage({ type: 'TOTP_RESULT', accountId, totp: null, error: error.message });
    }
}

async function getAccountSecret(account) {
    if (typeof account.secret !== 'string' || !account.secret.startsWith('{')) {
        return account.secret;
    }

    const encryptedData = JSON.parse(account.secret);
    return decryptSecret(encryptedData, vaultKey);
}

async function decryptSecret(encryptedData, key) {
    const ciphertext = base64ToBytes(encryptedData.ciphertext);
    const iv = base64ToBytes(encryptedData.iv);
    const authTag = base64ToBytes(encryptedData.authTag);
    const combined = new Uint8Array(ciphertext.length + authTag.length);
    combined.set(ciphertext);
    combined.set(authTag, ciphertext.length);

    const plaintext = await crypto.subtle.decrypt(
        { name: 'AES-GCM', iv, tagLength: 128 },
        key,
        combined
    );

    return new TextDecoder().decode(plaintext);
}

async function generateTOTP(secret, account) {
    const key = base32Decode(secret);
    const counter = Math.floor(Math.floor(Date.now() / 1000) / (account.period || 30));
    const counterBytes = counterToBytes(counter);
    const algorithm = normalizeHashAlgorithm(account.algorithm || 'SHA1');
    const cryptoKey = await crypto.subtle.importKey('raw', key, { name: 'HMAC', hash: algorithm }, false, ['sign']);
    const signature = await crypto.subtle.sign({ name: 'HMAC', hash: algorithm }, cryptoKey, counterBytes);
    const hmac = new Uint8Array(signature);
    const offset = hmac[hmac.length - 1] & 0x0f;
    const code = (
        ((hmac[offset] & 0x7f) << 24) |
        ((hmac[offset + 1] & 0xff) << 16) |
        ((hmac[offset + 2] & 0xff) << 8) |
        (hmac[offset + 3] & 0xff)
    );
    const digits = account.digits || 6;

    return (code % (10 ** digits)).toString().padStart(digits, '0');
}

function normalizeHashAlgorithm(algorithm) {
    const normalized = algorithm.toUpperCase().replace('SHA', 'SHA-');

    if (!['SHA-1', 'SHA-256', 'SHA-512'].includes(normalized)) {
        throw new Error('Unsupported offline TOTP algorithm: ' + algorithm);
    }

    return normalized;
}

function counterToBytes(counter) {
    const bytes = new Uint8Array(8);
    let value = BigInt(counter);

    for (let i = 7; i >= 0; i--) {
        bytes[i] = Number(value & 0xffn);
        value >>= 8n;
    }

    return bytes;
}

function base32Decode(str) {
    const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    const normalized = str.toUpperCase().replace(/=+$/g, '').replace(/\s+/g, '');
    let bits = 0;
    let value = 0;
    const output = [];

    for (let i = 0; i < normalized.length; i++) {
        const val = alphabet.indexOf(normalized[i]);
        if (val === -1) continue;
        value = (value << 5) | val;
        bits += 5;
        if (bits >= 8) {
            output.push((value >>> (bits - 8)) & 0xff);
            bits -= 8;
        }
    }

    return new Uint8Array(output);
}

function base64ToBytes(base64) {
    const binString = atob(base64);
    return Uint8Array.from(binString, char => char.charCodeAt(0));
}
