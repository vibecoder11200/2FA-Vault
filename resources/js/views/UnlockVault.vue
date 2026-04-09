<script setup>
    import Form from '@/components/formElements/Form'
    import { useCryptoStore } from '@/stores/crypto'
    import { useUserStore } from '@/stores/user'
    import { useNotify } from '@2fauth/ui'
    import { useI18n } from 'vue-i18n'
    import { useErrorHandler } from '@2fauth/stores'
    import httpClientFactory from '@/services/httpClientFactory'

    const errorHandler = useErrorHandler()
    const { t } = useI18n()
    const cryptoStore = useCryptoStore()
    const userStore = useUserStore()
    const notify = useNotify()
    const router = useRouter()
    const apiClient = httpClientFactory('api')

    const unlockForm = reactive(new Form({
        masterPassword: '',
        showPassword: false,
    }))

    onMounted(async () => {
        const statusResponse = await apiClient.get('/encryption/status')

        if (!statusResponse.data.encryption_enabled) {
            userStore.encryption_version = 0
            userStore.vault_locked = false
            cryptoStore.disableEncryption()
            router.push({ name: 'accounts' })
            return
        }

        userStore.encryption_version = statusResponse.data.encryption_version
        userStore.vault_locked = statusResponse.data.vault_locked

        if (!statusResponse.data.vault_locked && cryptoStore.isVaultUnlocked) {
            router.push({ name: 'accounts' })
        }
    })

    async function handleUnlock() {
        unlockForm.errors.clear()
        unlockForm.startProcessing()

        try {
            const response = await apiClient.get('/encryption/info')

            if (!response.data.encryption_enabled) {
                cryptoStore.disableEncryption()
                userStore.encryption_version = 0
                userStore.vault_locked = false
                router.push({ name: 'accounts' })
                return
            }

            const { encryption_salt, encryption_test_value } = response.data
            const success = await cryptoStore.unlockVault(
                unlockForm.masterPassword,
                encryption_salt,
                encryption_test_value
            )

            if (!success) {
                unlockForm.errors.set('masterPassword', t('error.invalid_master_password'))
                unlockForm.masterPassword = ''
                return
            }

            await apiClient.post('/encryption/verify', {
                verification_result: true,
            })

            userStore.encryption_version = response.data.encryption_version
            userStore.vault_locked = false

            notify.success({ text: t('notification.vault_unlocked') })
            await userStore.initDataStores()
            router.push({ name: 'accounts' })
        } catch (error) {
            if (error.response?.status === 401) {
                unlockForm.errors.set('masterPassword', t('error.invalid_master_password'))
            } else {
                errorHandler.show(error)
            }

            unlockForm.masterPassword = ''
        } finally {
            unlockForm.finishProcessing()
        }
    }

    async function handleLogout() {
        await userStore.logout()
    }

    onBeforeRouteLeave(() => {
        notify.clear()
    })
</script>

<template>
    <StackLayout>
        <template #content>
            <FormWrapper :title="'heading.end_to_end_encryption'" :punchline="'message.vault_locked_desc'">
                <div class="notification is-warning is-light mb-4">
                    {{ $t('message.vault_locked') }}
                </div>

                <form @submit.prevent="handleUnlock" @keydown="unlockForm.onKeydown($event)">
                    <FormField
                        v-model="unlockForm.masterPassword"
                        fieldName="masterPassword"
                        :errorMessage="unlockForm.errors.get('masterPassword')"
                        :inputType="unlockForm.showPassword ? 'text' : 'password'"
                        autocomplete="current-password"
                        label="field.master_password"
                        help="field.master_password.help"
                    />

                    <div class="field">
                        <label class="checkbox">
                            <input v-model="unlockForm.showPassword" type="checkbox" />
                            Show password
                        </label>
                    </div>

                    <FormButtons
                        :isBusy="unlockForm.isBusy"
                        submitLabel="label.unlock_vault"
                        submitId="btnUnlockVault"
                    />
                </form>

                <div class="nav-links mt-4">
                    <p>
                        <a class="is-link" @click="handleLogout">Logout</a>
                    </p>
                </div>

            </FormWrapper>
        </template>
        <template #footer>
            <VueFooter />
        </template>
    </StackLayout>
</template>

<style scoped>
.nav-links {
    text-align: center;
}
</style>