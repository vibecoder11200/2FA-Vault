/**
 * Offline TOTP Service - Generate TOTP codes completely offline
 * Uses cached encrypted secrets from IndexedDB
 */

import offlineDb from './offline-db.js';
import { decryptSecret } from './crypto.js';

class OfflineTotpService {
  constructor() {
    this.isOfflineMode = !navigator.onLine;
    // B18: derived non-extractable CryptoKeys keyed by salt — the raw master
    // password is NEVER retained; it is used once at unlock to derive a key
    // per cached salt and then discarded.
    this.derivedKeys = new Map();
    this.setupNetworkListener();
  }

  /**
   * Setup network status listener
   */
  setupNetworkListener() {
    window.addEventListener('online', () => {
      this.isOfflineMode = false;
      if (import.meta.env.DEV) console.log('[OfflineTotp] Online mode');
    });

    window.addEventListener('offline', () => {
      this.isOfflineMode = true;
      if (import.meta.env.DEV) console.log('[OfflineTotp] Offline mode');
    });
  }

  /**
   * Unlock vault: derive a CryptoKey for every salt found in the offline
   * cache, then drop the master password (B18).
   * @param {string} password - Master password (used once, never stored)
   */
  async unlockVault(password) {
    const { deriveKey } = await import('./crypto.js');
    const accounts = await offlineDb.getAccounts().catch(() => []);
    const salts = [...new Set(accounts.map(a => a.salt).filter(Boolean))];

    for (const salt of salts) {
      this.derivedKeys.set(salt, await deriveKey(password, salt));
    }
    if (import.meta.env.DEV) console.log('[OfflineTotp] Vault unlocked (' + this.derivedKeys.size + ' key(s) derived)');
  }

  /**
   * Lock vault (clear every derived key from memory)
   */
  lockVault() {
    this.derivedKeys.clear();
    if (import.meta.env.DEV) console.log('[OfflineTotp] Vault locked');
  }

  /**
   * Check if vault is unlocked
   */
  isVaultUnlocked() {
    return this.derivedKeys.size > 0;
  }

  /**
   * Generate TOTP code for an account (offline capable)
   * @param {string} accountId - Account ID
   * @param {number} timestamp - Current timestamp (optional, defaults to now)
   */
  async generateCode(accountId, timestamp = null) {
    if (!this.isVaultUnlocked()) {
      throw new Error('Vault is locked. Please unlock with master password.');
    }

    // Get account from offline cache
    const account = await offlineDb.getAccount(accountId);

    if (!account) {
      throw new Error('Account not found in offline cache');
    }

    // Decrypt the secret using master password
    const secret = await this.decryptAccountSecret(account);

    // Generate TOTP code (now async)
    const code = await this.computeTotp(
      secret,
      timestamp,
      account.algorithm || 'SHA1',
      account.digits || 6,
      account.period || 30
    );
    const timeRemaining = this.getTimeRemaining(account.period || 30);

    return {
      code,
      timeRemaining,
      period: account.period || 30,
      digits: account.digits || 6,
      algorithm: account.algorithm || 'SHA1',
      isOffline: this.isOfflineMode
    };
  }

  /**
   * Generate codes for all cached accounts
   */
  async generateAllCodes() {
    if (!this.isVaultUnlocked()) {
      throw new Error('Vault is locked. Please unlock with master password.');
    }

    const accounts = await offlineDb.getAccounts();
    const codes = [];

    for (const account of accounts) {
      try {
        const result = await this.generateCode(account.id);
        codes.push({
          accountId: account.id,
          service: account.service,
          username: account.username,
          ...result
        });
      } catch (error) {
        console.error('[OfflineTotp] Failed to generate code for account:', account.id, error);
        codes.push({
          accountId: account.id,
          service: account.service,
          username: account.username,
          error: error.message
        });
      }
    }

    return codes;
  }

