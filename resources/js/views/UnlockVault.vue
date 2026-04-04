<template>
    <div class="unlock-vault-container">
        <div class="unlock-vault-card">
            <h1 class="title">🔒 Unlock Your Vault</h1>
            
            <div class="content">
                <p class="has-text-centered mb-4">
                    Enter your master password to decrypt your OTP secrets
                </p>

                <form @submit.prevent="handleUnlock">
                    <div class="field">
                        <label class="label">Master Password</label>
                        <div class="control has-icons-left">
                            <input 
                                v-model="masterPassword"
                                :type="showPassword ? 'text' : 'password'"
                                class="input" 
                                placeholder="Enter your master password"
                                required
                                :disabled="isLoading"
                                autofocus
                            />
                            <span class="icon is-small is-left">
                                <i class="fas fa-lock"></i>
                            </span>
                        </div>
                    </div>

                    <div class="field">
                        <div class="control">
                            <label class="checkbox">
                                <input type="checkbox" v-model="showPassword" />
                                Show password
                            </label>
                        </div>
                    </div>

                    <div v-if="error" class="notification is-danger">
                        {{ error }}
                    </div>

                    <div class="field is-grouped mt-5">
                        <div class="control">
                            <button 
                                type="submit" 
                                class="button is-primary is-fullwidth"
                                :class="{ 'is-loading': isLoading }"
                                :disabled="isLoading"
                            >
                                🔓 Unlock Vault
                            </button>
                        </div>
                    </div>

                    <div class="has-text-centered mt-4">
                        <a href="#" @click.prevent="handleLogout" class="has-text-grey">
                            Logout
                        </a>
                    </div>
                </form>

                <div class="notification is-warning mt-5">
                    <p class="has-text-weight-semibold">⚠️ Forgot your master password?</p>
                    <p class="mt-2">Unfortunately, encrypted data cannot be recovered without the master password. This is by design for security.</p>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useCryptoStore } from '@/stores/crypto'
import { useUserStore } from '@/stores/user'
import { useNotification } from '@kyvg/vue3-notification'
import httpClientFactory from '@/services/httpClientFactory'

const router = useRouter()
const cryptoStore = useCryptoStore()
const userStore = useUserStore()
const { notify } = useNotification()

const masterPassword = ref('')
const showPassword = ref(false)
const isLoading = ref(false)
const error = ref('')

const apiClient = httpClientFactory('api')

onMounted(async () => {
    // Check if user has encryption enabled
    if (!cryptoStore.isEncryptionEnabled) {
        // User doesn't have encryption setup, redirect to main app
        router.push({ name: 'accounts' })
    }
})

async function handleUnlock() {
    error.value = ''
    isLoading.value = true
    
    try {
        // Fetch user's salt and test value from server
        const response = await apiClient.get('/encryption/info')
        const { encryption_salt, encryption_test_value } = response.data
        
        // Attempt to unlock vault
        const success = await cryptoStore.unlockVault(
            masterPassword.value,
            encryption_salt,
            encryption_test_value
        )
        
        if (!success) {
            error.value = 'Invalid master password. Please try again.'
            masterPassword.value = ''
            return
        }
        
        notify({
            type: 'success',
            title: 'Vault Unlocked',
            text: 'Your vault has been unlocked successfully'
        })
        
        // Redirect to main app
        router.push({ name: 'accounts' })
    } catch (err) {
        console.error('Unlock failed:', err)
        error.value = err.response?.data?.message || 'Failed to unlock vault. Please try again.'
        masterPassword.value = ''
    } finally {
        isLoading.value = false
    }
}

async function handleLogout() {
    try {
        await userStore.logout()
        router.push({ name: 'login' })
    } catch (err) {
        console.error('Logout failed:', err)
    }
}
</script>

<style scoped>
.unlock-vault-container {
    display: flex;
    justify-content: center;
    align-items: center;
    min-height: 100vh;
    padding: 1rem;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
}

.unlock-vault-card {
    max-width: 500px;
    width: 100%;
    background: white;
    border-radius: 8px;
    box-shadow: 0 4px 16px rgba(0, 0, 0, 0.2);
    padding: 2rem;
}

.title {
    font-size: 1.75rem;
    font-weight: bold;
    text-align: center;
    margin-bottom: 1.5rem;
}
</style>
