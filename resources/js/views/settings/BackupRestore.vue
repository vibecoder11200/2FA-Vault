<script setup>
    import tabs from './tabs'
    import Form from '@/components/formElements/Form'
    import { useUserStore } from '@/stores/user'
    import { useBackupStore } from '@/stores/backup'
    import { useNotify, TabBar } from '@2fauth/ui'
    import { useI18n } from 'vue-i18n'
    import { useErrorHandler } from '@2fauth/stores'
    import { computed, ref, onMounted } from 'vue'
    import httpClientFactory from '@/services/httpClientFactory'

    const errorHandler = useErrorHandler()
    const { t } = useI18n()
    const $2fauth = inject('2fauth')
    const user = useUserStore()
    const backup = useBackupStore()
    const notify = useNotify()
    const router = useRouter()
    const returnTo = useStorage($2fauth.prefix + 'returnTo', 'accounts')
    const apiClient = httpClientFactory('api')

    const isExporting = ref(false)
    const isImporting = ref(false)
    const backupFile = ref(null)
    const showExportDialog = ref(false)
    const showImportDialog = ref(false)
    const exportPassword = ref('')
    const importPassword = ref('')

    // Backup preview state
    const backupMetadata = ref(null)
    const isPreviewing = ref(false)
    const conflictResolution = ref('skip')
    const importGroups = ref(true)

    // Import result state (warnings + one-click cleanup of undecryptable
    // just-imported accounts, C3)
    const legacyFormatWarning = ref(false)
    const keyMismatchWarning = ref(false)
    const undecryptableAccountIds = ref([])
    const isDeletingImported = ref(false)

    const backupInfo = computed(() => backup.info)
    const encryptionStatus = ref(null)
    const encryptionEnabled = computed(() => {
        if (encryptionStatus.value) {
            return encryptionStatus.value.encryption_enabled === true
        }

        return user.encryption_version > 0
    })

    onMounted(async () => {
        const [backupResponse, encryptionResponse] = await Promise.allSettled([
            backup.fetchInfo(),
            apiClient.get('/encryption/status'),
        ])

        if (encryptionResponse.status === 'fulfilled') {
            encryptionStatus.value = encryptionResponse.value.data
        }

        if (backupResponse.status === 'rejected') {
            throw backupResponse.reason
        }
    })

    /**
     * Export encrypted backup
     */
    function exportBackup() {
        if (!encryptionEnabled.value) {
            notify.alert({ text: t('error.encryption_not_enabled') })
            return
        }
        showExportDialog.value = true
    }

    async function confirmExport() {
        if (!exportPassword.value) {
            notify.alert({ text: t('error.password_required') })
            return
        }

        isExporting.value = true
        try {
            await backup.exportBackup(exportPassword.value)
            notify.success({ text: t('notification.backup_exported') })
            showExportDialog.value = false
            exportPassword.value = ''
            await backup.fetchInfo()
        } catch (error) {
            if (error.response?.status === 400) {
                notify.alert({ text: error.response.data.message })
            } else {
                errorHandler.show(error)
            }
        } finally {
            isExporting.value = false
        }
    }

    /**
     * Import encrypted backup
     */
    async function selectBackupFile(event) {
        const file = event.target.files[0]
        if (!file) return

        backupFile.value = file
        isPreviewing.value = true

        try {
            const metadata = await backup.getBackupMetadata(file)
            backupMetadata.value = metadata
        } catch {
            // Invalid/unreadable file: stay on the page, tell the user in
            // place and let the server-side confirm reject the import.
            backupMetadata.value = null
            notify.alert({ text: t('error.invalid_backup_file') })
        } finally {
            isPreviewing.value = false
            showImportDialog.value = true
        }
    }

    async function confirmImport() {
        if (!backupFile.value) {
            notify.alert({ text: t('error.file_required') })
            return
        }

        isImporting.value = true
        try {
            // Format 2 files are decrypted client-side before upload (the
            // password never leaves the browser); legacy files upload as-is.
            const result = await backup.importBackup(
                backupFile.value,
                importPassword.value,
                conflictResolution.value,
                importGroups.value
            )

            notify.success({
                text: t('notification.backup_imported', {
                    imported: result.imported_count || 0,
                })
            })

            legacyFormatWarning.value = result.legacy_format_warning === true
            keyMismatchWarning.value = result.key_mismatch_warning === true
            undecryptableAccountIds.value = keyMismatchWarning.value
                ? (result.imported_account_ids || [])
                : []

            showImportDialog.value = false
            importPassword.value = ''
            backupFile.value = null
            backupMetadata.value = null
            await backup.fetchInfo()
        } catch (error) {
            if (error.response?.status === 422 || error.response?.status === 400) {
                notify.alert({ text: error.response.data.message })
            } else if (error.response === undefined && error instanceof Error && error.message) {
                // Client-side failures (e.g. wrong v2 password): tell the user
                // in place instead of bouncing to the global error page.
                notify.alert({ text: error.message })
            } else {
                errorHandler.show(error)
            }
        } finally {
            isImporting.value = false
        }
    }

    /**
     * One-click delete of just-imported undecryptable accounts (C3)
     */
    async function deleteImportedAccounts() {
        if (undecryptableAccountIds.value.length === 0) return

        isDeletingImported.value = true
        try {
            await backup.deleteImportedAccounts(undecryptableAccountIds.value)
            notify.success({ text: t('notification.accounts_deleted') })
            undecryptableAccountIds.value = []
            keyMismatchWarning.value = false
        } catch (error) {
            errorHandler.show(error)
        } finally {
            isDeletingImported.value = false
        }
    }

    function dismissImportWarnings() {
        legacyFormatWarning.value = false
        keyMismatchWarning.value = false
        undecryptableAccountIds.value = []
    }

    function cancelExport() {
        showExportDialog.value = false
        exportPassword.value = ''
    }

    function cancelImport() {
        showImportDialog.value = false
        importPassword.value = ''
        backupFile.value = null
        backupMetadata.value = null
    }

    onBeforeRouteLeave((to) => {
        if (!to.name.startsWith('settings.') && to.name === 'login') {
            returnTo.value = to.name
        }
    })
