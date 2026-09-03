<script setup>
    import tabs from './tabs'
    import emergencyService from '@/services/emergencyService'
    import { useNotify, TabBar } from '@2fauth/ui'

    const { t } = useI18n()
    const notify = useNotify()
    const router = useRouter()

    const contacts     = ref([])
    const pendingReqs  = ref([])
    const contactsForMe = ref([])

    const newEmail     = ref('')
    const newWaitDays  = ref(30)
    const newAccess    = ref('view_only')
    const isAdding     = ref(false)
    // B2/F1: master password typed by the owner to wrap the emergency key at
    // designation time. Never sent to the server — only its RSA-OAEP wrapper
    // for the grantee's public key is.
    const masterPassword = ref('')

    // Grantee-side vault viewer (F1)
    const vaultViewer   = ref(null)
    const isLoadingVault = ref(false)
    const revealedSecrets = ref({})

    const WAIT_OPTIONS = [7, 14, 30, 60, 90]

    onMounted(async () => {
        await Promise.all([loadContacts(), loadPending(), loadForMe()])
    })

    async function loadContacts() {
        const { data } = await emergencyService.getContacts().catch(() => ({ data: [] }))
        contacts.value = data
    }
    async function loadPending() {
        const { data } = await emergencyService.getPendingRequests().catch(() => ({ data: [] }))
        pendingReqs.value = data
    }
    async function loadForMe() {
        const { data } = await emergencyService.getContactsForMe().catch(() => ({ data: [] }))
        contactsForMe.value = data
    }

    /**
     * B2/F1 designation (and re-save): when the grantee is a registered user
     * with a public key, wrap the master password with that key and submit the
     * wrapper + fingerprint alongside the contact. When the grantee is not
     * registered yet, no key is stored — the contact shows a "re-save key"
     * nudge until the owner re-saves after the grantee registers.
     */
    async function addContact() {
        if (!newEmail.value.trim()) return
        isAdding.value = true
        try {
            const payload = { email: newEmail.value.trim(), wait_days: newWaitDays.value, access_type: newAccess.value }

            const { data: keyInfo } = await emergencyService.granteeKeyInfo({ email: payload.email }).catch(() => ({ data: null }))
            if (keyInfo?.public_key) {
                if (!masterPassword.value) {
                    notify.alert({ text: t('error.emergency_password_required') })
                    isAdding.value = false
                    return
                }
                const { wrapSecretForMember } = await import('@/services/keySharingService')
                payload.encrypted_key = await wrapSecretForMember(masterPassword.value, keyInfo.public_key)
                payload.grantee_public_key_fingerprint = keyInfo.fingerprint
            }

            const { data } = await emergencyService.addContact(payload)
            contacts.value = contacts.value.filter(c => c.email !== data.email)
            contacts.value.push(data)
            newEmail.value = ''
            masterPassword.value = ''
            notify.success({ text: keyInfo?.public_key ? t('notification.emergency_contact_added_with_key') : t('notification.emergency_contact_added') })
        } catch (e) {
            notify.alert({ text: e.response?.data?.message ?? t('error.unknown') })
        } finally {
            isAdding.value = false
        }
    }

    /** RT3 nudge: re-save reuses the designation flow (updateOrCreate). */
    async function reSaveKey(contact) {
        newEmail.value = contact.email
        newWaitDays.value = contact.wait_days
        newAccess.value = contact.access_type
        masterPassword.value = ''
        notify.info({ text: t('message.emergency_resave_hint') })
        window.scrollTo({ top: document.body.scrollHeight, behavior: 'smooth' })
    }

    async function revokeContact(contact) {
        if (!confirm(t('confirmation.revoke_emergency_contact', { email: contact.email }))) return
        try {
            await emergencyService.revokeContact(contact.id)
            contacts.value = contacts.value.filter(c => c.id !== contact.id)
            notify.success({ text: t('notification.emergency_contact_revoked') })
        } catch {
            notify.alert({ text: t('error.unknown') })
        }
    }

    async function approveRequest(req) {
        try {
            await emergencyService.approveRequest(req.id)
            pendingReqs.value = pendingReqs.value.filter(r => r.id !== req.id)
            notify.success({ text: t('notification.emergency_access_approved') })
        } catch (e) {
            notify.alert({ text: e.response?.data?.message ?? t('error.unknown') })
        }
    }

    async function denyRequest(req) {
        try {
            await emergencyService.denyRequest(req.id)
            pendingReqs.value = pendingReqs.value.filter(r => r.id !== req.id)
            notify.success({ text: t('notification.emergency_access_denied') })
        } catch (e) {
            notify.alert({ text: e.response?.data?.message ?? t('error.unknown') })
        }
    }

    async function requestMyAccess(contact) {
        try {
            await emergencyService.requestAccess(contact.id)
            notify.success({ text: t('notification.emergency_access_requested') })
        } catch (e) {
            notify.alert({ text: e.response?.data?.message ?? t('error.unknown') })
        }
    }

    /**
     * F1 grantee flow: fetch the owner's encrypted vault data, unwrap the
     * master password with the local RSA private key, derive the owner's vault
     * key and decrypt secrets on demand. Read-only by design.
     */
    async function openVault(contact) {
        isLoadingVault.value = true
        revealedSecrets.value = {}
        vaultViewer.value = null
        try {
            const { data } = await emergencyService.vaultData(contact.id)
            const { unwrapSharedSecret } = await import('@/services/keySharingService')
            const { deriveKey, decryptSecret } = await import('@/services/crypto')

            const masterPasswordOfOwner = await unwrapSharedSecret(data.encrypted_key)
            const ownerKey = await deriveKey(masterPasswordOfOwner, data.owner_encryption_salt)
            vaultViewer.value = { contact, data, ownerKey }
        } catch (e) {
            const apiError = e.response?.data
            notify.alert({ text: apiError?.error === 'emergency_key_unavailable'
                ? t('message.emergency_key_unavailable')
                : (apiError?.message ?? e.message ?? t('error.unknown')) })
        } finally {
            isLoadingVault.value = false
        }
    }

    async function revealSecret(account) {
        if (revealedSecrets.value[account.id] !== undefined) return
        try {
            const { decryptSecret } = await import('@/services/crypto')
            const envelope = typeof account.secret === 'string' ? JSON.parse(account.secret) : account.secret
            revealedSecrets.value[account.id] = await decryptSecret(envelope, vaultViewer.value.ownerKey)
        } catch {
            revealedSecrets.value[account.id] = false
        }
    }

    function statusClass(status) {
        return { pending: 'is-warning', confirmed: 'is-info', active: 'is-success', revoked: 'is-danger' }[status] ?? 'is-light'
    }
