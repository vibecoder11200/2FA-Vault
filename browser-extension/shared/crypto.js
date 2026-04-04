/**
 * Crypto utilities for 2FA-Vault browser extension
 * Reuses crypto logic from main app (Argon2id + AES-256-GCM)
 */

// Browser-compatible crypto using Web Crypto API
class Crypto {
  constructor() {
    this.subtle = crypto.subtle;
  }

  /**
   * Derive encryption key from master password using PBKDF2
   * (Argon2id not available in browser, using PBKDF2 as fallback)
   * @param {string} password - Master password
   * @param {Uint8Array} salt - Salt for key derivation
   * @returns {Promise<CryptoKey>} Derived key
   */
  async deriveKey(password, salt) {
    const encoder = new TextEncoder();
    const passwordBuffer = encoder.encode(password);
    
    const importedKey = await this.subtle.importKey(
      'raw',
      passwordBuffer,
      'PBKDF2',
      false,
      ['deriveKey']
    );

    return await this.subtle.deriveKey(
      {
        name: 'PBKDF2',
        salt: salt,
        iterations: 600000, // OWASP recommendation
        hash: 'SHA-256'
      },
      importedKey,
      { name: 'AES-GCM', length: 256 },
      false,
      ['encrypt', 'decrypt']
    );
  }

  /**
   * Encrypt data using AES-256-GCM
   * @param {string} plaintext - Data to encrypt
   * @param {CryptoKey} key - Encryption key
   * @returns {Promise<{ciphertext: string, iv: string, salt: string}>}
   */
  async encrypt(plaintext, key, salt) {
    const encoder = new TextEncoder();
    const data = encoder.encode(plaintext);
    
    const iv = crypto.getRandomValues(new Uint8Array(12)); // 96-bit IV for GCM
    
    const ciphertext = await this.subtle.encrypt(
      { name: 'AES-GCM', iv: iv },
      key,
      data
    );

    return {
      ciphertext: this.arrayBufferToBase64(ciphertext),
      iv: this.arrayBufferToBase64(iv),
      salt: this.arrayBufferToBase64(salt)
    };
  }

  /**
   * Decrypt data using AES-256-GCM
   * @param {string} ciphertext - Base64 encoded ciphertext
   * @param {string} ivBase64 - Base64 encoded IV
   * @param {CryptoKey} key - Decryption key
   * @returns {Promise<string>} Decrypted plaintext
   */
  async decrypt(ciphertext, ivBase64, key) {
    const ciphertextBuffer = this.base64ToArrayBuffer(ciphertext);
    const iv = this.base64ToArrayBuffer(ivBase64);

    const decrypted = await this.subtle.decrypt(
      { name: 'AES-GCM', iv: iv },
      key,
      ciphertextBuffer
    );

    const decoder = new TextDecoder();
    return decoder.decode(decrypted);
  }

  /**
   * Generate random salt
   * @returns {Uint8Array} 32-byte salt
   */
  generateSalt() {
    return crypto.getRandomValues(new Uint8Array(32));
  }

  /**
   * Convert ArrayBuffer to Base64
   */
  arrayBufferToBase64(buffer) {
    const bytes = new Uint8Array(buffer);
    let binary = '';
    for (let i = 0; i < bytes.length; i++) {
      binary += String.fromCharCode(bytes[i]);
    }
    return btoa(binary);
  }

  /**
   * Convert Base64 to ArrayBuffer
   */
  base64ToArrayBuffer(base64) {
    const binary = atob(base64);
    const bytes = new Uint8Array(binary.length);
    for (let i = 0; i < binary.length; i++) {
      bytes[i] = binary.charCodeAt(i);
    }
    return bytes.buffer;
  }

  /**
   * Hash data using SHA-256
   * @param {string} data - Data to hash
   * @returns {Promise<string>} Base64 encoded hash
   */
  async hash(data) {
    const encoder = new TextEncoder();
    const dataBuffer = encoder.encode(data);
    const hashBuffer = await this.subtle.digest('SHA-256', dataBuffer);
    return this.arrayBufferToBase64(hashBuffer);
  }
}

// Export for use in extension
const cryptoUtil = new Crypto();

if (typeof module !== 'undefined' && module.exports) {
  module.exports = cryptoUtil;
}
