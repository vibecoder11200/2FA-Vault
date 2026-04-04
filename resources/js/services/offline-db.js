/**
 * Offline Database Service - IndexedDB for offline data storage
 * Stores encrypted account data and user settings for offline access
 */

const DB_NAME = '2fauth-vault';
const DB_VERSION = 1;

const STORES = {
  ACCOUNTS: 'accounts',
  SETTINGS: 'settings',
  SYNC_QUEUE: 'syncQueue'
};

class OfflineDb {
  constructor() {
    this.db = null;
    this.isReady = false;
  }

  /**
   * Initialize IndexedDB
   */
  async init() {
    if (this.isReady) return this.db;

    return new Promise((resolve, reject) => {
      const request = indexedDB.open(DB_NAME, DB_VERSION);

      request.onerror = () => {
        console.error('[OfflineDB] Failed to open database:', request.error);
        reject(request.error);
      };

      request.onsuccess = () => {
        this.db = request.result;
        this.isReady = true;
        console.log('[OfflineDB] Database opened successfully');
        resolve(this.db);
      };

      request.onupgradeneeded = (event) => {
        console.log('[OfflineDB] Upgrading database...');
        const db = event.target.result;

        // Create accounts store
        if (!db.objectStoreNames.contains(STORES.ACCOUNTS)) {
          const accountsStore = db.createObjectStore(STORES.ACCOUNTS, { 
            keyPath: 'id' 
          });
          accountsStore.createIndex('service', 'service', { unique: false });
          accountsStore.createIndex('updatedAt', 'updatedAt', { unique: false });
          console.log('[OfflineDB] Created accounts store');
        }

        // Create settings store
        if (!db.objectStoreNames.contains(STORES.SETTINGS)) {
          db.createObjectStore(STORES.SETTINGS, { keyPath: 'key' });
          console.log('[OfflineDB] Created settings store');
        }

        // Create sync queue store
        if (!db.objectStoreNames.contains(STORES.SYNC_QUEUE)) {
          const syncStore = db.createObjectStore(STORES.SYNC_QUEUE, { 
            keyPath: 'id', 
            autoIncrement: true 
          });
          syncStore.createIndex('timestamp', 'timestamp', { unique: false });
          console.log('[OfflineDB] Created sync queue store');
        }
      };
    });
  }

  /**
   * Ensure database is initialized
   */
  async ensureReady() {
    if (!this.isReady) {
      await this.init();
    }
    return this.db;
  }

  /**
   * Save accounts to offline storage (encrypted)
   * @param {Array} accounts - Array of encrypted account objects
   */
  async saveAccounts(accounts) {
    const db = await this.ensureReady();

    return new Promise((resolve, reject) => {
      const transaction = db.transaction([STORES.ACCOUNTS], 'readwrite');
      const store = transaction.objectStore(STORES.ACCOUNTS);

      // Clear existing accounts first
      store.clear();

      // Add all accounts
      accounts.forEach(account => {
        store.put({
          ...account,
          cachedAt: Date.now()
        });
      });

      transaction.oncomplete = () => {
        console.log('[OfflineDB] Saved', accounts.length, 'accounts');
        resolve();
      };

      transaction.onerror = () => {
        console.error('[OfflineDB] Failed to save accounts:', transaction.error);
        reject(transaction.error);
      };
    });
  }

  /**
   * Get all cached accounts
   */
  async getAccounts() {
    const db = await this.ensureReady();

    return new Promise((resolve, reject) => {
      const transaction = db.transaction([STORES.ACCOUNTS], 'readonly');
      const store = transaction.objectStore(STORES.ACCOUNTS);
      const request = store.getAll();

      request.onsuccess = () => {
        console.log('[OfflineDB] Retrieved', request.result.length, 'accounts');
        resolve(request.result);
      };

      request.onerror = () => {
        console.error('[OfflineDB] Failed to get accounts:', request.error);
        reject(request.error);
      };
    });
  }

  /**
   * Get single account by ID
   */
  async getAccount(id) {
    const db = await this.ensureReady();

    return new Promise((resolve, reject) => {
      const transaction = db.transaction([STORES.ACCOUNTS], 'readonly');
      const store = transaction.objectStore(STORES.ACCOUNTS);
      const request = store.get(id);

      request.onsuccess = () => {
        resolve(request.result);
      };

      request.onerror = () => {
        console.error('[OfflineDB] Failed to get account:', request.error);
        reject(request.error);
      };
    });
  }

  /**
   * Save a single setting
   */
  async saveSetting(key, value) {
    const db = await this.ensureReady();

    return new Promise((resolve, reject) => {
      const transaction = db.transaction([STORES.SETTINGS], 'readwrite');
      const store = transaction.objectStore(STORES.SETTINGS);
      const request = store.put({ key, value, updatedAt: Date.now() });

      request.onsuccess = () => {
        console.log('[OfflineDB] Saved setting:', key);
        resolve();
      };

      request.onerror = () => {
        console.error('[OfflineDB] Failed to save setting:', request.error);
        reject(request.error);
      };
    });
  }