</script>

<template>
    <div>
        <TabBar :tabs="tabs" :active-tab="'settings.emergency'" @tab-selected="(to) => router.push({ name: to })" />
    <div class="container py-5">
        <h2 class="title is-3 mb-2">{{ $t('title.emergency_access') }}</h2>
        <p class="is-size-7 has-text-grey mb-5">{{ $t('message.emergency_access_desc') }}</p>

        <!-- Pending requests for me to approve -->
        <div v-if="pendingReqs.length" class="notification is-warning mb-4">
            <p class="has-text-weight-semibold mb-2">{{ $t('message.pending_emergency_requests', { n: pendingReqs.length }) }}</p>
            <div v-for="req in pendingReqs" :key="req.id" class="is-flex is-align-items-center mb-2">
                <span class="mr-3">{{ req.requester?.name }} ({{ req.contact?.email }})</span>
                <div class="buttons are-small mb-0">
                    <VueButton class="button is-success" @click="approveRequest(req)">{{ $t('label.approve') }}</VueButton>
                    <VueButton class="button is-danger" @click="denyRequest(req)">{{ $t('label.deny') }}</VueButton>
                </div>
            </div>
        </div>

        <!-- My designated contacts -->
        <div class="box mb-4">
            <h4 class="title is-5">{{ $t('title.my_emergency_contacts') }}</h4>
            <p v-if="!contacts.length" class="has-text-grey is-size-7 mb-3">{{ $t('message.no_emergency_contacts') }}</p>
            <div v-for="c in contacts" :key="c.id" class="is-flex is-align-items-center is-justify-content-space-between mb-2">
                <div>
                    <span class="has-text-weight-semibold">{{ c.email }}</span>
                    <span class="tag ml-2 is-size-7" :class="statusClass(c.status)">{{ c.status }}</span>
                    <span class="is-size-7 has-text-grey ml-2">{{ c.wait_days }} days · {{ c.access_type }}</span>
                    <!-- RT3: stale/missing wrapped key — the dead man's switch
                         would grant nothing usable until the owner re-saves. -->
                    <span v-if="c.key_stale" class="tag is-danger is-light is-size-7 ml-2">{{ $t('message.emergency_key_stale') }}</span>
                </div>
                <div class="buttons are-small">
                    <VueButton v-if="c.key_stale" class="button is-warning is-light" @click="reSaveKey(c)">{{ $t('label.re_save_key') }}</VueButton>
                    <VueButton class="button is-danger is-light" @click="revokeContact(c)">{{ $t('label.revoke') }}</VueButton>
                </div>
            </div>

            <!-- Add new contact -->
            <hr />
            <p class="has-text-weight-semibold is-size-7 mb-2">{{ $t('label.add_emergency_contact') }}</p>
            <p class="is-size-7 has-text-grey mb-2">{{ $t('message.emergency_wrap_explain') }}</p>
            <div class="field is-grouped is-flex-wrap-wrap">
                <div class="control is-expanded">
                    <input class="input is-small" type="email" v-model="newEmail" :placeholder="$t('field.trusted_email')" />
                </div>
                <div class="control">
                    <div class="select is-small">
                        <select v-model="newWaitDays">
                            <option v-for="d in WAIT_OPTIONS" :key="d" :value="d">{{ d }} {{ $t('label.days') }}</option>
                        </select>
                    </div>
                </div>
                <div class="control">
                    <div class="select is-small">
                        <select v-model="newAccess">
                            <option value="view_only">{{ $t('label.view_only') }}</option>
                            <option value="full_access">{{ $t('label.full_access') }}</option>
                        </select>
                    </div>
                </div>
                <div class="control is-expanded">
                    <input class="input is-small" type="password" v-model="masterPassword" :placeholder="$t('field.master_password')" autocomplete="new-password" />
                </div>
                <div class="control">
                    <VueButton class="button is-small is-primary" :isLoading="isAdding" @click="addContact">{{ $t('label.add') }}</VueButton>
                </div>
            </div>
        </div>

        <!-- Contacts where I'm trusted -->
        <div v-if="contactsForMe.length" class="box">
            <h4 class="title is-5">{{ $t('title.emergency_contacts_for_me') }}</h4>
            <div v-for="c in contactsForMe" :key="c.id" class="is-flex is-align-items-center is-justify-content-space-between mb-2">
                <span>{{ c.owner?.name }} ({{ c.owner?.email }}) — <em class="is-size-7">{{ c.access_type }}</em></span>
                <div class="buttons are-small">
                    <VueButton v-if="c.status === 'confirmed'" class="button is-warning" @click="requestMyAccess(c)">
                        {{ $t('label.request_emergency_access') }}
                    </VueButton>
                    <VueButton v-else-if="c.status === 'active'" class="button is-success" :isLoading="isLoadingVault" @click="openVault(c)">
                        {{ $t('label.access_vault') }}
                    </VueButton>
                </div>
            </div>
        </div>

        <!-- F1: grantee read-only vault viewer -->
        <div v-if="vaultViewer" class="box mt-4">
            <h4 class="title is-5">{{ $t('title.emergency_vault', { owner: vaultViewer.data.owner?.name }) }}</h4>
            <p class="is-size-7 has-text-grey mb-3">{{ $t('message.emergency_vault_readonly') }}</p>
            <table class="table is-fullwidth is-striped is-size-7">
                <thead>
                    <tr><th>{{ $t('label.service') }}</th><th>{{ $t('label.account') }}</th><th>{{ $t('label.secret') }}</th></tr>
                </thead>
                <tbody>
                    <tr v-for="account in vaultViewer.data.accounts" :key="account.id">
                        <td>{{ account.service }}</td>
                        <td>{{ account.account }}</td>
                        <td>
                            <template v-if="revealedSecrets[account.id] !== undefined">
                                <code v-if="revealedSecrets[account.id]">{{ revealedSecrets[account.id] }}</code>
                                <span v-else class="has-text-grey">{{ $t('error.cannot_decrypt_secret') }}</span>
                            </template>
                            <VueButton v-else class="button is-small" @click="revealSecret(account)">{{ $t('label.reveal') }}</VueButton>
                        </td>
                    </tr>
                </tbody>
            </table>
            <VueButton class="button is-small mt-2" @click="vaultViewer = null; revealedSecrets = {}">{{ $t('label.close') }}</VueButton>
        </div>
    </div>
    </div>
</template>
