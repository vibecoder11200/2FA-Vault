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
    const isMandatorySetup = computed(() => userStore.e2ee_required === true)

    onMounted(async () => {
        try {
            const statusResponse = await apiClient.get('/encryption/status')
            userStore.e2ee_required = statusResponse.data.e2ee_required === true
        } catch (error) {
            console.debug('Failed to fetch encryption status during setup mount', error)
        }
    })

    const isMandatorySetupLabel = computed(() => isMandatorySetup.value)

    function handleMaybeLater() {
        if (isMandatorySetup.value) {
            return
        }

        router.push({ name: 'accounts' })
    }

    const setupForm = reactive(new Form({
        masterPassword: '',
        masterPassword_confirmation: '',
        understood: false,
    }))

    /**
     * Setup encryption with client-side key derivation
     */
    async function doSetup() {
        if (!setupForm.understood) {
            return
        }

        if (setupForm.masterPassword.length < 8) {
            setupForm.errors.set('masterPassword', t('validation.min_string', { attribute: t('field.master_password'), min: 8 }))
            return
        }

        if (setupForm.masterPassword !== setupForm.masterPassword_confirmation) {
            setupForm.errors.set('masterPassword_confirmation', t('validation.confirmed', { attribute: t('field.master_password') }))
            return
        }

        setupForm.startProcessing()

        try {
            // Client-side: derive key and create test value
            const { salt, testValue } = await cryptoStore.setupEncryption(setupForm.masterPassword)

            // Send only salt + test value to server (NEVER the password)
            await apiClient.post('/encryption/setup', {
                encryption_salt: salt,
                encryption_test_value: testValue,
                encryption_version: 1,
            })

            await apiClient.post('/encryption/verify', {
                verification_result: true,
            })

            userStore.encryption_version = 1
            userStore.vault_locked = false

            notify.success({ text: t('notification.encryption_enabled') })
            router.push({ name: 'accounts' })
        } catch (err) {
            cryptoStore.reset()
            userStore.encryption_version = 0
            userStore.vault_locked = false
            const msg = err.response?.data?.message || t('error.encryption_setup_failed')
            setupForm.errors.set('masterPassword', msg)
        } finally {
            setupForm.finishProcessing()
        }
    }

    onBeforeRouteLeave(() => {
        notify.clear()
    })
</script>

<template>
    <StackLayout>
        <template #content>
            <FormWrapper :title="'heading.end_to_end_encryption'" :punchline="'message.e2ee_description'">
                <div class="block">
                    <ul class="mb-3">
                        <li class="mb-1">{{ $t('message.e2ee_benefit_1') }}</li>
                        <li class="mb-1">{{ $t('message.e2ee_benefit_2') }}</li>
                        <li class="mb-1">{{ $t('message.e2ee_benefit_3') }}</li>
                    </ul>
                    <div class="notification is-warning is-light">
                        {{ $t('message.e2ee_warning_setup') }}
                    </div>
                </div>
                <form @submit.prevent="doSetup" @keydown="setupForm.onKeydown($event)">
                    <FormField v-model="setupForm.masterPassword" fieldName="masterPassword" :errorMessage="setupForm.errors.get('masterPassword')" inputType="password" autocomplete="new-password" label="field.master_password" help="field.master_password.help" />
                    <FormField v-model="setupForm.masterPassword_confirmation" fieldName="masterPassword_confirmation" :errorMessage="setupForm.errors.get('masterPassword_confirmation')" inputType="password" autocomplete="new-password" label="field.confirm_password" />
                    <FormCheckbox v-model="setupForm.understood" fieldName="understood" label="field.understand_data_loss" />
                    <FormButtons :isBusy="setupForm.isBusy" :isDisabled="!setupForm.understood" submitLabel="label.enable_encryption" submitId="btnEnableEncryption" />
                </form>
                <div v-if="!isMandatorySetupLabel" class="nav-links">
                    <p><a class="is-link" @click="handleMaybeLater">{{ $t('link.maybe_later') }}</a></p>
                </div>
            </FormWrapper>
        </template>
        <template #footer>
            <VueFooter />
        </template>
    </StackLayout>
</template>
