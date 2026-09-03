<script setup>
    import Form from '@/components/formElements/Form'
    import QrContentDisplay from '@/components/QrContentDisplay.vue'
    import { FormProtectedField } from '@2fauth/formcontrols'
    import twofaccountService from '@/services/twofaccountService'
    import { useUserStore } from '@/stores/user'
    import { useTwofaccounts } from '@/stores/twofaccounts'
    import { useGroups } from '@/stores/groups'
    import { useBusStore } from '@/stores/bus'
    import { useTeamsStore } from '@/stores/teams'
    import { useNotify, OtpDisplay, RecoveryCodesField } from '@2fauth/ui'
    import { UseColorMode } from '@vueuse/components'
    import { useI18n } from 'vue-i18n'
    import { useErrorHandler } from '@2fauth/stores'
    import { useIconManager } from '@/composables/useIconManager'
    import { useQrUpload } from '@/composables/useQrUpload'
    import { useAccountEncryption } from '@/composables/useAccountEncryption'
    import {
        LucideHardDriveUpload,
        LucideImageUp,
        LucideQrCode,
        LucideWandSparkles,
        LucideRefreshCw
    } from 'lucide-vue-next'

    const errorHandler = useErrorHandler()
    const { t } = useI18n()
    const $2fauth = inject('2fauth')
    const router = useRouter()
    const route = useRoute()
    const user = useUserStore()
    const twofaccounts = useTwofaccounts()
    const bus = useBusStore()
    const notify = useNotify()

    const form = reactive(new Form({
        service: '', account: '', otp_type: '', icon: '',
        group_id: user.preferences.defaultGroup == -1 ? user.preferences.activeGroup : user.preferences.defaultGroup,
        secret: '', algorithm: '', digits: null, counter: null, period: null, image: '',
        notes: '',
        recovery_codes: '',
    }))

    const qrcodeForm = reactive(new Form({ qrcode: null }))
    const iconForm = reactive(new Form({ icon: null }))

    const showQuickForm = ref(false)
    const showAlternatives = ref(false)
    const showOtpInModal = ref(false)
    const showAdvancedForm = ref(false)
    const ShowTwofaccountInModal = ref(false)
    const showSpinner = ref(false)

    const accountParams = ref({
        otp_type: '', account: '', service: '', icon: '',
        secret: '', digits: null, algorithm: '', period: null, counter: null, image: ''
    })

    const otp_types = [
        { text: 'TOTP', value: 'totp' }, { text: 'HOTP', value: 'hotp' }, { text: 'STEAM', value: 'steamtotp' },
    ]
    const digitsChoices = [
        { text: '6', value: 6 }, { text: '7', value: 7 }, { text: '8', value: 8 },
        { text: '9', value: 9 }, { text: '10', value: 10 },
    ]
    const algorithms = [
        { text: 'sha1', value: 'sha1' }, { text: 'sha256', value: 'sha256' },
        { text: 'sha512', value: 'sha512' }, { text: 'md5', value: 'md5' },
    ]

    // Composables — icon manager must init first so tempIcon ref is available for QR upload.
    // Hoisted to the top of <script setup>: useAccountEncryption() calls useI18n() internally,
    // which requires an active component instance. Calling it inside the onMounted async
    // callback below would run after setup has returned (getCurrentInstance() === null) and
    // throw vue-i18n error 26 ("Must be called at the top of a `setup` function"), aborting
    // the edit form render.
    const { encryptSecret, decryptSecret } = useAccountEncryption()
    // Auto-imported components (BreachCheckButton) are available in template without import.
    const {
        tempIcon, fetchingLogo, iconCollection, iconCollectionVariant,
        iconPack, iconPacks, hasSomeIconPack, isLoading,
        iconCollections, iconCollectionVariants,
        uploadIcon, deleteTempIcon, fetchLogo, refreshIconPackList,
    } = useIconManager(form, { showQuickForm, OtpDisplayForQuickForm: null })
    const { uri, uploadQrcode } = useQrUpload(form, {
        tempIcon, showAlternatives, showAdvancedForm,
    })

    // Share with Team
    const teamsStore = useTeamsStore()
    const showShareModal = ref(false)
    const shareForm = reactive({ team_id: '', access_level: 'read' })
    const isSharing = ref(false)

    // $refs
    const iconInput = ref(null)
    const OtpDisplayForAutoSave = ref(null)
    const OtpDisplayForQuickForm = ref(null)
    const OtpDisplayForAdvancedForm = ref(null)
    const qrcodeInputLabel = ref(null)
    const qrcodeInput = ref(null)
    const iconInputLabel = ref(null)

    const props = defineProps({ twofaccountId: [Number, String] })

    const isEditMode = computed(() => props.twofaccountId != undefined)

    const groups = computed(() => {
        return useGroups().items.map((item) => ({
            text: item.id > 0 ? item.name : '- ' + t('label.no_group') + ' -', value: item.id
        }))
    })

    const shareableTeams = computed(() => teamsStore.teams.map(team => ({
        text: team.name, value: team.id
    })))

    const accessLevels = [
        { text: 'label.read', value: 'read' }, { text: 'label.write', value: 'write' },
    ]

    async function shareWithTeam() {
        if (!shareForm.team_id) return
        isSharing.value = true
        try {
            await teamsStore.shareAccount(shareForm.team_id, props.twofaccountId, shareForm.access_level)
            notify.success({ text: t('teams.share_success') })
            showShareModal.value = false
            shareForm.team_id = ''
            shareForm.access_level = 'read'
        } catch (error) {
            notify.error({ text: error.response?.data?.message || t('teams.share_error') })
        } finally { isSharing.value = false }
    }

    onMounted(() => {
        if (route.name == 'editAccount') {
            showSpinner.value = true
            twofaccountService.get(props.twofaccountId).then(async response => {
                // decryptSecret is the hoisted reference from the top of <script setup>;
                // do not re-invoke useAccountEncryption() here (see note above the hoist).
                response.data.secret = await decryptSecret(response.data.secret)
                form.fill(response.data)
                if (form.group_id == null) form.group_id = 0
                form.setOriginal()
                tempIcon.value = form.icon
                showAdvancedForm.value = true
            }).finally(() => { showSpinner.value = false })
        }
        else if (bus.decodedUri) {
            uri.value = bus.decodedUri
            bus.decodedUri = null
            showSpinner.value = true

            if (user.preferences.AutoSaveQrcodedAccount) {
                twofaccountService.storeFromUri(uri.value).then(response => {
                    showOTP(response.data)
                })
                .catch(error => {
                    if (error.response.data.errors.uri) {
                        showAlternatives.value = true
                        showAdvancedForm.value = true
                    }
                })
                .finally(() => { showSpinner.value = false })
            } else {
                twofaccountService.preview(uri.value).then(response => {
                    form.fill(response.data)
                    tempIcon.value = response.data.icon ? response.data.icon : ''
                    showQuickForm.value = true
                    nextTick().then(() => { OtpDisplayForQuickForm.value.show() })
                })
                .catch(error => {
                    if (error.response.data.errors.uri) {
                        showAlternatives.value = true
                        showAdvancedForm.value = true
                    }
                })
                .finally(() => { showSpinner.value = false })
            }
        } else {
            showAdvancedForm.value = true
        }
        refreshIconPackList()
    })

    watch(ShowTwofaccountInModal, (val) => {
        if (val == false) {
            OtpDisplayForAdvancedForm.value?.clearOTP()
            OtpDisplayForQuickForm.value?.clearOTP()
        }
    })

    watch(showOtpInModal, (val) => {
        if (val == false) {
            OtpDisplayForAutoSave.value?.clearOTP()
            router.push({ name: 'accounts' })
        }
    })

    watch(() => form.otp_type, (to, from) => {
        if (to === 'steamtotp') { form.service = 'Steam'; fetchLogo() }
        else if (from === 'steamtotp') { form.service = ''; deleteTempIcon() }
    })

    function handleSubmit() {
        isEditMode.value ? updateAccount() : createAccount()
    }

    async function createAccount() {
        form.icon = tempIcon.value
        const encrypted = await encryptSecret(form.secret)
        if (encrypted === null) return
        form.secret = encrypted

        const { data } = await form.post('/api/v1/twofaccounts')
        if (form.errors.any() === false) {
            twofaccounts.items.push(data)
            twofaccounts.sortDefault()
            notify.success({ text: t('notification.account_created') })
            router.push({ name: 'accounts' })
        }
    }

    async function updateAccount() {
        if (tempIcon.value !== form.icon) {
            const oldIcon = form.icon
            form.icon = tempIcon.value
            tempIcon.value = oldIcon
            deleteTempIcon()
        }
        const encrypted = await encryptSecret(form.secret)
        if (encrypted === null) return
        form.secret = encrypted

        const { data } = await form.put('/api/v1/twofaccounts/' + props.twofaccountId)
        if (form.errors.any() === false) {
            // RT7/B7: when the secret changed on a shared account, the server
            // dropped every member's wrapped key (it cannot re-wrap under
            // E2EE). Tell the owner so they re-share from the team page.
            if (data.shares_revoked === true) {
                notify.warn({ text: t('warning.shares_revoked_re_share') })
            }
            const index = twofaccounts.items.findIndex(acc => acc.id === data.id)
            if (index !== -1) {
                twofaccounts.items.splice(index, 1, data)
            }
            twofaccounts.sortDefault()
            notify.success({ text: t('notification.account_updated') })
            router.push({ name: 'accounts' })
        }
    }

    function previewOTP() {
        form.clear()
        ShowTwofaccountInModal.value = true
        OtpDisplayForAdvancedForm.value.show()
    }

    function showOTP(otp) {
        accountParams.value.otp_type = otp.otp_type
        accountParams.value.service = otp.service
        accountParams.value.account = otp.account
        accountParams.value.icon = otp.icon
        nextTick().then(() => {
            showOtpInModal.value = true
            OtpDisplayForAutoSave.value.show(otp.id)
        })
    }

    function cancelCreation() {
        if (form.hasChanged() || tempIcon.value != form.icon) {
            if (confirm(t('confirmation.cancel_creation')) === true) {
                if (!isEditMode.value || tempIcon.value != form.icon) deleteTempIcon()
                router.push({ name: 'accounts' })
            }
        } else router.push({ name: 'accounts' })
    }

    function incrementHotp(payload) { form.counter = payload.nextHotpCounter }

    function mapDisplayerErrors(errorResponse) {
        form.errors.set(form.extractErrors(errorResponse))
    }

    function strip_tags(str) { return str.replace(/(<([^> ]+)>)/ig, "") }

    function saveActiveGroup(newActiveGroupId) {
        if (user.preferences.activeGroup != newActiveGroupId) {
            user.preferences.activeGroup = newActiveGroupId
        }
        if (user.preferences.rememberActiveGroup) {
            userService.updatePreference('activeGroup', user.preferences.activeGroup)
        }
    }

    function onUploadIcon() { uploadIcon(iconForm, iconInput.value) }
    function onDeleteTempIcon() { deleteTempIcon(isEditMode.value) }
    function onUploadQrcode() { uploadQrcode(qrcodeForm, qrcodeInput.value) }
