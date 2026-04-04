/**
 * End-to-End Encryption (E2EE) Crypto Module
 * Zero-Knowledge Architecture - Server NEVER sees plaintext secrets
 * 
 * All encryption/decryption happens client-side using:
 * - Argon2id for key derivation
 * - AES-256-GCM for encryption
 * - Web Crypto API for cryptographic operations
 */

import argon2 from 'argon2-browser'

// Crypto configuration constants
const ARGON2_CONFIG = {
    time: 3,        // Number of iterations
    mem: 65536,     // Memory cost in KB (64 MB)
    hashLen: 32,    // Hash length in bytes
    parallelism: 1, // Parallelism factor
    type: argon2.ArgonType.Argon2id
}

const AES_CONFIG = {
    name: 'AES-GCM',
    length: 256,
    ivLength: 12,
    tagLength: 128
}

const SALT_LENGTH = 32 // 32 bytes = 256 bits

/**
 * Generate a random salt for key derivation
 * @returns {Uint8Array} Random 32-byte salt
 */
export function generateSalt() {
    return crypto.getRandomValues(new Uint8Array(SALT_LENGTH))
}

/**
 * Derive encryption key from master password using Argon2id
 * @param {string} masterPassword - User's master password
 * @param {Uint8Array|string} salt - Salt for key derivation (Uint8Array or base64 string)
 * @returns {Promise<CryptoKey>} Derived AES-256-GCM key
 */
export async function deriveKey(masterPassword, salt) {
    // Convert salt to Uint8Array if it's a base64 string
    const saltBytes = typeof salt === 'string' ? base64ToBytes(salt) : salt

    // Derive key using Argon2id
    const result = await argon2.hash({
        pass: masterPassword,
        salt: saltBytes,
        ...ARGON2_CONFIG
    })

    // Import the derived hash as a CryptoKey for AES-GCM
    return await crypto.subtle.importKey(
        'raw',
        result.hash,
        { name: AES_CONFIG.name },
        false, // Not extractable
        ['encrypt', 'decrypt']
    )
}

/**
 * Encrypt plaintext using AES-256-GCM
 * @param {string} plaintext - Data to encrypt
 * @param {CryptoKey} key - Encryption key
 * @returns {Promise<{ciphertext: string, iv: string, authTag: string}>} Encrypted data
 */
export async function encryptSecret(plaintext, key) {
    // Generate random IV
    const iv = crypto.getRandomValues(new Uint8Array(AES_CONFIG.ivLength))

    // Encode plaintext as bytes
    const plaintextBytes = new TextEncoder().encode(plaintext)

    // Encrypt using AES-256-GCM
    const ciphertextBytes = await crypto.subtle.encrypt(
        {
            name: AES_CONFIG.name,
            iv: iv,
            tagLength: AES_CONFIG.tagLength
        },
        key,
        plaintextBytes
    )

    // AES-GCM returns ciphertext + auth tag combined
    // Split them for storage
    const ciphertextArray = new Uint8Array(ciphertextBytes)
    const authTagLength = AES_CONFIG.tagLength / 8 // Convert bits to bytes
    const ciphertext = ciphertextArray.slice(0, -authTagLength)
    const authTag = ciphertextArray.slice(-authTagLength)

    return {
        ciphertext: bytesToBase64(ciphertext),
        iv: bytesToBase64(iv),
        authTag: bytesToBase64(authTag)
    }
}

/**
 * Decrypt ciphertext using AES-256-GCM
 * @param {{ciphertext: string, iv: string, authTag: string}} encryptedData - Encrypted data
 * @param {CryptoKey} key - Decryption key
 * @returns {Promise<string>} Decrypted plaintext
 */
export async function decryptSecret(encryptedData, key) {
    try {
        // Convert from base64
        const ciphertext = base64ToBytes(encryptedData.ciphertext)
        const iv = base64ToBytes(encryptedData.iv)
        const authTag = base64ToBytes(encryptedData.authTag)

        // Combine ciphertext and auth tag (AES-GCM expects them together)
        const combined = new Uint8Array(ciphertext.length + authTag.length)
        combined.set(ciphertext)
        combined.set(authTag, ciphertext.length)

        // Decrypt using AES-256-GCM
        const plaintextBytes = await crypto.subtle.decrypt(
            {
                name: AES_CONFIG.name,
                iv: iv,
                tagLength: AES_CONFIG.tagLength
            },
            key,
            combined
        )

        // Decode bytes to string
        return new TextDecoder().decode(plaintextBytes)
    } catch (error) {
        throw new Error('Decryption failed: Invalid password or corrupted data')
    }
}

/**
 * Encrypt a TwoFAccount object (encrypts the secret field)
 * @param {Object} account - TwoFAccount object
 * @param {CryptoKey} key - Encryption key
 * @returns {Promise<Object>} Account with encrypted secret
 */
export async function encryptAccount(account, key) {
    if (!account.secret) {
        return account
    }

    const encryptedSecret = await encryptSecret(account.secret, key)

    return {
        ...account,
        secret: JSON.stringify(encryptedSecret),
        encrypted: true
    }
}

/**
 * Decrypt a TwoFAccount object (decrypts the secret field)
 * @param {Object} encryptedAccount - Encrypted TwoFAccount object
 * @param {CryptoKey} key - Decryption key
 * @returns {Promise<Object>} Account with decrypted secret
 */
export async function decryptAccount(encryptedAccount, key) {
    if (!encryptedAccount.secret || !encryptedAccount.encrypted) {
        return encryptedAccount
    }

    try {
        const encryptedData = JSON.parse(encryptedAccount.secret)
        const plaintext = await decryptSecret(encryptedData, key)

        return {
            ...encryptedAccount,
            secret: plaintext,
            encrypted: false
        }
    } catch (error) {
        console.error('Failed to decrypt account:', error)
        return {
            ...encryptedAccount,
            decryptionError: true
        }
    }
}

/**
 * Create an encrypted test value for password verification
 * Used for zero-knowledge password verification on the server
 * @param {CryptoKey} key - Encryption key
 * @returns {Promise<string>} Encrypted test value (JSON string)
 */
export async function createTestValue(key) {
    const testPlaintext = 'VERIFICATION_TEST_VALUE'
    const encrypted = await encryptSecret(testPlaintext, key)
    return JSON.stringify(encrypted)
}

/**
 * Verify if a master password is correct by decrypting the test value
 * @param {string} encryptedTestValue - Encrypted test value (JSON string)
 * @param {CryptoKey} key - Encryption key to test
 * @returns {Promise<boolean>} True if password is correct
 */
export async function verifyPassword(encryptedTestValue, key) {
    try {
        const encryptedData = JSON.parse(encryptedTestValue)
        const plaintext = await decryptSecret(encryptedData, key)
        return plaintext === 'VERIFICATION_TEST_VALUE'
    } catch {
        return false
    }
}

// Helper functions for base64 encoding/decoding

/**
 * Convert Uint8Array to base64 string
 * @param {Uint8Array} bytes 
 * @returns {string} Base64 encoded string
 */
function bytesToBase64(bytes) {
    const binString = String.fromCharCode(...bytes)
    return btoa(binString)
}

/**
 * Convert base64 string to Uint8Array
 * @param {string} base64 
 * @returns {Uint8Array} Decoded bytes
 */
function base64ToBytes(base64) {
    const binString = atob(base64)
    return Uint8Array.from(binString, (char) => char.charCodeAt(0))
}

export default {
    generateSalt,
    deriveKey,
    encryptSecret,
    decryptSecret,
    encryptAccount,
    decryptAccount,
    createTestValue,
    verifyPassword
}
