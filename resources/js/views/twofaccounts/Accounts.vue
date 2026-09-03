<script setup>
    import twofaccountService from '@/services/twofaccountService'
    import { httpClientFactory } from '@/services/httpClientFactory'
    import DestinationGroupSelector from '@/components/DestinationGroupSelector.vue'
    import Toolbar from '@/components/Toolbar.vue'
    import ActionButtons from '@/components/ActionButtons.vue'
    import ExportButtons from '@/components/ExportButtons.vue'
    import { UseColorMode } from '@vueuse/components'
    import { useUserStore } from '@/stores/user'
    import {
        useNotify, SearchBox, GroupSwitch, OtpDisplay, Dots, DotsController, useVisiblePassword
    } from '@2fauth/ui'
    import { useBusStore } from '@/stores/bus'
    import { useTwofaccounts } from '@/stores/twofaccounts'
    import { useGroups } from '@/stores/groups'
    import { useI18n } from 'vue-i18n'
    import { useErrorHandler } from '@2fauth/stores'
    import { useOtpDisplay } from '@/composables/useOtpDisplay'
    import { useAccountSort } from '@/composables/useAccountSort'
    import { LucideChevronDown, LucideCircleAlert, LucideEye, LucideEyeOff, LucideMenu, LucidePin, LucideQrCode } from 'lucide-vue-next'

    const errorHandler = useErrorHandler()
    const { t } = useI18n()
    const $2fauth = inject('2fauth')
    const router = useRouter()
    const notify = useNotify()
    const user = useUserStore()
    const bus = useBusStore()
    const twofaccounts = useTwofaccounts()
    const groups = useGroups()

    // Dedicated api client for optimistic pin PATCH (twofaccountService exposes no generic update method)
    const apiClient = httpClientFactory('api')

    const showExportFormatSelector = ref(false)
    const showGroupSwitch = ref(false)
    const showDestinationGroupSelector = ref(false)
    const isDragging = ref(false)
    const revealPassword = ref(null)
    const opacities = ref({})

    const otpDisplay = ref(null)
    const dotsControllers = ref([])
    const dotsRefs = ref([])
    const renewedPeriod = ref(null)

    // Composables
    const { setSortable } = useAccountSort()
    const {
        showOtpInModal, accountParams,
        turnDotsOn, turnDotsOff, updateTotps,
        showOrCopy, copyToClipboard, getAndCopyOTP,
    } = useOtpDisplay({ otpDisplay, dotsRefs, dotsControllers, opacities, renewedPeriod })

    const showAccounts = computed(() => {
        return !twofaccounts.isEmpty && !showGroupSwitch.value && !showDestinationGroupSelector.value
    })

    watch(showOtpInModal, (val) => {
        if (val == false) otpDisplay.value?.clearOTP()
    })

    // E8: when the vault locks (inactivity auto-lock), close any open OTP
    // modal and clear its plaintext so an unattended screen shows nothing.
    watch(() => bus.vaultLockedAt, () => {
        if (bus.vaultLockedAt > 0) {
            showOtpInModal.value = false
            otpDisplay.value?.clearOTP()
        }
    })

    watch(() => twofaccounts.items, (val) => {
        if (bus.inManagementMode) setSortable()
    })

    watch(() => bus.inManagementMode, (val) => {
        if (val) setSortable()
    })

    // B9: entering the "shared with me" virtual group loads the shared slice
    // into the store (flagged is_shared); every other group view hides it.
    watch(() => user.preferences.activeGroup, (val) => {
        if (parseInt(val) === -4) {
            twofaccounts.fetchSharedWithMe().catch(() => {})
        }
    }, { immediate: true })

    onMounted(async () => {
        if (!user.preferences.getOtpOnRequest) {
            updateTotps()
        } else {
            twofaccounts.fetch().then(() => {
                if (twofaccounts.backendWasNewer) {
                    notify.info({ text: t('notification.data_refreshed_to_reflect_server_changes'), duration: 10000 })
                }
            })
        }
        groups.fetch()
    })

    function postGroupAssignementUpdate() {
        twofaccounts.fetch()
        twofaccounts.selectNone()
        showDestinationGroupSelector.value = false
        notify.success({ text: t('notification.accounts_moved') })
    }

    async function deleteAccounts() {
        await twofaccounts.deleteSelected()
        if (twofaccounts.isEmpty) {
            bus.inManagementMode = false
            router.push({ name: 'start' })
        }
    }

    function exitManagementMode() {
        bus.inManagementMode = false
        twofaccounts.selectNone()
    }

    function saveActiveGroup(newActiveGroupId) {
        twofaccounts.groupLessOnly = false
        if (user.preferences.activeGroup != newActiveGroupId) {
            user.preferences.activeGroup = newActiveGroupId
        }
        if (user.preferences.rememberActiveGroup) {
            userService.updatePreference('activeGroup', user.preferences.activeGroup)
        }
    }

    function onStart() { isDragging.value = true }
    function onEnd() { isDragging.value = false }

    /**
     * Toggles the is_pinned flag on an account optimistically.
     * Sends a PATCH to the backend; reverts on error.
     */
    function togglePin(account) {
        const previous = account.is_pinned
        account.is_pinned = !account.is_pinned
        apiPatchPin(account.id, account.is_pinned).then(() => {
            notify.success({ text: t('notification.pin_updated') })
        }).catch(() => {
            account.is_pinned = previous
            notify.alert({ text: t('notification.pin_update_failed') })
        })
    }

    async function apiPatchPin(id, isPinned) {
        // twofaccountService has no generic patch method; call the axios api client directly.
        return apiClient.patch('/twofaccounts/' + id, { is_pinned: isPinned }, { returnError: true })
    }
