/**
 * Biometric Authentication Service
 * Uses WebAuthn API for biometric unlock (fingerprint, face recognition)
 */

import offlineDb from './offline-db.js';

class BiometricService {
  constructor() {
    this.isAvailable = this.checkAvailability();
    this.credentialId = null;
  }

  /**
   * Check if WebAuthn is available
   */
  checkAvailability() {
    return window.PublicKeyCredential !== undefined &&
           navigator.credentials !== undefined;
  }

  /**
   * Check if biometric is supported
   */
  async isSupported() {
    if (!this.isAvailable) {
      return false;
    }

    try {
      // Check if platform authenticator is available
      const available = await PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable();
      return available;
    } catch (error) {
      console.error('[Biometric] Failed to check availability:', error);
      return false;
    }
  }

  /**
   * Register biometric credential for app unlock
   */
  async register(username) {
    if (!this.isAvailable) {
      throw new Error('WebAuthn not supported');
    }

    try {
      // Generate challenge
      const challenge = this.generateChallenge();

      // Create credential options
      const createCredentialOptions = {
        publicKey: {
          challenge: challenge,
          rp: {
            name: '2FA-Vault',
            id: window.location.hostname
          },
          user: {
            id: this.stringToBuffer(username),
            name: username,
            displayName: username
          },
          pubKeyCredParams: [
            { alg: -7, type: 'public-key' },  // ES256
            { alg: -257, type: 'public-key' } // RS256
          ],
          authenticatorSelection: {
            authenticatorAttachment: 'platform', // Platform authenticator (built-in)
            userVerification: 'required',
            requireResidentKey: false
          },
          timeout: 60000,
          attestation: 'none'
        }
      };

      // Create credential
      const credential = await navigator.credentials.create(createCredentialOptions);

      if (!credential) {
        throw new Error('Failed to create credential');
      }

      // Store credential ID
      this.credentialId = this.bufferToBase64(credential.rawId);
      await offlineDb.saveSetting('biometric_credential_id', this.credentialId);
      await offlineDb.saveSetting('biometric_username', username);

      console.log('[Biometric] Credential registered successfully');

      return {
        credentialId: this.credentialId,
        success: true
      };
    } catch (error) {
      console.error('[Biometric] Registration failed:', error);
      throw error;
    }
  }

  /**
   * Authenticate with biometric
   */
  async authenticate() {
    if (!this.isAvailable) {
      throw new Error('WebAuthn not supported');
    }

    try {
      // Load credential ID from storage
      if (!this.credentialId) {
        this.credentialId = await offlineDb.getSetting('biometric_credential_id');
      }

      if (!this.credentialId) {
        throw new Error('No biometric credential found. Please register first.');
      }

      // Generate challenge
      const challenge = this.generateChallenge();

      // Get credential options
      const getCredentialOptions = {
        publicKey: {
          challenge: challenge,
          allowCredentials: [{
            id: this.base64ToBuffer(this.credentialId),
            type: 'public-key',
            transports: ['internal']
          }],
          userVerification: 'required',
          timeout: 60000
        }
      };

      // Get credential
      const credential = await navigator.credentials.get(getCredentialOptions);

      if (!credential) {
        throw new Error('Authentication failed');
      }

      console.log('[Biometric] Authentication successful');

      // Get stored username
      const username = await offlineDb.getSetting('biometric_username');

      return {
        success: true,
        username,
        credentialId: this.bufferToBase64(credential.rawId)
      };
    } catch (error) {
      console.error('[Biometric] Authentication failed:', error);
      throw error;
    }
  }

  /**
   * Remove biometric credential
   */
  async remove() {
    try {
      await offlineDb.saveSetting('biometric_credential_id', null);
      await offlineDb.saveSetting('biometric_username', null);
      this.credentialId = null;

      console.log('[Biometric] Credential removed');
      return true;
    } catch (error) {
      console.error('[Biometric] Failed to remove credential:', error);
      throw error;
    }
  }

  /**
   * Check if biometric is registered
   */
  async isRegistered() {
    if (!this.credentialId) {
      this.credentialId = await offlineDb.getSetting('biometric_credential_id');
    }
    return this.credentialId !== null;
  }

  /**
   * Get biometric status
   */
  async getStatus() {
    const supported = await this.isSupported();
    const registered = await this.isRegistered();
    const username = await offlineDb.getSetting('biometric_username');

    return {
      available: this.isAvailable,
      supported,
      registered,
      username
    };
  }

  /**
   * Generate random challenge
   */
  generateChallenge() {
    const buffer = new Uint8Array(32);
    crypto.getRandomValues(buffer);
    return buffer;
  }

  /**
   * Convert string to ArrayBuffer
   */
  stringToBuffer(str) {
    const encoder = new TextEncoder();
    return encoder.encode(str);
  }

  /**
   * Convert ArrayBuffer to base64
   */
  bufferToBase64(buffer) {
    const bytes = new Uint8Array(buffer);
    let binary = '';
    for (let i = 0; i < bytes.byteLength; i++) {
      binary += String.fromCharCode(bytes[i]);
    }
    return window.btoa(binary);
  }

  /**
   * Convert base64 to ArrayBuffer
   */
  base64ToBuffer(base64) {
    const binary = window.atob(base64);
    const bytes = new Uint8Array(binary.length);
    for (let i = 0; i < binary.length; i++) {
      bytes[i] = binary.charCodeAt(i);
    }
    return bytes.buffer;
  }
}

// Export singleton instance
export default new BiometricService();
