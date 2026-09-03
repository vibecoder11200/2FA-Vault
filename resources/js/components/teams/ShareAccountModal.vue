<template>
    <div class="modal" :class="{ 'is-active': show }" role="dialog" aria-modal="true" aria-labelledby="share-modal-title" @keydown.escape="$emit('close')">
        <div class="modal-background" @click="$emit('close')" aria-hidden="true"></div>
        <div class="modal-card">
            <header class="modal-card-head">
                <p class="modal-card-title" id="share-modal-title">{{ $t('teams.share_encrypted') }}</p>
                <button class="delete" :aria-label="$t('label.close')" @click="$emit('close')"></button>
            </header>
            <section class="modal-card-body">
                <div v-if="!keyPairReady" class="notification is-warning is-size-7 mb-3">
                    {{ $t('teams.key_pair_not_ready') }}
                    <button class="button is-small is-warning ml-3" @click="$emit('init-key-pair')" :class="{ 'is-loading': isInitingKeys }">
                        {{ $t('teams.setup_key_pair') }}
                    </button>
                </div>
                <div v-else>
                    <div class="field">
                        <label class="label">{{ $t('label.access_level') }}</label>
                        <div class="select">
                            <select v-model="shareAccessLevel">
                                <option value="read">{{ $t('label.read_only') }}</option>
                                <option value="write">{{ $t('label.full_access') }}</option>
                            </select>
                        </div>
                    </div>
                    <div class="field">
                        <label class="label">{{ $t('teams.select_members') }}</label>
                        <div v-for="member in members" :key="member.id" class="is-flex is-align-items-center mb-2">
                            <input type="checkbox" :id="'member-' + member.id" :value="member.id" v-model="selectedMemberIds" class="mr-2" />
                            <label :for="'member-' + member.id">{{ member.name }} ({{ member.email }})</label>
                        </div>
                    </div>
                    <div class="field">
                        <label class="label">{{ $t('label.account') }}</label>
                        <div class="control">
                            <div class="select is-fullwidth">
                                <!-- B1: share an existing vault account by id. The
                                     secret is decrypted client-side by the caller and
                                     wrapped per member — it is never pasted as text. -->
                                <select v-model="selectedAccountId">
                                    <option :value="null" disabled>{{ $t('teams.select_account_to_share') }}</option>
                                    <option v-for="account in accounts" :key="account.id" :value="account.id">
                                        {{ accountLabel(account) }}
                                    </option>
                                </select>
                            </div>
                        </div>
                        <p class="help">{{ $t('teams.secret_never_sent_to_server') }}</p>
                    </div>
                </div>
            </section>
            <footer class="modal-card-foot">
                <button
                    @click="$emit('share', { accountId: selectedAccountId, memberIds: selectedMemberIds, accessLevel: shareAccessLevel })"
                    class="button is-success"
                    :class="{ 'is-loading': isSharing }"
                    :disabled="!keyPairReady || !selectedAccountId || selectedMemberIds.length === 0"
                >
                    {{ $t('teams.share_now') }}
                </button>
                <button @click="$emit('close')" class="button">{{ $t('label.cancel') }}</button>
            </footer>
        </div>
    </div>
</template>

<script setup>
    const props = defineProps({
        show: { type: Boolean, required: true },
        keyPairReady: { type: Boolean, default: false },
        isInitingKeys: { type: Boolean, default: false },
        isSharing: { type: Boolean, default: false },
        members: { type: Array, default: () => [] },
        accounts: { type: Array, default: () => [] },
    })

    defineEmits(['close', 'init-key-pair', 'share'])

    const shareAccessLevel = ref('read')
    const selectedAccountId = ref(null)
    const selectedMemberIds = ref([])

    function accountLabel(account) {
        const service = account.service || account.account || `#${account.id}`

        return account.account && account.service ? `${account.service} (${account.account})` : service
    }
</script>
