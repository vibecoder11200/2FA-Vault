<script setup>
    import tabs from './tabs'
    import backupDestinationService from '@/services/backupDestinationService'
    import userService from '@/services/userService'
    import { useUserStore } from '@/stores/user'
    import { useNotify, TabBar } from '@2fauth/ui'
    import { LucidePlus, LucideTrash2, LucidePencil, LucidePlugZap } from 'lucide-vue-next'

    const router = useRouter()

    const { t } = useI18n()
    const notify = useNotify()
    const user = useUserStore()

    // Auto-backup preferences (persisted as user preferences)
    const autoEnabled = ref(false)
    const frequency = ref('daily')
    const backupTime = ref('02:00')
    const isSavingPrefs = ref(false)

    // Destinations
    const destinations = ref([])
    const isLoading = ref(false)
    const showEditor = ref(false)
    const editingId = ref(null)
    const testingId = ref(null)

    // Config keys MUST match the backend contract
    // (StoreBackupDestinationRequest / BackupDestinationService).
    const emptyForm = () => ({
        label: '',
        type: 's3',
        is_active: true,
        config: {
            endpoint: '', bucket: '', access_key: '', secret_key: '', region: '', prefix: '',
            url: '', username: '', password: '', path: '',
            email: '',
            // C1 (F3): per-destination backup encryption password — backups
            // pushed to this destination are AES-256-GCM encrypted with it.
            encryption_password: '',
            // Email destinations must explicitly opt in to attachments
            // (AutoBackupJob only ever attaches encrypted envelopes).
            email_attachments: false,
        },
    })
    const form = reactive(emptyForm())

    const destinationTypes = [
        { text: 'S3', value: 's3' },
        { text: 'WebDAV', value: 'webdav' },
        { text: 'Email', value: 'email' },
        { text: 'Local', value: 'local' },
    ]

    const frequencies = [
        { text: 'frequency.daily', value: 'daily' },
        { text: 'frequency.weekly', value: 'weekly' },
        { text: 'frequency.monthly', value: 'monthly' },
    ]

    onMounted(async () => {
        autoEnabled.value = !!user.preferences.auto_backup_enabled
        frequency.value = user.preferences.auto_backup_frequency || 'daily'
        backupTime.value = user.preferences.auto_backup_time || '02:00'
        await loadDestinations()
    })

    async function loadDestinations() {
        isLoading.value = true
        try {
            const { data } = await backupDestinationService.list().catch(() => ({ data: [] }))
            destinations.value = Array.isArray(data) ? data : (data.data ?? [])
        } finally {
            isLoading.value = false
        }
    }

    async function savePrefs() {
        isSavingPrefs.value = true
        try {
            await userService.updatePreference('auto_backup_enabled', autoEnabled.value)
            await userService.updatePreference('auto_backup_frequency', frequency.value)
            await userService.updatePreference('auto_backup_time', backupTime.value)
            user.preferences.auto_backup_enabled = autoEnabled.value
            user.preferences.auto_backup_frequency = frequency.value
            user.preferences.auto_backup_time = backupTime.value
            notify.success({ text: t('notification.preferences_updated') })
        } catch (e) {
            notify.alert({ text: e.response?.data?.message ?? t('error.unknown') })
        } finally {
            isSavingPrefs.value = false
        }
    }

    function openCreate() {
        editingId.value = null
        Object.assign(form, emptyForm())
        showEditor.value = true
    }

    function openEdit(d) {
        editingId.value = d.id
        // The API returns a masked payload (config_summary, no secrets):
        // secret fields stay blank and mean "keep the stored value" (the
        // backend merges partial configs on update).
        Object.assign(form, {
            label: d.label || '',
            type: d.type || 's3',
            is_active: d.is_active !== false,
            config: {
                ...(emptyForm().config),
                ...(d.config_summary || {}),
                encryption_password: '',
                email_attachments: !!(d.config_summary && d.config_summary.email_attachments),
            },
        })
        showEditor.value = true
    }

    async function saveDestination() {
        if (!form.label.trim()) return
        const payload = {
            label: form.label,
            type: form.type,
            is_active: form.is_active,
            config: stripEmpty(form.config),
        }
        try {
            if (editingId.value) {
                const { data } = await backupDestinationService.update(editingId.value, payload)
                replaceDestination(data)
                notify.success({ text: t('notification.destination_updated') })
            } else {
                const { data } = await backupDestinationService.create(payload)
                destinations.value.push(data)
                notify.success({ text: t('notification.destination_created') })
            }
            showEditor.value = false
        } catch (e) {
            notify.alert({ text: e.response?.data?.message ?? t('error.unknown') })
        }
    }

    function replaceDestination(updated) {
        const idx = destinations.value.findIndex(d => d.id === updated.id)
        if (idx !== -1) destinations.value.splice(idx, 1, updated)
    }

    async function removeDestination(d) {
        if (!confirm(t('confirmation.delete_destination'))) return
        try {
            await backupDestinationService.remove(d.id)
            destinations.value = destinations.value.filter(x => x.id !== d.id)
            notify.success({ text: t('notification.destination_deleted') })
        } catch (e) {
            notify.alert({ text: e.response?.data?.message ?? t('error.unknown') })
        }
    }

    async function testDestination(d) {
        testingId.value = d.id
        try {
            await backupDestinationService.test(d.id)
            notify.success({ text: t('message.connection_ok') })
        } catch (e) {
            notify.alert({ text: e.response?.data?.message ?? t('message.connection_failed') })
        } finally {
            testingId.value = null
        }
    }

    function stripEmpty(obj) {
        const out = {}
        for (const [k, v] of Object.entries(obj)) {
            if (v !== '' && v !== null && v !== undefined) out[k] = v
        }
        return out
    }