</script>

<template>
    <UseColorMode v-slot="{ mode }">
    <StackLayout>
        <template #content>
            <!-- otp display modal (when auto-save is enabled) -->
            <Modal v-if="user.preferences.AutoSaveQrcodedAccount" v-model="showOtpInModal">
                <OtpDisplay
                    ref="OtpDisplayForAutoSave"
                    :accountParams="accountParams"
                    :preferences="user.preferences"
                    :twofaccountService="twofaccountService"
                    :iconPathPrefix="$2fauth.config.subdirectory"
                    @please-close-me="router.push({ name: 'accounts' })"
                    @please-update-activeGroup="saveActiveGroup"
                    @otp-copied-to-clipboard="notify.success({ text: t('notification.copied_to_clipboard') })"
                    @error="(error) => errorHandler.show(error)"
                />
            </Modal>
            <!-- Quick form (right after a qr code upload) -->
            <div v-if="!isEditMode && showQuickForm" class="modal modal-otp is-active">
                <div class="modal-background"></div>
                <div class="modal-card is-flex-grow-1">
                    <section class="modal-card-body modal-slot py-0 is-align-content-center has-text-centered">
                        <form @submit.prevent="createAccount" @keydown="form.onKeydown($event)">
                            <div>
                                <FormFieldError v-if="iconForm.errors.hasAny('icon')" :error="iconForm.errors.get('icon')" :field="'icon'" class="help-for-file" />
                                <label for="filUploadIcon" class="add-icon-button pt-2" v-if="!tempIcon">
                                    <input id="filUploadIcon" class="file-input" type="file" accept="image/*" v-on:change="onUploadIcon" ref="iconInput">
                                    <LucideImageUp class="icon-size-3" />
                                </label>
                                <button type="button" class="delete delete-icon-button is-medium" v-if="tempIcon" @click.prevent="onDeleteTempIcon"></button>
                                <OtpDisplay
                                    ref="OtpDisplayForQuickForm"
                                    :accountParams="form.data()"
                                    :preferences="user.preferences"
                                    :twofaccountService="twofaccountService"
                                    :iconPathPrefix="$2fauth.config.subdirectory"
                                    :can_autoCloseTimeout="false"
                                    @increment-hotp="incrementHotp"
                                    @please-close-me="ShowTwofaccountInModal = false"
                                    @please-update-activeGroup="saveActiveGroup"
                                    @otp-copied-to-clipboard="notify.success({ text: t('notification.copied_to_clipboard') })"
                                    @validation-error="mapDisplayerErrors"
                                    @error="(error) => errorHandler.show(error)"
                                />
                            </div>
                            <div v-if="form.errors.any()" role="alert" class="m-3">
                                <ul v-for="(field, index) in form.errors.errors" :key="index" class="help is-danger">
                                    <li v-for="(error, index) in field" :key="index">{{ error }}</li>
                                </ul>
                            </div>
                            <div class="field is-grouped is-grouped-centered mt-6">
                                <div class="control">
                                    <VueButton nativeType="submit" :isLoading="form.isBusy" >{{ $t('label.save') }}</VueButton>
                                </div>
                                <NavigationButton action="cancel" :isText="true" :isRounded="false" :useLinkTag="false" @canceled="cancelCreation" />
                            </div>
                        </form>
                    </section>
                </div>
            </div>
            <!-- Full form -->
            <FormWrapper v-if="showAdvancedForm" :title="isEditMode ? 'heading.edit_account' : 'heading.new_account'">
                <form @submit.prevent="handleSubmit" @keydown="form.onKeydown($event)">
                    <!-- qrcode fileupload -->
                    <div v-if="!isEditMode" class="field is-grouped">
                        <div class="control">
                            <div role="button" tabindex="0" class="file is-small" :class="{ 'is-black': mode == 'dark' }" @keyup.enter="qrcodeInputLabel.click()">
                                <label class="file-label" :title="$t('tooltip.use_qrcode')" ref="qrcodeInputLabel">
                                    <input inert tabindex="-1" class="file-input" type="file" accept="image/*" v-on:change="onUploadQrcode" ref="qrcodeInput">
                                    <span class="file-cta">
                                        <span class="file-label">
                                            <LucideQrCode class="mr-2" />{{ $t('label.prefill_using_qrcode') }}
                                        </span>
                                    </span>
                                </label>
                            </div>
                        </div>
                    </div>
                    <FormFieldError v-if="qrcodeForm.errors.hasAny('qrcode')" :error="qrcodeForm.errors.get('qrcode')" :field="'qrcode'" class="help-for-file" />
                    <!-- service -->
                    <FormField v-model="form.service" fieldName="service" :errorMessage="form.errors.get('email')" :isDisabled="form.otp_type === 'steamtotp'" label="field.service" :placeholder="$t('field.service.placeholder')" autofocus />
                    <!-- account -->
                    <FormField v-model="form.account" fieldName="account" :errorMessage="form.errors.get('account')" label="field.account" :placeholder="$t('field.account.placeholder')" />
                    <!-- icon upload -->
                    <label v-if="user.preferences.iconSource == 'logolib'" for="icon-collection" class="label">{{ $t('field.icon') }}</label>
                    <!-- try my luck -->
                    <div v-if="user.preferences.iconSource == 'logolib'" class="field has-addons">
                        <div class="control">
                            <div class="select">
                                <select :disabled="!form.service" name="icon-collection" v-model="iconCollection">
                                    <option v-for="collection in iconCollections" :key="collection.text" :value="collection.value">
                                        {{ collection.text }}
                                    </option>
                                </select>
                            </div>
                        </div>
                        <div v-if="iconCollectionVariants[iconCollection]" class="control">
                            <div class="select">
                                <select :disabled="!form.service" name="icon-collection-variant" v-model="iconCollectionVariant">
                                    <option v-for="variant in iconCollectionVariants[iconCollection]" :key="variant.value" :value="variant.value">
                                        {{ $t(variant.text) }}
                                    </option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <!-- icon pack -->
                    <FormSelect v-else-if="user.preferences.iconSource == 'iconpack'" v-model="iconPack" :options="iconPacks" fieldName="iconPack" :isDisabled="!form.service" label="field.icon">
                        <button class="button is-ghost" @click="refreshIconPackList" type="button" :title="$t('tooltip.refresh_icon_pack_list')">
                            <LucideRefreshCw :class="{ 'spinning': isLoading }"/>
                        </button>
                    </FormSelect>
                    <!-- icon field buttons -->
                    <div class="field is-grouped">
                        <div class="control">
                            <VueButton @click="fetchLogo" :color="mode == 'dark' ? 'is-dark' : ''" nativeType="button" :is-loading="fetchingLogo" :disabled="!form.service || (user.preferences.iconSource == 'iconpack' && !hasSomeIconPack)" aria-describedby="lgdTryMyLuck">
                                <span class="icon is-small"><LucideWandSparkles /></span>
                                <span>{{ $t('label.i_m_lucky') }}</span>
                            </VueButton>
                        </div>
                        <div class="control is-flex">
                            <div role="button" tabindex="0" class="file mr-3" :class="mode == 'dark' ? 'is-dark' : 'is-white'" @keyup.enter="iconInputLabel.click()">
                                <label for="filUploadIcon" class="file-label" ref="iconInputLabel">
                                    <input id="filUploadIcon" tabindex="-1" class="file-input" type="file" accept="image/*" v-on:change="onUploadIcon" ref="iconInput">
                                    <span class="file-cta">
                                        <span class="icon"><LucideImageUp /></span>
                                        <span class="file-label"><span class="is-hidden-mobile ml-1">{{ $t('label.choose_image') }}</span></span>
                                    </span>
                                </label>
                            </div>
                            <span class="tag is-large" :class="mode =='dark' ? 'is-dark' : 'is-white'" v-if="tempIcon">
                                <img class="icon-preview" :src="$2fauth.config.subdirectory + '/storage/icons/' + tempIcon" :alt="$t('alttext.icon_to_illustrate_the_account')">
                                <button type="button" class="clear-selection delete is-small" @click.prevent="onDeleteTempIcon" :aria-label="$t('label.remove_icon')"></button>
                            </span>
                        </div>
                    </div>
                    <div class="field">
                        <FormFieldError v-if="iconForm.errors.hasAny('icon')" :error="iconForm.errors.get('icon')" :field="'icon'" class="help-for-file" />
                        <p id="lgdTryMyLuck" v-if="user.preferences.getOfficialIcons" class="help">{{ $t('message.i_m_lucky_legend') }}</p>
                    </div>
                    <!-- group -->
                    <FormSelect v-if="groups.length > 0" v-model="form.group_id" :options="groups" fieldName="group_id" label="field.group" help="field.group.help" />
                    <!-- notes -->
                    <div class="field">
                        <label class="label">{{ $t('field.notes') }}</label>
                        <div class="control">
                            <textarea class="textarea" v-model="form.notes" maxlength="5000" :placeholder="$t('field.notes')"></textarea>
                        </div>
                        <p class="help">{{ $t('field.notes.help') }}</p>
                    </div>
                    <!-- recovery codes (external-service backup codes) -->
                    <RecoveryCodesField v-model="form.recovery_codes" :maxlength="10000" />
                    <!-- breach check (service only; public data, no opt-in needed) -->
                    <BreachCheckButton v-if="isEditMode" :service="form.service" />
                    <!-- otp type -->
                    <FormToggle v-model="form.otp_type" :isDisabled="isEditMode" :choices="otp_types" fieldName="otp_type" :errorMessage="form.errors.get('otp_type')" label="field.otp_type" help="field.otp_type.help" :hasOffset="true" />
                    <div v-if="form.otp_type != ''">
                        <FormProtectedField :enableProtection="isEditMode" v-model.trimAll="form.secret" fieldName="secret" :errorMessage="form.errors.get('secret')" label="field.secret" help="field.secret.help" />
                        <div v-if="form.otp_type !== 'steamtotp'">
                            <h2 class="title is-4 mt-5 mb-2">{{ $t('heading.options') }}</h2>
                            <p class="help mb-4">{{ $t('field.options.help') }}</p>
                            <FormToggle v-model="form.digits" :choices="digitsChoices" fieldName="digits" :errorMessage="form.errors.get('digits')" label="field.digits" help="field.digits.help" />
                            <FormToggle v-model="form.algorithm" :choices="algorithms" fieldName="algorithm" :errorMessage="form.errors.get('algorithm')" label="field.algorithm" help="field.algorithm.help" />
                            <FormField v-if="form.otp_type === 'totp'" pattern="[0-9]{1,4}" :class="'is-half-width-field'" v-model="form.period" fieldName="period" :errorMessage="form.errors.get('period')" label="field.period" help="field.period.help" :placeholder="$t('field.period.placeholder')" />
                            <FormProtectedField v-if="form.otp_type === 'hotp'" pattern="[0-9]{1,4}" :enableProtection="isEditMode" :isExpanded="false" v-model="form.counter" fieldName="counter" :errorMessage="form.errors.get('counter')" label="field.counter" :placeholder="$t('field.counter.placeholder')" :help="isEditMode ? 'field.counter.help_lock' : 'field.counter.help'" />
                        </div>
                    </div>
                </form>
                <!-- otp display modal (for previewing) -->
                <Modal v-model="ShowTwofaccountInModal">
                    <OtpDisplay
                        ref="OtpDisplayForAdvancedForm"
                        :accountParams="form.data()"
                        :preferences="user.preferences"
                        :twofaccountService="twofaccountService"
                        :iconPathPrefix="$2fauth.config.subdirectory"
                        :can_autoCloseTimeout="false"
                        @increment-hotp="incrementHotp"
                        @please-close-me="ShowTwofaccountInModal = false"
                        @otp-copied-to-clipboard="notify.success({ text: t('notification.copied_to_clipboard') })"
                        @validation-error="mapDisplayerErrors"
                        @error="(error) => errorHandler.show(error)"
                    />
                </Modal>
            </FormWrapper>
            <!-- alternatives -->
            <Modal v-model="showAlternatives">
                <QrContentDisplay :qrContent="uri" />
            </Modal>
        </template>
        <template #footer>
            <VueFooter>
                <template #default>
                    <p class="control">
                        <VueButton nativeType="submit" @click="handleSubmit" :id="isEditMode ? 'btnUpdate' : 'btnCreate'" :isLoading="form.isBusy" class="is-rounded">
                            {{ isEditMode ? $t('label.save') : $t('label.create') }}
                        </VueButton>
                    </p>
                    <p class="control" v-if="form.otp_type && form.secret">
                        <button id="btnPreview" type="button" class="button is-success is-rounded has-text-white" @click="previewOTP">{{ $t('label.test') }}</button>
                    </p>
                    <p class="control" v-if="isEditMode && shareableTeams.length > 0">
                        <button type="button" class="button is-link is-rounded" @click="showShareModal = true">{{ $t('teams.share_with_team') }}</button>
                    </p>
                    <NavigationButton action="cancel" :useLinkTag="false" @canceled="cancelCreation" />
                </template>
            </VueFooter>
        </template>
    </StackLayout>
    <Spinner v-if="showSpinner" :type="'fullscreen'" :isVisible="true" message="message.parsing_data" />
    <!-- Share with Team modal -->
    <Modal v-model="showShareModal">
        <div class="modal-card">
            <header class="modal-card-head">
                <p class="modal-card-title">{{ $t('teams.share_with_team') }}</p>
                <button class="delete" :aria-label="$t('label.close')" @click="showShareModal = false"></button>
            </header>
            <section class="modal-card-body">
                <FormSelect v-model="shareForm.team_id" :options="shareableTeams" fieldName="team_id" label="teams.select_team" />
                <FormToggle v-model="shareForm.access_level" :choices="accessLevels" fieldName="access_level" label="teams.select_access_level" />
            </section>
            <footer class="modal-card-foot">
                <VueButton nativeType="button" :isLoading="isSharing" :disabled="!shareForm.team_id" @click="shareWithTeam">{{ $t('label.share') }}</VueButton>
                <NavigationButton action="cancel" :isText="true" :isRounded="false" :useLinkTag="false" @canceled="showShareModal = false" />
            </footer>
        </div>
    </Modal>
    </UseColorMode>
</template>
