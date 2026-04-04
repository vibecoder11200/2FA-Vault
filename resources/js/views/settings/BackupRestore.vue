<script setup>
import tabs from './tabs'
import Form from '@/components/formElements/Form'
import { useUserStore } from '@/stores/user'
import { useBackupStore } from '@/stores/backup'
import { useNotify, TabBar } from '@2fauth/ui'
import { useI18n } from 'vue-i18n'
import { useErrorHandler } from '@2fauth/stores'
import { computed, ref, onMounted } from 'vue'

const errorHandler = useErrorHandler()
const { t } = useI18n()
const $2fauth = inject('2fauth')
const user = useUserStore()
const backup = useBackupStore()
const notify = useNotify()
const router = useRouter()
const returnTo = useStorage($2fauth.prefix + 'returnTo', 'accounts')

const isExporting = ref(false)
const isImporting = ref(false)
const backupFile = ref(null)
const importMode = ref('merge')
const showExportDialog = ref(false)
const showImportDialog = ref(false)
const exportPassword = ref('')
const importPassword = ref('')

const backupInfo = computed(() => backup.info)
const encryptionEnabled = computed(() => user.encryptionVersion > 0)

const formExport = reactive(new Form({
    masterPassword: '',
}))

const formImport = reactive(new Form({
    backupFile: null,
    masterPassword: '',
    mode: 'merge',
}))

onMounted(async () => {
    await backup.fetchInfo()
})

/**
 * Export encrypted backup
 */
async function exportBackup() {
    if (!encryptionEnabled.value) {
        notify.alert({ text: t('errors.encryption_not_enabled') })
        return
    }

    showExportDialog.value = true
}