  /**
   * Get a single setting
   */
  async getSetting(key, defaultValue = null) {
    const db = await this.ensureReady();

    return new Promise((resolve, reject) => {
      const transaction = db.transaction([STORES.SETTINGS], 'readonly');
      const store = transaction.objectStore(STORES.SETTINGS);
      const request = store.get(key);

      request.onsuccess = () => {
        const result = request.result;
        resolve(result ? result.value : defaultValue);
      };

      request.onerror = () => {
        console.error('[OfflineDB] Failed to get setting:', request.error);
        reject(request.error);
      };
    });
  }

  /**
   * Get all settings
   */
  async getAllSettings() {
    const db = await this.ensureReady();

    return new Promise((resolve, reject) => {
      const transaction = db.transaction([STORES.SETTINGS], 'readonly');
      const store = transaction.objectStore(STORES.SETTINGS);
      const request = store.getAll();

      request.onsuccess = () => {
        const settings = {};
        request.result.forEach(item => {
          settings[item.key] = item.value;
        });
        resolve(settings);
      };

      request.onerror = () => {
        console.error('[OfflineDB] Failed to get settings:', request.error);
        reject(request.error);
      };
    });
  }

  /**
   * Queue an action to sync when back online
   */
  async queueSync(action, data) {
    const db = await this.ensureReady();

    return new Promise((resolve, reject) => {
      const transaction = db.transaction([STORES.SYNC_QUEUE], 'readwrite');
      const store = transaction.objectStore(STORES.SYNC_QUEUE);
      const request = store.add({
        action,
        data,
        timestamp: Date.now()
      });

      request.onsuccess = () => {
        console.log('[OfflineDB] Queued sync action:', action);
        resolve(request.result);
      };

      request.onerror = () => {
        console.error('[OfflineDB] Failed to queue sync:', request.error);
        reject(request.error);
      };
    });
  }

  /**
   * Get all queued sync actions
   */
  async getSyncQueue() {
    const db = await this.ensureReady();

    return new Promise((resolve, reject) => {
      const transaction = db.transaction([STORES.SYNC_QUEUE], 'readonly');
      const store = transaction.objectStore(STORES.SYNC_QUEUE);
      const request = store.getAll();

      request.onsuccess = () => {
        resolve(request.result);
      };

      request.onerror = () => {
        console.error('[OfflineDB] Failed to get sync queue:', request.error);
        reject(request.error);
      };
    });
  }

  /**
   * Clear sync queue after successful sync
   */
  async clearSyncQueue() {
    const db = await this.ensureReady();

    return new Promise((resolve, reject) => {
      const transaction = db.transaction([STORES.SYNC_QUEUE], 'readwrite');
      const store = transaction.objectStore(STORES.SYNC_QUEUE);
      const request = store.clear();

      request.onsuccess = () => {
        console.log('[OfflineDB] Sync queue cleared');
        resolve();
      };

      request.onerror = () => {
        console.error('[OfflineDB] Failed to clear sync queue:', request.error);
        reject(request.error);
      };
    });
  }

  /**
   * Clear all offline data
   */
  async clearAll() {
    const db = await this.ensureReady();

    return new Promise((resolve, reject) => {
      const transaction = db.transaction(
        [STORES.ACCOUNTS, STORES.SETTINGS, STORES.SYNC_QUEUE], 
        'readwrite'
      );

      transaction.objectStore(STORES.ACCOUNTS).clear();
      transaction.objectStore(STORES.SETTINGS).clear();
      transaction.objectStore(STORES.SYNC_QUEUE).clear();

      transaction.oncomplete = () => {
        console.log('[OfflineDB] All data cleared');
        resolve();
      };

      transaction.onerror = () => {
        console.error('[OfflineDB] Failed to clear data:', transaction.error);
        reject(transaction.error);
      };
    });
  }

  /**
   * Get database stats
   */
  async getStats() {
    const db = await this.ensureReady();

    const stats = {};

    for (const storeName of Object.values(STORES)) {
      const transaction = db.transaction([storeName], 'readonly');
      const store = transaction.objectStore(storeName);
      const countRequest = store.count();

      stats[storeName] = await new Promise((resolve) => {
        countRequest.onsuccess = () => resolve(countRequest.result);
      });
    }

    return stats;
  }

  /**
   * Close database connection
   */
  close() {
    if (this.db) {
      this.db.close();
      this.db = null;
      this.isReady = false;
      console.log('[OfflineDB] Database closed');
    }
  }
}

// Export singleton instance
export default new OfflineDb();
