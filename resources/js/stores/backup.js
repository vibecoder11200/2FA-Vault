import { defineStore } from 'pinia'
import { useUserStore } from './user'

export const useBackupStore = defineStore('backup', {
    state: () => ({
        lastBackupDate: null,
        isExporting: false,
        isImporting: false,
        info: {
            hasBackup: false,
            lastBackupAt: null,
            daysSinceBackup: null,
            shouldBackup: true,
        }
    }),

    getters: {
        needsBackup: (state) => {
            return state.info.shouldBackup || !state.info.hasBackup
        },
    },

    actions: {
        /**
         * Export encrypted backup
         * 
         * @param {string} masterPassword - User's master password
         * @returns {Promise}
         */
        async exportBackup(masterPassword) {
            this.isExporting = true
            
            try {
                // Derive encryption key from password (client-side)
                // This is a placeholder - in production, use proper key derivation
                const keyHash = await this.deriveKeyHash(masterPassword)
                
                const response = await window.axios.get('/api/v1/backup/export', {
                    params: {
                        encryption_key_hash: keyHash,
                        master_password_verified: true
                    },
                    responseType: 'blob'
                })
                
                // Download file
                const url = window.URL.createObjectURL(new Blob([response.data]))
                const link = document.createElement('a')
                link.href = url
                link.setAttribute('download', `2fauth-backup-${Date.now()}.vault`)
                document.body.appendChild(link)
                link.click()
                link.remove()
                window.URL.revokeObjectURL(url)
                
                // Update last backup date
                this.lastBackupDate = new Date().toISOString()
                
                return response
            } finally {
                this.isExporting = false
            }
        },

        /**
         * Import encrypted backup
         * 
         * @param {File} file - Backup file
         * @param {string} masterPassword - User's master password
         * @param {string} mode - 'merge' or 'replace'
         * @returns {Promise}
         */
        async importBackup(file, masterPassword, mode = 'merge') {
            this.isImporting = true
            
            try {
                const formData = new FormData()
                formData.append('backup_file', file)
                formData.append('mode', mode)
                formData.append('master_password_verified', true)
                
                const response = await window.axios.post('/api/v1/backup/import', formData, {
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
         * Get backup metadata from file
         * 
         * @param {File} file - Backup file
         * @returns {Promise}
         */
        async getBackupMetadata(file) {
            const formData = new FormData()
            formData.append('backup_file', file)
            
            const response = await window.axios.post('/api/v1/backup/metadata', formData, {
                headers: {
                    'Content-Type': 'multipart/form-data'
                }
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
                const response = await window.axios.get('/api/v1/backup/info')
                this.info = response.data
                
                if (this.info.lastBackupAt) {
                    this.lastBackupDate = this.info.lastBackupAt
                }
                
                return this.info
            } catch (error) {
                console.error('Failed to fetch backup info:', error)
                throw error
            }
        },

        /**
         * Derive key hash from password (placeholder)
         * In production, use Argon2id with salt from server
         * 
         * @param {string} password
         * @returns {Promise<string>}
         */
        async deriveKeyHash(password) {
            // This is a placeholder
            // In production: fetch salt from server, use Argon2id to derive key
            const encoder = new TextEncoder()
            const data = encoder.encode(password)
            const hashBuffer = await crypto.subtle.digest('SHA-256', data)
            const hashArray = Array.from(new Uint8Array(hashBuffer))
            return hashArray.map(b => b.toString(16).padStart(2, '0')).join('')
        },
    }
})
