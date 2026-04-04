/**
 * Crypto Store - Manages encryption state and vault locking
 * Pinia store for E2EE encryption key management
 */

import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import * as crypto from '@/services/crypto'

export const useCryptoStore = defineStore('crypto', () => {
    // State
    const encryptionKey = ref(null)
    const isVaultUnlocked = ref(false)
    const encryptionEnabled = ref(false)
    const salt = ref(null)
    
    // Getters
    const isEncryptionEnabled = computed(() => encryptionEnabled.value)
    const isUnlocked = computed(() => isVaultUnlocked.value)
    const hasEncryptionKey = computed(() => encryptionKey.value !== null)
    
    // Actions
    
    /**
     * Initialize encryption for a user
     * @param {string} masterPassword - User's master password
     * @returns {Promise<{salt: string, testValue: string}>} Salt and test value for server storage
     */
    async function setupEncryption(masterPassword) {
        // Generate salt
        const saltBytes = crypto.generateSalt()
        const saltBase64 = bytesToBase64(saltBytes)
        
        // Derive key
        const key = await crypto.deriveKey(masterPassword, saltBytes)
        
        // Create test value for verification
        const testValue = await crypto.createTestValue(key)
        
        // Store in memory
        encryptionKey.value = key
        salt.value = saltBase64
        encryptionEnabled.value = true
        isVaultUnlocked.value = true
        
        return {
            salt: saltBase64,
            testValue
        }
    }
    
    /**
     * Unlock vault with master password
     * @param {string} masterPassword - User's master password
     * @param {string} storedSalt - Salt from server
     * @param {string} testValue - Encrypted test value from server
     * @returns {Promise<boolean>} True if unlocked successfully
     */
    async function unlockVault(masterPassword, storedSalt, testValue) {
        try {
            // Derive key from password and salt
            const key = await crypto.deriveKey(masterPassword, storedSalt)
            
            // Verify password by decrypting test value
            const isValid = await crypto.verifyPassword(testValue, key)
            
            if (!isValid) {
                throw new Error('Invalid password')
            }
            
            // Store key in memory
            encryptionKey.value = key
            salt.value = storedSalt
            encryptionEnabled.value = true
            isVaultUnlocked.value = true
            
            return true
        } catch (error) {
            console.error('Failed to unlock vault:', error)
            return false
        }
    }
    
    /**
     * Lock the vault (clear encryption key from memory)
     */
    function lockVault() {
        encryptionKey.value = null
        isVaultUnlocked.value = false
    }
    
    /**
     * Enable encryption state (called when user has encryption setup)
     * @param {string} userSalt - User's encryption salt
     */
    function enableEncryption(userSalt) {
        encryptionEnabled.value = true
        salt.value = userSalt
        isVaultUnlocked.value = false
    }
    
    /**
     * Disable encryption (for users who haven't setup E2EE)
     */
    function disableEncryption() {
        encryptionEnabled.value = false
        encryptionKey.value = null
        salt.value = null
        isVaultUnlocked.value = false
    }
    
    /**
     * Encrypt an account before sending to server
     * @param {Object} account - Account object with plaintext secret
     * @returns {Promise<Object>} Account with encrypted secret
     */
    async function encryptAccountData(account) {
        if (!isVaultUnlocked.value || !encryptionKey.value) {
            throw new Error('Vault is locked')
        }
        
        return await crypto.encryptAccount(account, encryptionKey.value)
    }
    
    /**
     * Decrypt an account received from server
     * @param {Object} encryptedAccount - Account with encrypted secret
     * @returns {Promise<Object>} Account with plaintext secret
     */
    async function decryptAccountData(encryptedAccount) {
        if (!isVaultUnlocked.value || !encryptionKey.value) {
            throw new Error('Vault is locked')
        }
        
        return await crypto.decryptAccount(encryptedAccount, encryptionKey.value)
    }
    
    /**
     * Decrypt multiple accounts
     * @param {Array<Object>} accounts - Array of encrypted accounts
     * @returns {Promise<Array<Object>>} Array of decrypted accounts
     */
    async function decryptAccounts(accounts) {
        if (!isVaultUnlocked.value || !encryptionKey.value) {
            throw new Error('Vault is locked')
        }
        
        return await Promise.all(
            accounts.map(account => crypto.decryptAccount(account, encryptionKey.value))
        )
    }
    
    /**
     * Reset store (for logout)
     */
    function reset() {
        encryptionKey.value = null
        isVaultUnlocked.value = false
        encryptionEnabled.value = false
        salt.value = null
    }
    
    return {
        // State
        encryptionKey,
        isVaultUnlocked,
        encryptionEnabled,
        salt,
        
        // Getters
        isEncryptionEnabled,
        isUnlocked,
        hasEncryptionKey,
        
        // Actions
        setupEncryption,
        unlockVault,
        lockVault,
        enableEncryption,
        disableEncryption,
        encryptAccountData,
        decryptAccountData,
        decryptAccounts,
        reset
    }
})

// Helper function
function bytesToBase64(bytes) {
    const binString = String.fromCharCode(...bytes)
    return btoa(binString)
}