async function confirmExport() {
    if (!exportPassword.value) {
        notify.alert({ text: t('errors.password_required') })
        return
    }

    isExporting.value = true

    try {
        // In production, this would derive the key from password
        // For now, we'll use a placeholder
        const response = await backup.exportBackup(exportPassword.value)
        
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
function selectBackupFile(event) {
    const file = event.target.files[0]
    if (file) {
        backupFile.value = file
        showImportDialog.value = true
    }
}

async function confirmImport() {
    if (!backupFile.value) {
        notify.alert({ text: t('errors.file_required') })
        return
    }

    if (!importPassword.value) {
        notify.alert({ text: t('errors.password_required') })
        return
    }

    isImporting.value = true

    try {
        const result = await backup.importBackup(
            backupFile.value,
            importPassword.value,
            importMode.value
        )
        
        notify.success({ 
            text: t('notification.backup_imported', { 
                imported: result.imported,
                failed: result.failed 
            })
        })
        
        showImportDialog.value = false
        importPassword.value = ''
        backupFile.value = null
        
        await backup.fetchInfo()
    } catch (error) {
        if (error.response?.status === 400) {
            notify.alert({ text: error.response.data.message })
        } else {
            errorHandler.show(error)
        }
    } finally {
        isImporting.value = false
    }
}

function cancelExport() {
    showExportDialog.value = false
    exportPassword.value = ''
}

function cancelImport() {
    showImportDialog.value = false
    importPassword.value = ''
    backupFile.value = null
}

onBeforeRouteLeave((to) => {
    if (!to.name.startsWith('settings.') && to.name === 'login') {
        returnTo.value = to.name
    }
})
</script>

<template>
    <div>
        <TabBar :tabs="tabs" :activeTab="'backup'" />
        
        <div class="settings-panel">
            <!-- Export Section -->
            <div class="section">
                <h3 class="title is-4">{{ t('settings.backup.export_title') }}</h3>
                <p class="help">{{ t('settings.backup.export_description') }}</p>
                
                <div class="field">
                    <div class="control">
                        <button 
                            class="button is-primary" 
                            @click="exportBackup"
                            :disabled="!encryptionEnabled || isExporting"
                        >
                            <span class="icon" v-if="isExporting">
                                <i class="fas fa-spinner fa-pulse"></i>
                            </span>
                            <span class="icon" v-else>
                                <i class="fas fa-download"></i>
                            </span>
                            <span>{{ t('settings.backup.export_button') }}</span>
                        </button>
                    </div>
                </div>

                <!-- Backup Info -->
                <div v-if="backupInfo.hasBackup" class="notification is-info is-light">
                    <p>
                        <strong>{{ t('settings.backup.last_backup') }}:</strong>
                        {{ new Date(backupInfo.lastBackupAt).toLocaleString() }}
                    </p>
                    <p v-if="backupInfo.daysSinceBackup">
                        ({{ t('settings.backup.days_ago', { days: backupInfo.daysSinceBackup }) }})
                    </p>
                </div>

                <div v-else-if="encryptionEnabled" class="notification is-warning is-light">
                    <p>{{ t('settings.backup.no_backup_yet') }}</p>
                </div>

                <div v-if="!encryptionEnabled" class="notification is-warning is-light">
                    <p>{{ t('settings.backup.encryption_required') }}</p>
                    <router-link :to="{ name: 'settings.encryption' }" class="button is-small is-info">
                        {{ t('settings.backup.enable_encryption') }}
                    </router-link>
                </div>
            </div>

            <hr />

            <!-- Import Section -->
            <div class="section">
                <h3 class="title is-4">{{ t('settings.backup.import_title') }}</h3>
                <p class="help">{{ t('settings.backup.import_description') }}</p>
                
                <div class="field">
                    <div class="file has-name">
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
                                    {{ t('settings.backup.choose_file') }}
                                </span>
                            </span>
                            <span class="file-name" v-if="backupFile">
                                {{ backupFile.name }}
                            </span>
                        </label>
                    </div>
                </div>

                <!-- Import Mode Selection -->
                <div class="field" v-if="showImportDialog">
                    <label class="label">{{ t('settings.backup.import_mode') }}</label>
                    <div class="control">
                        <label class="radio">
                            <input type="radio" v-model="importMode" value="merge" />
                            {{ t('settings.backup.mode_merge') }}
                        </label>
                        <label class="radio">
                            <input type="radio" v-model="importMode" value="replace" />
                            {{ t('settings.backup.mode_replace') }}
                        </label>
                    </div>
                    <p class="help" v-if="importMode === 'replace'">
                        <strong class="has-text-danger">
                            {{ t('settings.backup.replace_warning') }}
                        </strong>
                    </p>
                </div>
            </div>
        </div>

        <!-- Export Dialog -->
        <div class="modal" :class="{ 'is-active': showExportDialog }">
            <div class="modal-background" @click="cancelExport"></div>
            <div class="modal-card">
                <header class="modal-card-head">
                    <p class="modal-card-title">{{ t('settings.backup.export_dialog_title') }}</p>
                    <button class="delete" @click="cancelExport" aria-label="close"></button>
                </header>
                <section class="modal-card-body">
                    <div class="field">
                        <label class="label">{{ t('settings.backup.master_password') }}</label>
                        <div class="control">
                            <input 
                                class="input" 
                                type="password" 
                                v-model="exportPassword"
                                :placeholder="t('settings.backup.password_placeholder')"
                            />
                        </div>
                        <p class="help">{{ t('settings.backup.password_help') }}</p>
                    </div>
                </section>
                <footer class="modal-card-foot">
                    <button class="button is-primary" @click="confirmExport" :disabled="isExporting">
                        <span class="icon" v-if="isExporting">
                            <i class="fas fa-spinner fa-pulse"></i>
                        </span>
                        <span>{{ t('common.confirm') }}</span>
                    </button>
                    <button class="button" @click="cancelExport">{{ t('common.cancel') }}</button>
                </footer>
            </div>
        </div>

        <!-- Import Dialog -->
        <div class="modal" :class="{ 'is-active': showImportDialog }">
            <div class="modal-background" @click="cancelImport"></div>
            <div class="modal-card">
                <header class="modal-card-head">
                    <p class="modal-card-title">{{ t('settings.backup.import_dialog_title') }}</p>
                    <button class="delete" @click="cancelImport" aria-label="close"></button>
                </header>
                <section class="modal-card-body">
                    <div class="field">
                        <label class="label">{{ t('settings.backup.master_password') }}</label>
                        <div class="control">
                            <input 
                                class="input" 
                                type="password" 
                                v-model="importPassword"
                                :placeholder="t('settings.backup.password_placeholder')"
                            />
                        </div>
                        <p class="help">{{ t('settings.backup.password_help') }}</p>
                    </div>
                </section>
                <footer class="modal-card-foot">
                    <button class="button is-primary" @click="confirmImport" :disabled="isImporting">
                        <span class="icon" v-if="isImporting">
                            <i class="fas fa-spinner fa-pulse"></i>
                        </span>
                        <span>{{ t('common.confirm') }}</span>
                    </button>
                    <button class="button" @click="cancelImport">{{ t('common.cancel') }}</button>
                </footer>
            </div>
        </div>
    </div>
</template>

<style scoped>
.settings-panel {
    padding: 1.5rem;
}

.section {
    margin-bottom: 2rem;
}

.radio + .radio {
    margin-left: 1rem;
}
</style>