  /**
   * Decrypt account secret using the cached derived key (B18)
   * @param {object} account - Encrypted account object
   */
  async decryptAccountSecret(account) {
    if (!account.encryptedSecret) {
      throw new Error('No encrypted secret found');
    }

    if (!account.salt || !this.derivedKeys.has(account.salt)) {
      throw new Error('No derived key for this account. Re-unlock the vault.');
    }

    try {
      const secret = await decryptSecret(account.encryptedSecret, this.derivedKeys.get(account.salt));

      return secret;
    } catch (error) {
      console.error('[OfflineTotp] Failed to decrypt secret:', error);
      throw new Error('Failed to decrypt secret. Invalid master password?');
    }
  }

  /**
   * Compute TOTP code from secret using Web Crypto API
   * @param {string} secret - Base32 encoded secret
   * @param {number} timestamp - Unix timestamp (optional)
   * @param {string} algorithm - Hash algorithm (SHA1, SHA256, SHA512)
   * @param {number} digits - Number of digits (6 or 8)
   * @param {number} period - Time period in seconds (default 30)
   */
  async computeTotp(secret, timestamp = null, algorithm = 'SHA1', digits = 6, period = 30) {
    const time = timestamp || Math.floor(Date.now() / 1000);
    const counter = Math.floor(time / period);

    // Decode base32 secret
    const key = this.base32Decode(secret);

    // Convert counter to 8-byte big-endian array
    const counterBytes = this.intToBytes(counter);

    // Import key for HMAC
    const cryptoKey = await crypto.subtle.importKey(
      'raw',
      key,
      { name: 'HMAC', hash: algorithm },
      false,
      ['sign']
    );

    // Compute HMAC using Web Crypto API
    const signature = await crypto.subtle.sign(
      { name: 'HMAC', hash: algorithm },
      cryptoKey,
      counterBytes
    );

    const hmac = new Uint8Array(signature);

    // Dynamic truncation
    const offset = hmac[hmac.length - 1] & 0x0f;
    const code = (
      ((hmac[offset] & 0x7f) << 24) |
      ((hmac[offset + 1] & 0xff) << 16) |
      ((hmac[offset + 2] & 0xff) << 8) |
      (hmac[offset + 3] & 0xff)
    );

    // Generate code with specified digits
    const modulo = Math.pow(10, digits);
    const otp = (code % modulo).toString().padStart(digits, '0');

    return otp;
  }

  /**
   * Get remaining time in current period (seconds)
   */
  getTimeRemaining(period = 30) {
    const time = Math.floor(Date.now() / 1000);
    return period - (time % period);
  }

  /**
   * Base32 decode
   */
  base32Decode(encoded) {
    const base32Chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    encoded = encoded.toUpperCase().replace(/=+$/, '');

    let bits = 0;
    let value = 0;
    const output = [];

    for (let i = 0; i < encoded.length; i++) {
      const idx = base32Chars.indexOf(encoded[i]);
      if (idx === -1) continue;

      value = (value << 5) | idx;
      bits += 5;

      if (bits >= 8) {
        output.push((value >>> (bits - 8)) & 0xff);
        bits -= 8;
      }
    }

    return new Uint8Array(output);
  }

  /**
   * Convert integer to byte array (big-endian, 8 bytes)
   */
  intToBytes(num) {
    const bytes = new Uint8Array(8);
    for (let i = 7; i >= 0; i--) {
      bytes[i] = num & 0xff;
      num = num >> 8;
    }
    return bytes;
  }

  /**
   * Check if offline mode is available (vault unlocked + accounts cached)
   */
  async isOfflineAvailable() {
    if (!this.isVaultUnlocked()) {
      return false;
    }

    const stats = await offlineDb.getStats();
    return stats.accounts > 0;
  }

  /**
   * Get offline mode status
   */
  async getStatus() {
    const available = await this.isOfflineAvailable();
    const stats = await offlineDb.getStats();

    return {
      isOfflineMode: this.isOfflineMode,
      isVaultUnlocked: this.isVaultUnlocked(),
      isOfflineAvailable: available,
      cachedAccounts: stats.accounts || 0
    };
  }
}

// Export singleton instance
export default new OfflineTotpService();
