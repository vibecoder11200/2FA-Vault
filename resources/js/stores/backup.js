import { defineStore } from 'pinia'
import httpClientFactory from '@/services/httpClientFactory'
import { generateSalt, deriveKey, encryptSecret, decryptSecret, bytesToBase64 } from '@/services/crypto'

const apiClient = httpClientFactory('api')

// Argon2id parameters for backup password encryption — MUST match the
// server-side stack (BackupService::BACKUP_KDF_PARAMS) so files exported by
// the auto-backup job and by the SPA are interchangeable.
const BACKUP_KDF_PARAMS = { time: 3, memory_kib: 65536, parallelism: 1 }

/**
 * Build a format 2 backup envelope: the payload JSON encrypted with a
 * password-derived AES-256-GCM key, alongside the crypto material (salt,
 * iv, tag, kdf params) needed to decrypt it later.
 */
async function buildEncryptedBackupEnvelope(payload, password) {
    const salt = generateSalt()
    const key = await deriveKey(password, salt)
    const { ciphertext, iv, authTag } = await encryptSecret(JSON.stringify(payload), key)

    return {
        app: '2FA-Vault',
        format: 2,
        version: payload.version ?? '2.0',
        datetime: new Date().toISOString(),
        encryption: {
            algorithm: 'aes-256-gcm',
            kdf: 'argon2id',
            kdf_params: BACKUP_KDF_PARAMS,
            salt: bytesToBase64(salt),
            iv,
            tag: authTag,
        },
        data: ciphertext,
    }
}

/**
 * Trigger a browser file download for the given JSON-serializable content.
 */
function downloadBlob(content, filename) {
    const blob = new Blob([JSON.stringify(content, null, 2)], { type: 'application/json' })
    const url = URL.createObjectURL(blob)
    const link = document.createElement('a')
    link.href = url
    link.download = filename
    document.body.appendChild(link)
    link.click()
    link.remove()
    URL.revokeObjectURL(url)
}

export const useBackupStore = defineStore('backup', {
    state: () => ({
        lastBackupDate: null,
        isExporting: false,
        isImporting: false,
        info: {
            has_backup: false,
            last_backup_at: null,
            days_since_backup: null,
            should_backup: true,
        }
    }),

    getters: {
        needsBackup: (state) => {
            return state.info.should_backup || !state.info.has_backup
        },
    },

    actions: {
        /**
         * Export encrypted backup (client-side encryption, format 2).
         *
         * The server returns the backup PAYLOAD; this store encrypts it with
         * the user-typed password (Argon2id + AES-256-GCM, same crypto stack
         * as the E2EE module) and triggers the file download. The password
         * never leaves the browser.
         *
         * @param {string} password - Backup password chosen by the user
         * @returns {Promise}
         */
        async exportBackup(password) {
            this.isExporting = true

            try {
                const response = await apiClient.post('/backups/export')

                const envelope = await buildEncryptedBackupEnvelope(response.data.payload, password)
                downloadBlob(envelope, response.data.filename || '2fa-vault-backup.vault')

                this.lastBackupDate = new Date().toISOString()

                return response.data
            } finally {
                this.isExporting = false
            }
        },

        /**
         * Decrypt a format 2 backup envelope client-side.
         *
         * @param {Object} envelope - Parsed .vault file content
         * @param {string} password - Backup password
         * @returns {Promise<Object>} Decrypted payload
         */
        async decryptBackupEnvelope(envelope, password) {
            const { encryption, data } = envelope
            const key = await deriveKey(password, encryption.salt)
            const plaintext = await decryptSecret({
                ciphertext: data,
                iv: encryption.iv,
                authTag: encryption.tag,
            }, key)

            return JSON.parse(plaintext)
        },

        /**
         * Prepare a backup file for upload.
         *
         * Format 2 envelopes are decrypted client-side first (the server
         * refuses undecrypted v2 files by design). Legacy files are uploaded
         * as-is.
         *
         * @param {File} file - Backup file chosen by the user
         * @param {string} password - Backup password (v2 files)
         * @returns {Promise<{file: File, payload: Object|null}>}
         */
        async prepareBackupFileForUpload(file, password) {
            const text = await file.text()
            let data = null

            try {
                data = JSON.parse(text)
            } catch {
                // Not JSON: upload the original file as-is — the SERVER is
                // the authority on validity and answers with a 422 (throwing
                // here used to bounce the user to the global error page and
                // skip server-side validation entirely).
                return { file, payload: null }
            }

            const isV2Envelope = data && Number(data.format) >= 2 && !data.accounts

            if (!isV2Envelope) {
                return { file, payload: data }
            }

            const payload = await this.decryptBackupEnvelope(data, password)

            return {
                file: new File([JSON.stringify(payload, null, 2)], file.name, { type: 'application/json' }),
                payload,
            }
        },

        /**
         * Import encrypted backup
         *
         * @param {File} file - Backup file
         * @param {string} password - Backup password (used to decrypt v2 files client-side)
         * @param {string} conflictResolution - 'skip' | 'replace' | 'rename'
         * @param {boolean} importGroups
         * @returns {Promise}
         */
        async importBackup(file, password, conflictResolution = 'skip', importGroups = true) {
            this.isImporting = true

            try {
                const prepared = await this.prepareBackupFileForUpload(file, password)

                const formData = new FormData()
                formData.append('backup_file', prepared.file)
                formData.append('conflict_resolution', conflictResolution)
                formData.append('import_groups', importGroups ? '1' : '0')

                const response = await apiClient.post('/backups/import', formData, {
                    headers: {
                        'Content-Type': 'multipart/form-data'
                    }
                })

                return response.data
            } finally {
                this.isImporting = false
            }
        },

        /**
         * Delete just-imported accounts (one-click cleanup of undecryptable
         * imports, C3). Uses the normal batch delete endpoint.
         *
         * @param {number[]} ids
         * @returns {Promise<boolean>} True when the batch delete succeeded
         */
        async deleteImportedAccounts(ids) {
            await apiClient.delete('/twofaccounts', {
                data: { ids: ids.join(',') },
            })

            return true
        },

        /**
         * Get backup metadata from file
         *
         * @param {File} file - Backup file
         * @returns {Promise}
         */
        async getBackupMetadata(file) {
            const formData = new FormData()
            formData.append('backup_file', file)

            const response = await apiClient.post('/backups/metadata', formData, {
                headers: {
                    'Content-Type': 'multipart/form-data'
                },
                // An invalid file must surface in the import dialog, not kick
                // the user to the global error page.
                returnError: true
            })

            return response.data
        },

        /**
         * Fetch user's backup info
         *
         * @returns {Promise}
         */
        async fetchInfo() {
            try {
                const response = await apiClient.get('/backups/info')
                this.info = response.data

                if (this.info.last_backup_at) {
                    this.lastBackupDate = this.info.last_backup_at
                }

                return this.info
            } catch (error) {
                console.error('Failed to fetch backup info:', error)
                throw error
            }
        },

    }
})