</script>

<template>
    <div>
        <TabBar :tabs="tabs" :active-tab="'settings.backup'" @tab-selected="(to) => router.push({ name: to })" />
    <div class="container py-5">
        <div class="level mb-4">
            <div class="level-left">
                <h2 class="title is-3 mb-0">{{ $t('title.auto_backup') }}</h2>
            </div>
        </div>

        <!-- Preferences -->
        <div class="box mb-4">
            <h4 class="title is-5">{{ $t('label.enable_auto_backup') }}</h4>
            <div class="field">
                <label class="checkbox">
                    <input type="checkbox" v-model="autoEnabled" />
                    {{ $t('label.enable_auto_backup') }}
                </label>
            </div>
            <div class="field">
                <label class="label is-size-7">{{ $t('label.backup_frequency') }}</label>
                <div class="select">
                    <select v-model="frequency">
                        <option v-for="f in frequencies" :key="f.value" :value="f.value">{{ $t(f.text) }}</option>
                    </select>
                </div>
            </div>
            <div class="field">
                <label class="label is-size-7">{{ $t('label.backup_time') }} (UTC, HH:MM)</label>
                <input class="input" type="time" v-model="backupTime" />
            </div>
            <div class="buttons">
                <VueButton class="button is-primary" :isLoading="isSavingPrefs" @click="savePrefs">{{ $t('label.save') }}</VueButton>
            </div>
        </div>

        <!-- Destinations -->
        <div class="box">
            <div class="is-flex is-align-items-center is-justify-content-space-between mb-3">
                <h4 class="title is-5 mb-0">{{ $t('label.backup_destinations') }}</h4>
                <VueButton class="button is-primary is-small" @click="openCreate">
                    <LucidePlus class="mr-1" />{{ $t('label.add_destination') }}
                </VueButton>
            </div>

            <div v-if="isLoading" class="has-text-grey">{{ $t('label.loading') }}</div>
            <p v-else-if="!destinations.length" class="has-text-grey">{{ $t('message.no_destinations_yet') }}</p>

            <div v-for="d in destinations" :key="d.id" class="is-flex is-align-items-center is-justify-content-space-between py-3" style="border-bottom:1px solid #f0f0f0">
                <div>
                    <span class="has-text-weight-semibold">{{ d.label }}</span>
                    <span class="tag is-light ml-2">{{ d.type }}</span>
                    <span class="tag ml-2 is-size-7" :class="d.is_active !== false ? 'is-success is-light' : 'is-light'">
                        {{ d.is_active !== false ? $t('label.active') : $t('label.inactive') }}
                    </span>
                    <!-- C1: destinations without a backup encryption password
                         still receive legacy (unencrypted) envelopes — warn. -->
                    <span v-if="d.is_active !== false && !d.has_encryption_password" class="tag ml-2 is-size-7 is-warning is-light">
                        {{ $t('settings.backup.warning_destination_unencrypted') }}
                    </span>
                </div>
                <div class="buttons are-small">
                    <VueButton class="button is-info is-light" :isLoading="testingId === d.id" @click="testDestination(d)">
                        <LucidePlugZap class="mr-1" />{{ $t('label.test_connection') }}
                    </VueButton>
                    <button class="button is-light" @click="openEdit(d)"><LucidePencil /></button>
                    <button class="button is-danger is-light" @click="removeDestination(d)"><LucideTrash2 /></button>
                </div>
            </div>
        </div>

        <!-- Destination editor modal -->
        <div v-if="showEditor" class="modal is-active" role="dialog" aria-modal="true">
            <div class="modal-background" @click="showEditor = false"></div>
            <div class="modal-card">
                <header class="modal-card-head">
                    <p class="modal-card-title">{{ editingId ? $t('label.edit') : $t('label.add_destination') }}</p>
                    <button class="delete" :aria-label="$t('label.close')" @click="showEditor = false"></button>
                </header>
                <section class="modal-card-body">
                    <div class="field">
                        <label class="label is-size-7">{{ $t('field.destination_label') }}</label>
                        <input class="input" type="text" v-model="form.label" />
                    </div>
                    <div class="field">
                        <label class="label is-size-7">{{ $t('field.destination_type') }}</label>
                        <div class="select">
                            <select v-model="form.type" :disabled="!!editingId">
                                <option v-for="tp in destinationTypes" :key="tp.value" :value="tp.value">{{ tp.text }}</option>
                            </select>
                        </div>
                    </div>

                    <!-- S3 -->
                    <template v-if="form.type === 's3'">
                        <div class="field"><label class="label is-size-7">{{ $t('field.s3_endpoint') }}</label><input class="input" v-model="form.config.endpoint" /></div>
                        <div class="field"><label class="label is-size-7">{{ $t('field.s3_bucket') }}</label><input class="input" v-model="form.config.bucket" /></div>
                        <div class="field"><label class="label is-size-7">{{ $t('field.s3_key') }}</label><input class="input" v-model="form.config.access_key" :placeholder="editingId ? t('field.secret_unchanged') : ''" /></div>
                        <div class="field"><label class="label is-size-7">{{ $t('field.s3_secret') }}</label><input class="input" type="password" v-model="form.config.secret_key" :placeholder="editingId ? t('field.secret_unchanged') : ''" /></div>
                        <div class="field"><label class="label is-size-7">{{ $t('field.s3_prefix') }}</label><input class="input" v-model="form.config.prefix" /></div>
                    </template>
                    <!-- WebDAV -->
                    <template v-else-if="form.type === 'webdav'">
                        <div class="field"><label class="label is-size-7">{{ $t('field.webdav_url') }}</label><input class="input" v-model="form.config.url" /></div>
                        <div class="field"><label class="label is-size-7">{{ $t('field.webdav_user') }}</label><input class="input" v-model="form.config.username" :placeholder="editingId ? t('field.secret_unchanged') : ''" /></div>
                        <div class="field"><label class="label is-size-7">{{ $t('field.webdav_pass') }}</label><input class="input" type="password" v-model="form.config.password" :placeholder="editingId ? t('field.secret_unchanged') : ''" /></div>
                        <div class="field"><label class="label is-size-7">{{ $t('field.webdav_path') }}</label><input class="input" v-model="form.config.path" /></div>
                    </template>
                    <!-- Email -->
                    <template v-else-if="form.type === 'email'">
                        <div class="field"><label class="label is-size-7">{{ $t('field.email_address') }}</label><input class="input" type="email" v-model="form.config.email" /></div>
                        <div class="field">
                            <label class="checkbox">
                                <input type="checkbox" v-model="form.config.email_attachments" />
                                {{ $t('field.email_attachments') }}
                            </label>
                            <p class="help">{{ $t('field.email_attachments.help') }}</p>
                        </div>
                    </template>
                    <!-- Local -->
                    <template v-else-if="form.type === 'local'">
                        <div class="field"><label class="label is-size-7">{{ $t('field.local_path') }}</label><input class="input" v-model="form.config.path" /></div>
                    </template>

                    <!-- Per-destination backup encryption password (all types) -->
                    <div class="field">
                        <label class="label is-size-7">{{ $t('field.backup_encryption_password') }}</label>
                        <input
                            class="input"
                            type="password"
                            v-model="form.config.encryption_password"
                            :placeholder="editingId ? t('field.secret_unchanged') : ''"
                        />
                        <p class="help">{{ $t('field.backup_encryption_password.help') }}</p>
                    </div>

                    <div class="field">
                        <label class="checkbox">
                            <input type="checkbox" v-model="form.is_active" />
                            {{ $t('label.active') }}
                        </label>
                    </div>
                </section>
                <footer class="modal-card-foot">
                    <VueButton class="button is-primary" :disabled="!form.label.trim()" @click="saveDestination">{{ $t('label.save') }}</VueButton>
                    <VueButton class="button" @click="showEditor = false">{{ $t('label.cancel') }}</VueButton>
                </footer>
            </div>
        </div>
    </div>
    </div>
</template>
