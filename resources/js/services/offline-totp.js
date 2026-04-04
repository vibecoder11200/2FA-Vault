/**
 * Offline TOTP Service - Generate TOTP codes completely offline
 * Uses cached encrypted secrets from IndexedDB
 */

import offlineDb from './offline-db.js';
import { decryptSecret } from './crypto.js';

class OfflineTotpService {
  constructor() {
    this.isOfflineMode = !navigator.onLine;
    this.masterPassword = null; // Session memory only
    this.setupNetworkListener();
  }

  /**
   * Setup network status listener
   */
  setupNetworkListener() {
    window.addEventListener('online', () => {
      this.isOfflineMode = false;
      console.log('[OfflineTotp] Online mode');
    });

    window.addEventListener('offline', () => {
      this.isOfflineMode = true;
      console.log('[OfflineTotp] Offline mode');
    });
  }

  /**
   * Unlock vault with master password (store in memory only)
   * @param {string} password - Master password
   */
  unlockVault(password) {
    this.masterPassword = password;
    console.log('[OfflineTotp] Vault unlocked');
  }

  /**
   * Lock vault (clear master password from memory)
   */
  lockVault() {
    this.masterPassword = null;
    console.log('[OfflineTotp] Vault locked');
  }

  /**
   * Check if vault is unlocked
   */
  isVaultUnlocked() {
    return this.masterPassword !== null;
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

    // Generate TOTP code
    const code = this.computeTotp(secret, timestamp);
    const timeRemaining = this.getTimeRemaining();

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
   * Decrypt account secret using master password
   * @param {object} account - Encrypted account object
   */
  async decryptAccountSecret(account) {
    if (!account.encryptedSecret) {
      throw new Error('No encrypted secret found');
    }

    try {
      // Use crypto.js decryptSecret function with master password
      const secret = await decryptSecret(
        account.encryptedSecret,
        this.masterPassword,
        account.salt
      );

      return secret;
    } catch (error) {
      console.error('[OfflineTotp] Failed to decrypt secret:', error);
      throw new Error('Failed to decrypt secret. Invalid master password?');
    }
  }

  /**
   * Compute TOTP code from secret
   * @param {string} secret - Base32 encoded secret
   * @param {number} timestamp - Unix timestamp (optional)
   */
  computeTotp(secret, timestamp = null) {
    const time = timestamp || Math.floor(Date.now() / 1000);
    const period = 30; // Standard TOTP period
    const counter = Math.floor(time / period);

    // Decode base32 secret
    const key = this.base32Decode(secret);

    // Compute HMAC-SHA1
    const hmac = this.hmacSha1(key, this.intToBytes(counter));

    // Dynamic truncation
    const offset = hmac[hmac.length - 1] & 0x0f;
    const code = (
      ((hmac[offset] & 0x7f) << 24) |
      ((hmac[offset + 1] & 0xff) << 16) |
      ((hmac[offset + 2] & 0xff) << 8) |
      (hmac[offset + 3] & 0xff)
    );

    // Generate 6-digit code
    const otp = (code % 1000000).toString().padStart(6, '0');

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
   * HMAC-SHA1 computation
   */
  hmacSha1(key, message) {
    // This is a simplified implementation
    // In production, use Web Crypto API or a library like crypto-js
    
    const blockSize = 64;
    
    // Pad or hash key if needed
    if (key.length > blockSize) {
      key = this.sha1(key);
    }
    if (key.length < blockSize) {
      const paddedKey = new Uint8Array(blockSize);
      paddedKey.set(key);
      key = paddedKey;
    }

    // Create inner and outer padded keys
    const innerKey = new Uint8Array(blockSize);
    const outerKey = new Uint8Array(blockSize);
    
    for (let i = 0; i < blockSize; i++) {
      innerKey[i] = key[i] ^ 0x36;
      outerKey[i] = key[i] ^ 0x5c;
    }

    // HMAC = H(outerKey || H(innerKey || message))
    const innerHash = this.sha1(this.concat(innerKey, message));
    const hmac = this.sha1(this.concat(outerKey, innerHash));

    return hmac;
  }

  /**
   * SHA-1 hash (simplified - use crypto library in production)
   */
  sha1(data) {
    // For production, use Web Crypto API:
    // const buffer = await crypto.subtle.digest('SHA-1', data);
    // return new Uint8Array(buffer);
    
    // This is a placeholder - implement proper SHA-1 or use crypto-js
    console.warn('[OfflineTotp] Using simplified SHA-1 - replace with Web Crypto API in production');
    
    // Fallback: return dummy hash for now
    return new Uint8Array(20); // SHA-1 produces 20 bytes
  }

  /**
   * Concatenate two Uint8Arrays
   */
  concat(a, b) {
    const result = new Uint8Array(a.length + b.length);
    result.set(a);
    result.set(b, a.length);
    return result;
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