</script>

<template>
    <StackLayout>
        <template #header>
            <TabBar :tabs="tabs" :active-tab="'settings.backup'" @tab-selected="(to) => router.push({ name: to })" />
        </template>
        <template #content>
            <FormWrapper>
                <form>
                    <!-- Export Section -->
                    <div class="block">
                        <h4 class="title is-4">{{ $t('settings.backup.export_title') }}</h4>
                        <p class="block">{{ $t('settings.backup.export_description') }}</p>

                        <!-- Not encrypted: show warning -->
                        <div v-if="!encryptionEnabled" class="notification is-warning">
                            {{ $t('settings.backup.encryption_required') }}
                            <p class="mt-3">
                                <router-link :to="{ name: 'settings.encryption' }" class="button is-small is-info">
                                    {{ $t('settings.backup.enable_encryption') }}
                                </router-link>
                            </p>
                        </div>

                        <!-- Encrypted: show export button -->
                        <div v-else class="block">
                            <button
                                type="button"
                                class="button is-primary"
                                :class="{ 'is-loading': isExporting }"
                                @click="exportBackup"
                            >
                                {{ $t('settings.backup.export_button') }}
                            </button>

                            <!-- Backup info -->
                            <div v-if="backupInfo.has_backup" class="notification is-info is-light mt-3">
                                <p>
                                    <strong>{{ $t('settings.backup.last_backup') }}:</strong>
                                    {{ new Date(backupInfo.last_backup_at).toLocaleString() }}
                                </p>
                                <p v-if="backupInfo.days_since_backup" class="is-size-7 mt-1">
                                    {{ $t('settings.backup.days_ago', { days: backupInfo.days_since_backup }) }}
                                </p>
                            </div>

                            <div v-else class="notification is-warning is-light mt-3">
                                <p>{{ $t('settings.backup.no_backup_yet') }}</p>
                            </div>
                        </div>
                    </div>

                    <hr />

                    <!-- Import Section -->
                    <div class="block">
                        <h4 class="title is-4">{{ $t('settings.backup.import_title') }}</h4>
                        <p class="block">{{ $t('settings.backup.import_description') }}</p>

                        <!-- File upload -->
                        <div class="file has-name mt-3">
                            <label class="file-label">
                                <input
                                    class="file-input"
                                    type="file"
                                    accept=".vault,.json"
                                    @change="selectBackupFile"
                                    :disabled="isImporting"
                                />
                                <span class="file-cta">
                                    <span class="file-icon">
                                        <i class="fas fa-upload"></i>
                                    </span>
                                    <span class="file-label">
                                        {{ $t('settings.backup.choose_file') }}
                                    </span>
                                </span>
                                <span class="file-name" v-if="backupFile">
                                    {{ backupFile.name }}
                                </span>
                            </label>
                        </div>

                        <!-- Loading preview -->
                        <div v-if="isPreviewing" class="has-text-centered py-3">
                            <span class="loader"></span>
                            <p class="mt-2">{{ $t('settings.backup.previewing') }}</p>
                        </div>

                        <!-- Post-import warnings (C3 / RT4) -->
                        <div v-if="legacyFormatWarning" class="notification is-warning is-light mt-3">
                            {{ $t('settings.backup.warning_legacy_format') }}
                            <p class="mt-2">
                                <button type="button" class="button is-small" @click="dismissImportWarnings">
                                    {{ $t('label.close') }}
                                </button>
                            </p>
                        </div>

                        <div v-if="keyMismatchWarning" class="notification is-warning is-light mt-3">
                            {{ $t('settings.backup.warning_key_mismatch') }}
                            <p class="mt-2">
                                <button
                                    type="button"
                                    class="button is-small is-danger"
                                    :class="{ 'is-loading': isDeletingImported }"
                                    :disabled="isDeletingImported || undecryptableAccountIds.length === 0"
                                    @click="deleteImportedAccounts"
                                >
                                    {{ $t('settings.backup.delete_imported') }}
                                </button>
                            </p>
                        </div>
                    </div>
                </form>
            </FormWrapper>

            <!-- Export Dialog -->
            <div class="modal" :class="{ 'is-active': showExportDialog }" role="dialog" aria-modal="true" aria-labelledby="export-dialog-title" @keydown.escape="cancelExport">
                <div class="modal-background" @click="cancelExport" aria-hidden="true"></div>
                <div class="modal-card">
                    <header class="modal-card-head">
                        <p class="modal-card-title" id="export-dialog-title">{{ $t('settings.backup.export_dialog_title') }}</p>
                        <button class="delete" @click="cancelExport" :aria-label="$t('label.close')"></button>
                    </header>
                    <section class="modal-card-body">
                        <div class="field">
                            <label class="label">{{ $t('settings.backup.master_password') }}</label>
                            <div class="control">
                                <input
                                    class="input"
                                    type="password"
                                    v-model="exportPassword"
                                    :placeholder="t('settings.backup.password_placeholder')"
                                />
                            </div>
                            <p class="help">{{ $t('settings.backup.password_help') }}</p>
                        </div>
                    </section>
                    <footer class="modal-card-foot">
                        <button class="button is-primary" @click="confirmExport" :disabled="isExporting">
                            <span class="icon" v-if="isExporting">
                                <i class="fas fa-spinner fa-pulse"></i>
                            </span>
                            <span>{{ $t('label.confirm') }}</span>
                        </button>
                        <button class="button" @click="cancelExport">{{ $t('label.cancel') }}</button>
                    </footer>
                </div>
            </div>

            <!-- Import Dialog -->
            <div class="modal" :class="{ 'is-active': showImportDialog }" role="dialog" aria-modal="true" aria-labelledby="import-dialog-title" @keydown.escape="cancelImport">
                <div class="modal-background" @click="cancelImport" aria-hidden="true"></div>
                <div class="modal-card">
                    <header class="modal-card-head">
                        <p class="modal-card-title" id="import-dialog-title">{{ $t('settings.backup.import_dialog_title') }}</p>
                        <button class="delete" @click="cancelImport" :aria-label="$t('label.close')"></button>
                    </header>
                    <section class="modal-card-body">
                        <!-- Backup Preview -->
                        <div v-if="backupMetadata" class="notification is-info is-light mb-4">
                            <p class="has-text-weight-semibold mb-2">{{ $t('settings.backup.preview_title') }}</p>
                            <ul>
                                <li>{{ $t('settings.backup.preview_format') }}: <strong>{{ backupMetadata.format || 'unknown' }}</strong></li>
                                <li v-if="backupMetadata.requires_decryption" class="has-text-weight-semibold">
                                    {{ $t('settings.backup.preview_requires_decryption') }}
                                </li>
                                <li v-else>{{ $t('settings.backup.preview_accounts') }}: <strong>{{ backupMetadata.account_count || 0 }}</strong></li>
                                <li v-if="backupMetadata.group_count">
                                    {{ $t('settings.backup.preview_groups') }}: <strong>{{ backupMetadata.group_count }}</strong>
                                </li>
                                <li v-if="backupMetadata.version">{{ $t('settings.backup.preview_version') }}: <strong>{{ backupMetadata.version }}</strong></li>
                                <li>
                                    {{ $t('settings.backup.preview_encrypted') }}:
                                    <strong :class="backupMetadata.encrypted ? 'has-text-success' : 'has-text-warning'">
                                        {{ backupMetadata.encrypted ? $t('label.yes') : $t('label.no') }}
                                    </strong>
                                </li>
                                <li v-if="backupMetadata.exported_at">
                                    {{ $t('settings.backup.preview_exported_at') }}: <strong>{{ new Date(backupMetadata.exported_at).toLocaleString() }}</strong>
                                </li>
                            </ul>
                        </div>

                        <!-- Password -->
                        <div class="field">
                            <label class="label">{{ $t('settings.backup.master_password') }}</label>
                            <div class="control">
                                <input
                                    class="input"
                                    type="password"
                                    v-model="importPassword"
                                    :placeholder="t('settings.backup.password_placeholder')"
                                />
                            </div>
                            <p class="help">{{ $t('settings.backup.password_help') }}</p>
                        </div>

                        <!-- Conflict Resolution -->
                        <div class="field">
                            <label class="label">{{ $t('settings.backup.conflict_resolution') }}</label>
                            <div class="control">
                                <label class="radio">
                                    <input type="radio" v-model="conflictResolution" value="skip" />
                                    {{ $t('settings.backup.conflict_skip') }}
                                </label>
                                <label class="radio">
                                    <input type="radio" v-model="conflictResolution" value="replace" />
                                    {{ $t('settings.backup.conflict_replace') }}
                                </label>
                                <label class="radio">
                                    <input type="radio" v-model="conflictResolution" value="rename" />
                                    {{ $t('settings.backup.conflict_rename') }}
                                </label>
                            </div>
                            <p class="help" v-if="conflictResolution === 'replace'">
                                <strong class="has-text-danger">{{ $t('settings.backup.replace_warning') }}</strong>
                            </p>
                        </div>

                        <!-- Import Groups Toggle -->
                        <div class="field" v-if="backupMetadata && backupMetadata.group_count">
                            <label class="checkbox">
                                <input type="checkbox" v-model="importGroups" />
                                {{ $t('settings.backup.import_groups') }}
                            </label>
                            <p class="help">{{ $t('settings.backup.import_groups.help') }}</p>
                        </div>
                    </section>
                    <footer class="modal-card-foot">
                        <button class="button is-primary" @click="confirmImport" :disabled="isImporting">
                            <span class="icon" v-if="isImporting">
                                <i class="fas fa-spinner fa-pulse"></i>
                            </span>
                            <span>{{ $t('label.confirm') }}</span>
                        </button>
                        <button class="button" @click="cancelImport">{{ $t('label.cancel') }}</button>
                    </footer>
                </div>
            </div>
        </template>
        <template #footer>
            <VueFooter>
                <template #default>
                    <NavigationButton action="close" @closed="router.push({ name: returnTo })" :current-page-title="$t('title.settings.backup')" />
                </template>
            </VueFooter>
        </template>
    </StackLayout>
</template>
