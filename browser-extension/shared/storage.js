/**
 * Chrome/Firefox extension storage wrapper
 * Handles secure storage for 2FA-Vault extension
 */

class Storage {
  constructor() {
    // Use browser.storage for Firefox, chrome.storage for Chrome
    this.storage = typeof browser !== 'undefined' ? browser.storage : chrome.storage;
  }

  /**
   * Get item from storage
   * @param {string} key - Storage key
   * @returns {Promise<any>} Stored value
   */
  async get(key) {
    return new Promise((resolve, reject) => {
      this.storage.local.get(key, (result) => {
        if (this.storage.runtime.lastError) {
          reject(this.storage.runtime.lastError);
        } else {
          resolve(result[key]);
        }
      });
    });
  }

  /**
   * Set item in storage
   * @param {string} key - Storage key
   * @param {any} value - Value to store
   * @returns {Promise<void>}
   */
  async set(key, value) {
    return new Promise((resolve, reject) => {
      this.storage.local.set({ [key]: value }, () => {
        if (this.storage.runtime.lastError) {
          reject(this.storage.runtime.lastError);
        } else {
          resolve();
        }
      });
    });
  }

  /**
   * Remove item from storage
   * @param {string} key - Storage key
   * @returns {Promise<void>}
   */
  async remove(key) {
    return new Promise((resolve, reject) => {
      this.storage.local.remove(key, () => {
        if (this.storage.runtime.lastError) {
          reject(this.storage.runtime.lastError);
        } else {
          resolve();
        }
      });
    });
  }

  /**
   * Clear all storage
   * @returns {Promise<void>}
   */
  async clear() {
    return new Promise((resolve, reject) => {
      this.storage.local.clear(() => {
        if (this.storage.runtime.lastError) {
          reject(this.storage.runtime.lastError);
        } else {
          resolve();
        }
      });
    });
  }

  // Predefined storage keys
  static KEYS = {
    SERVER_URL: 'serverUrl',
    API_TOKEN: 'apiToken',
    ENCRYPTED_VAULT: 'encryptedVault',
    SALT: 'salt',
    IS_LOCKED: 'isLocked',
    THEME: 'theme',
    AUTO_SYNC_INTERVAL: 'autoSyncInterval',
    LAST_SYNC: 'lastSync',
    SESSION_KEY: 'sessionKey', // Temporary encryption key (cleared on lock)
    PREFERENCES: 'preferences'
  };

  /**
   * Get server URL
   */
  async getServerUrl() {
    return await this.get(Storage.KEYS.SERVER_URL) || 'http://localhost:8000';
  }

  /**
   * Set server URL
   */
  async setServerUrl(url) {
    await this.set(Storage.KEYS.SERVER_URL, url);
  }

  /**
   * Get API token
   */
  async getApiToken() {
    return await this.get(Storage.KEYS.API_TOKEN);
  }

  /**
   * Set API token
   */
  async setApiToken(token) {
    await this.set(Storage.KEYS.API_TOKEN, token);
  }

  /**
   * Get encrypted vault
   */
  async getEncryptedVault() {
    return await this.get(Storage.KEYS.ENCRYPTED_VAULT);
  }

  /**
   * Set encrypted vault
   */
  async setEncryptedVault(vault) {
    await this.set(Storage.KEYS.ENCRYPTED_VAULT, vault);
  }

  /**
   * Check if vault is locked
   */
  async isLocked() {
    const locked = await this.get(Storage.KEYS.IS_LOCKED);
    return locked !== false; // Default to locked
  }

  /**
   * Lock vault (clear session key)
   */
  async lock() {
    await this.remove(Storage.KEYS.SESSION_KEY);
    await this.set(Storage.KEYS.IS_LOCKED, true);
  }

  /**
   * Unlock vault (store session key)
   */
  async unlock(sessionKey) {
    await this.set(Storage.KEYS.SESSION_KEY, sessionKey);
    await this.set(Storage.KEYS.IS_LOCKED, false);
  }

  /**
   * Get session encryption key (only available when unlocked)
   */
  async getSessionKey() {
    return await this.get(Storage.KEYS.SESSION_KEY);
  }

  /**
   * Get theme preference
   */
  async getTheme() {
    return await this.get(Storage.KEYS.THEME) || 'system';
  }

  /**
   * Set theme preference
   */
  async setTheme(theme) {
    await this.set(Storage.KEYS.THEME, theme);
  }

  /**
   * Get auto-sync interval (in minutes)
   */
  async getAutoSyncInterval() {
    return await this.get(Storage.KEYS.AUTO_SYNC_INTERVAL) || 15;
  }

  /**
   * Set auto-sync interval
   */
  async setAutoSyncInterval(interval) {
    await this.set(Storage.KEYS.AUTO_SYNC_INTERVAL, interval);
  }
}

// Export singleton
const storage = new Storage();

if (typeof module !== 'undefined' && module.exports) {
  module.exports = storage;
}
