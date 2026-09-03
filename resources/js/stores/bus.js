import { defineStore } from 'pinia'

export const useBusStore = defineStore('bus', {
    state: () => {
        return {
            migrationUri: null,
            decodedUri: null,
            inManagementMode: false,
            editedGroupName: null,
            username: null,
            // E8: bumped every time the vault auto-locks so views can scrub
            // plaintext UI (close the OTP modal etc.)
            vaultLockedAt: 0,
        }
    },

    actions: {
        
    },
})