</script>

<template>
    <UseColorMode v-slot="{ mode }">
    <div>
        <StackLayout>
            <template #header>
                <div class="header" v-if="showAccounts || showGroupSwitch">
                    <div class="columns is-gapless is-mobile is-centered">
                        <div class="column is-three-quarters-mobile is-one-third-tablet is-one-quarter-desktop is-one-quarter-widescreen is-one-quarter-fullhd">
                            <SearchBox v-model:keyword="twofaccounts.filter"/>
                        </div>
                    </div>
                </div>
            </template>
            <template #subheader v-if="!showDestinationGroupSelector">
                <Toolbar v-if="bus.inManagementMode"
                    v-model:sortOrder="user.preferences.sortOrder"
                    :selectedCount="twofaccounts.selectedCount"
                    @clear-selected="twofaccounts.selectNone()"
                    @select-all="twofaccounts.selectAll()"
                    @sort-asc="twofaccounts.sortAsc()"
                    @sort-desc="twofaccounts.sortDesc()">
                </Toolbar>
                <div v-else class="has-text-centered">
                    <div v-if="showGroupSwitch">
                        <button type="button" id="btnHideGroupSwitch" :title="$t('tooltip.hide_group_selector')" tabindex="1" class="button is-text is-like-text has-text-grey-dark" :class="{'has-text-grey' : mode != 'dark'}" @click.stop="showGroupSwitch = !showGroupSwitch">
                            {{ $t('label.select_accounts_to_show') }}
                        </button>
                    </div>
                    <div v-else>
                        <button type="button" id="btnShowGroupSwitch" :title="$t('tooltip.show_group_selector')" tabindex="1" class="button is-text is-like-text has-text-grey-dark" :class="{'has-text-grey' : mode != 'dark'}" @click.stop="showGroupSwitch = !showGroupSwitch">
                            <template v-if="twofaccounts.groupLessOnly">{{ $t('label.group_less') }} ({{ twofaccounts.filteredCount }})&nbsp;</template>
                            <template v-else-if="groups.current">{{ groups.current }} ({{ twofaccounts.filteredCount }})&nbsp;</template>
                            <template v-else>{{ $t('label.all') }} ({{ twofaccounts.filteredCount }})&nbsp;</template>
                            <LucideChevronDown class="mt-1" />
                        </button>
                    </div>
                </div>
            </template>
            <template #content>
                <GroupSwitch v-if="showGroupSwitch" v-model:is-visible="showGroupSwitch" v-model:active-group="user.preferences.activeGroup" :groups="groups.items" @active-group-changed="saveActiveGroup" @show-group-less="twofaccounts.groupLessOnly = true">
                    <RouterLink :to="{ name: 'groups' }" >{{ $t('link.manage_groups') }}</RouterLink>
                </GroupSwitch>
                <DestinationGroupSelector v-if="showDestinationGroupSelector" v-model:showDestinationGroupSelector="showDestinationGroupSelector" v-model:selectedAccountsIds="twofaccounts.selectedIds" :groups="groups.items" @accounts-moved="postGroupAssignementUpdate"></DestinationGroupSelector>
                <div class="accounts-container" v-if="showAccounts" :class="bus.inManagementMode ? 'is-edit-mode' : ''">
                    <div class="accounts">
                        <span id="dv" class="columns is-multiline m-0" :class="{ 'is-centered': user.preferences.displayMode === 'grid' }">
                            <div :class="[user.preferences.displayMode === 'grid' ? 'tfa-grid' : 'tfa-list']" class="column is-narrow" v-for="account in twofaccounts.filtered" :key="account.id">
                                <div class="tfa-container">
                                    <transition name="slideCheckbox">
                                        <div class="tfa-cell tfa-checkbox" v-if="bus.inManagementMode">
                                            <div class="field">
                                                <input class="is-checkradio is-small" :class="mode == 'dark' ? 'is-white':'is-info'" :id="'ckb_' + account.id" :value="account.id" type="checkbox" :name="'ckb_' + account.id" v-model="twofaccounts.selectedIds">
                                                <label tabindex="0" :for="'ckb_' + account.id" v-on:keypress.space.prevent="twofaccounts.select(account.id)"></label>
                                            </div>
                                        </div>
                                    </transition>
                                    <div tabindex="0" class="tfa-cell tfa-content is-size-3 is-size-4-mobile" @click.exact="showOrCopy(account)" @keyup.enter="showOrCopy(account)" @click.ctrl="getAndCopyOTP(account)" role="button">
                                        <div class="tfa-text has-ellipsis">
                                            <img v-if="account.icon && user.preferences.showAccountsIcons" role="presentation" class="tfa-icon" :src="$2fauth.config.subdirectory + '/storage/icons/' + account.icon" alt="">
                                            <img v-else-if="account.icon == null && user.preferences.showAccountsIcons" role="presentation" class="tfa-icon" :src="$2fauth.config.subdirectory + '/storage/noicon.svg'" alt="">
                                            {{ account.service ? account.service : $t('message.no_service') }}<LucideCircleAlert class="has-text-danger ml-2" v-if="account.account === $t('error.indecipherable')" />
                                            <span class="is-block has-ellipsis is-family-primary is-size-6 is-size-7-mobile has-text-grey">{{ account.account }}</span>
                                        </div>
                                    </div>
                                    <transition name="popLater">
                                        <div v-show="user.preferences.getOtpOnRequest == false && !bus.inManagementMode" class="has-text-right">
                                            <div v-if="account.otp != undefined">
                                                <div class="always-on-otp is-clickable has-nowrap has-text-grey is-size-5 ml-4" @click="copyToClipboard(account.otp.password)" @keyup.enter="copyToClipboard(account.otp.password)" :title="$t('tooltip.copy_to_clipboard')">
                                                    {{ useVisiblePassword(account.otp.password, user.preferences.formatPassword, user.preferences.formatPasswordBy, user.preferences.showOtpAsDot, user.preferences.revealDottedOTP && revealPassword == account.id) }}
                                                </div>
                                                <div class="has-nowrap" style="line-height: 0.9;">
                                                    <span v-if="user.preferences.showNextOtp" class="always-on-otp is-clickable has-nowrap has-text-grey is-size-7 mr-2" :class="opacities[account.period]" @click="copyToClipboard(account.otp.next_password)" @keyup.enter="copyToClipboard(account.otp.next_password)" :title="$t('tooltip.copy_next_password')">
                                                        {{ useVisiblePassword(account.otp.next_password, user.preferences.formatPassword, user.preferences.formatPasswordBy, user.preferences.showOtpAsDot, user.preferences.revealDottedOTP && revealPassword == account.id) }}
                                                    </span>
                                                    <Dots v-if="account.otp_type.includes('totp')" ref="dotsRefs" :class="'is-inline-block'" :isCondensed="true" :period="account.period" />
                                                </div>
                                            </div>
                                            <div v-else>
                                                <button type="button" class="button tag" :class="mode == 'dark' ? 'is-dark' : 'is-white'" @click="showOrCopy(account)" :title="$t('tooltip.import_this_account')">{{ $t('label.generate') }}</button>
                                            </div>
                                        </div>
                                    </transition>
                                    <!-- pin toggle (optimistic) -->
                                    <transition name="fadeInOut">
                                        <button v-if="!bus.inManagementMode" type="button" class="tfa-cell tfa-pin button is-ghost pr-0" :class="{ 'has-text-warning': account.is_pinned, 'has-text-grey': !account.is_pinned }" :title="$t('tooltip.toggle_pin')" @click.stop="togglePin(account)" :aria-label="$t('tooltip.toggle_pin')">
                                            <LucidePin />
                                        </button>
                                    </transition>
                                    <transition name="popLater" v-if="user.preferences.showOtpAsDot && user.preferences.revealDottedOTP">
                                        <div v-show="user.preferences.getOtpOnRequest == false && !bus.inManagementMode" class="has-text-right">
                                            <button v-if="revealPassword == account.id" type="button" class="pr-0 button is-ghost has-text-grey-dark" @click.stop="revealPassword = null"><LucideEye /></button>
                                            <button v-else type="button" class="pr-0 button is-ghost has-text-grey-dark" @click.stop="revealPassword = account.id"><LucideEyeOff /></button>
                                        </div>
                                    </transition>
                                    <transition name="fadeInOut">
                                        <div class="tfa-cell tfa-edit has-text-grey" v-if="bus.inManagementMode">
                                            <RouterLink :to="{ name: 'editAccount', params: { twofaccountId: account.id }}" class="tag is-rounded mr-1" :class="mode == 'dark' ? 'is-dark' : 'is-white'">{{ $t('link.edit') }}</RouterLink>
                                            <RouterLink :to="{ name: 'showQRcode', params: { twofaccountId: account.id }}" class="tag is-rounded" :class="mode == 'dark' ? 'is-dark' : 'is-white'" :title="$t('tooltip.show_qrcode')"><LucideQrCode class="icon-size-1" /></RouterLink>
                                        </div>
                                    </transition>
                                    <transition name="fadeInOut">
                                        <div class="drag-handle tfa-cell tfa-dots has-text-grey" v-if="bus.inManagementMode"><LucideMenu /></div>
                                    </transition>
                                </div>
                            </div>
                        </span>
                    </div>
                </div>
            </template>
            <template #footer v-if="showGroupSwitch">
                <VueFooter :show-buttons="true">
                    <NavigationButton action="close" :use-link-tag="false" @closed="showGroupSwitch = false" />
                </VueFooter>
            </template>
            <template #footer v-else-if="!showDestinationGroupSelector">
                <VueFooter v-if="bus.inManagementMode && !showDestinationGroupSelector">
                    <template #default>
                        <ActionButtons v-model:inManagementMode="bus.inManagementMode" :areDisabled="twofaccounts.hasNoneSelected" @move-button-clicked="showDestinationGroupSelector = true" @delete-button-clicked="deleteAccounts" @export-button-clicked="showExportFormatSelector = true"></ActionButtons>
                    </template>
                    <template #subpart>
                        <button type="button" id="lnkExitEdit" class="button is-ghost is-like-text" @click.stop="exitManagementMode">{{ $t('label.done') }}</button>
                    </template>
                </VueFooter>
                <VueFooter v-else>
                    <template #default>
                        <ActionButtons v-model:inManagementMode="bus.inManagementMode" />
                    </template>
                </VueFooter>
            </template>
        </StackLayout>
        <Modal v-model="showExportFormatSelector">
            <ExportButtons @export-twofauth-format="twofaccounts.export()" @export-otpauth-format="twofaccounts.export('otpauth')"></ExportButtons>
        </Modal>
        <Modal v-model="showOtpInModal">
            <OtpDisplay
                ref="otpDisplay"
                :accountParams="accountParams"
                :preferences="user.preferences"
                :twofaccountService="twofaccountService"
                :iconPathPrefix="$2fauth.config.subdirectory"
                @please-close-me="showOtpInModal = false"
                @please-clear-search="twofaccounts.filter = ''"
                @kickme="user.logout({ kicked: true})"
                @please-update-activeGroup="saveActiveGroup"
                @otp-copied-to-clipboard="notify.success({ text: t('notification.copied_to_clipboard') })"
                @error="(error) => errorHandler.show(error)"
            />
        </Modal>
        <span v-if="!user.preferences.getOtpOnRequest">
            <DotsController v-for="period in twofaccounts.periods" ref="dotsControllers" :key="period.period" :autostart="false" :period="period.period" :generated_at="period.generated_at" @stepping-ended="updateTotps(period.period)" @stepping-started="turnDotsOn(period.period, $event)" @stepped-up="turnDotsOn(period.period, $event)"></DotsController>
        </span>
    </div>
    </UseColorMode>
</template>
