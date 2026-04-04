<template>
    <div class="setup-encryption-container">
        <div class="setup-encryption-card">
            <h1 class="title">🔐 Setup End-to-End Encryption</h1>
            
            <div class="content">
                <div class="notification is-info">
                    <p class="has-text-weight-semibold">What is End-to-End Encryption?</p>
                    <ul class="mt-2">
                        <li>✅ Your OTP secrets are encrypted in your browser before being sent to the server</li>
                        <li>✅ Only you can decrypt your secrets with your master password</li>
                        <li>✅ The server NEVER sees your plaintext secrets or encryption keys</li>
                        <li>⚠️ If you forget your master password, your data CANNOT be recovered</li>
                    </ul>
                </div>

                <form @submit.prevent="handleSetup" class="mt-5">
                    <div class="field">
                        <label class="label">Master Password</label>
                        <div class="control has-icons-left">
                            <input 
                                v-model="masterPassword"
                                :type="showPassword ? 'text' : 'password'"
                                class="input" 
                                placeholder="Enter a strong master password"
                                required
                                minlength="8"
                                :disabled="isLoading"
                            />
                            <span class="icon is-small is-left">
                                <i class="fas fa-lock"></i>
                            </span>
                        </div>
                        <p class="help">Minimum 8 characters. Use a strong, unique password.</p>
                    </div>

                    <div class="field">
                        <label class="label">Confirm Master Password</label>
                        <div class="control has-icons-left">
                            <input 
                                v-model="confirmPassword"
                                :type="showPassword ? 'text' : 'password'"
                                class="input" 
                                placeholder="Confirm your master password"
                                required
                                :disabled="isLoading"
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

                    <div class="field">
                        <div class="control">
                            <label class="checkbox">
                                <input type="checkbox" v-model="understood" required />
                                I understand that if I forget my master password, my data cannot be recovered
                            </label>
                        </div>
                    </div>

                    <div class="field is-grouped mt-5">
                        <div class="control">
                            <button 
                                type="submit" 
                                class="button is-primary"
                                :class="{ 'is-loading': isLoading }"
                                :disabled="isLoading || !understood"
                            >
                                Enable Encryption
                            </button>
                        </div>
                        <div class="control">
                            <button 
                                type="button" 
                                class="button is-light"
                                @click="handleSkip"
                                :disabled="isLoading"
                            >
                                Skip for Now
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useCryptoStore } from '@/stores/crypto'
import { useNotification } from '@kyvg/vue3-notification'
import httpClientFactory from '@/services/httpClientFactory'

const router = useRouter()
const cryptoStore = useCryptoStore()
const { notify } = useNotification()

const masterPassword = ref('')
const confirmPassword = ref('')
const showPassword = ref(false)
const understood = ref(false)
const isLoading = ref(false)
const error = ref('')

const apiClient = httpClientFactory('api')

async function handleSetup() {
    error.value = ''
    
    // Validate passwords match
    if (masterPassword.value !== confirmPassword.value) {
        error.value = 'Passwords do not match'
        return
    }
    
    // Validate password strength
    if (masterPassword.value.length < 8) {
        error.value = 'Master password must be at least 8 characters long'
        return
    }
    
    isLoading.value = true
    
    try {
        // Setup encryption (generates salt and test value)
        const { salt, testValue } = await cryptoStore.setupEncryption(masterPassword.value)
        
        // Send salt and test value to server (NOT the password or key!)
        await apiClient.post('/encryption/setup', {
            encryption_salt: salt,
            encryption_test_value: testValue,
            encryption_version: 1
        })
        
        notify({
            type: 'success',
            title: 'Encryption Enabled',
            text: 'Your vault is now protected with end-to-end encryption'
        })
        
        // Redirect to main app
        router.push({ name: 'accounts' })
    } catch (err) {
        console.error('Encryption setup failed:', err)
        error.value = err.response?.data?.message || 'Failed to setup encryption. Please try again.'
    } finally {
        isLoading.value = false
    }
}

function handleSkip() {
    // User chose not to enable encryption
    router.push({ name: 'accounts' })
}
</script>

<style scoped>
.setup-encryption-container {
    display: flex;
    justify-content: center;
    align-items: center;
    min-height: 100vh;
    padding: 1rem;
}

.setup-encryption-card {
    max-width: 600px;
    width: 100%;
    background: white;
    border-radius: 8px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
    padding: 2rem;
}

.title {
    font-size: 1.75rem;
    font-weight: bold;
    text-align: center;
    margin-bottom: 1.5rem;
}

.notification ul {
    list-style: none;
    padding-left: 0;
}

.notification li {
    margin-bottom: 0.5rem;
}
</style>
