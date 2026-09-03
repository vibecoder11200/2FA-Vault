<script setup>
    import { RouterView } from 'vue-router'
    import { Kicker } from '@2fauth/ui'
    import { useCryptoStore } from '@/stores/crypto'
    import { useBusStore } from '@/stores/bus'
    import UpdatePrompt from '@/components/UpdatePrompt.vue'
    import OfflineIndicator from '@/components/OfflineIndicator.vue'
    import PwaInstallPrompt from '@/components/PwaInstallPrompt.vue'

    const { t } = useI18n()
    const { language } = useNavigatorLanguage()
    const route = useRoute()
    const user = inject('userStore')
    const cryptoStore = useCryptoStore()

    const mustKick = ref(false)
    const kickUserAfter = ref(null)
    const isProtectedRoute = ref(route.meta.watchedByKicker)
    const vaultAutoLockTimer = ref(null)

    mustKick.value = user.isAuthenticated
    kickUserAfter.value = parseInt(user.preferences.kickUserAfter)

    function clearVaultAutoLockTimer() {
        if (vaultAutoLockTimer.value) {
            clearTimeout(vaultAutoLockTimer.value)
            vaultAutoLockTimer.value = null
        }
    }

    async function lockVaultSession() {
        clearVaultAutoLockTimer()

        if (!user.isAuthenticated || !cryptoStore.isVaultUnlocked || user.encryption_version <= 0) {
            return
        }

        cryptoStore.lockVault()
        user.vault_locked = true

        const accounts = useTwofaccounts()
        accounts.$reset()

        // E8: scrub everything that still holds plaintext while the vault is
        // now locked — otpauth URIs carry the raw secret, an open OTP modal
        // shows the last code, and the offline service caches derived keys.
        const bus = useBusStore()
        bus.decodedUri = null
        bus.migrationUri = null
        bus.inManagementMode = false

        try {
            const { default: offlineTotp } = await import('@/services/offline-totp.js')
            offlineTotp.lockVault()
        } catch (error) {
            console.debug('offline totp lock skipped', error)
        }

        // Signal views (e.g. Accounts closes an open OTP modal) via the bus.
        bus.vaultLockedAt = Date.now()

        try {
            const { default: httpClientFactory } = await import('@/services/httpClientFactory')
            await httpClientFactory('api').post('/encryption/lock')
        } catch (error) {
            console.debug('Vault lock sync failed', error)
        }
    }

    /**
     * E8: fire-and-forget lock POST that survives page tear-down (the axios
     * call above is usually dropped mid-unload). Same-origin fetch with
     * keepalive carries the session cookies; CSRF header mirrors the SPA one.
     */
    function sendLockBeacon() {
        try {
            const xsrf = document.cookie.match(/XSRF-TOKEN=([^;]+)/)
            const baseUrl = import.meta.env.BASE_URL ?? '/'
            fetch(baseUrl.replace(/\/$/, '') + '/api/v1/encryption/lock', {
                method: 'POST',
                keepalive: true,
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json',
                    ...(xsrf ? { 'X-XSRF-TOKEN': decodeURIComponent(xsrf[1]) } : {}),
                },
            }).catch(() => {})
        } catch (error) {
            console.debug('lock beacon failed', error)
        }
    }

    function onUnloadLock(unloadEvent) {
        lockVaultSession()
        sendLockBeacon()
    }

    function scheduleVaultAutoLock() {
        clearVaultAutoLockTimer()

        if (!user.isAuthenticated || !cryptoStore.isVaultUnlocked || user.encryption_version <= 0) {
            return
        }

        if (user.preferences.vaultAutoLockMode === 'immediately') {
            vaultAutoLockTimer.value = setTimeout(() => {
                lockVaultSession()
            }, 0)
            return
        }

        if (user.preferences.vaultAutoLockMode === 'inactivity') {
            const minutes = parseInt(user.preferences.vaultAutoLockMinutes ?? 0)
            if (minutes > 0) {
                vaultAutoLockTimer.value = setTimeout(() => {
                    lockVaultSession()
                }, minutes * 60 * 1000)
            }
        }
    }

    function registerVaultActivity() {
        if (!user.isAuthenticated || !cryptoStore.isVaultUnlocked || user.encryption_version <= 0) {
            return
        }

        if (user.preferences.vaultAutoLockMode === 'immediately') {
            lockVaultSession()
            return
        }

        scheduleVaultAutoLock()
    }

    watch(
        () => user.preferences.kickUserAfter,
        () => {
            kickUserAfter.value = parseInt(user.preferences.kickUserAfter)
        }
    )
    watch(
        () => user.isAuthenticated,
        () => {
            mustKick.value = user.isAuthenticated
            scheduleVaultAutoLock()
        }
    )
    watch(
        () => user.preferences.vaultAutoLockMode,
        () => {
            scheduleVaultAutoLock()
        }
    )
    watch(
        () => user.preferences.vaultAutoLockMinutes,
        () => {
            scheduleVaultAutoLock()
        }
    )
    watch(
        () => cryptoStore.isVaultUnlocked,
        () => {
            scheduleVaultAutoLock()
        }
    )

    watch(language, () => {
        user.applyLanguage()
    })

    watch(
        () => route.name,
        () => {
            isProtectedRoute.value = route.meta.watchedByKicker
            registerVaultActivity()
            nextTick(() => {
                const main = document.getElementById('main-content')
                if (main) main.focus()
            })
        }
    )

    onMounted(() => {
        window.addEventListener('click', registerVaultActivity)
        window.addEventListener('keydown', registerVaultActivity)
        window.addEventListener('mousemove', registerVaultActivity)
        window.addEventListener('scroll', registerVaultActivity, true)
        // E8: pagehide keeps firing reliably during tab tear-down where
        // beforeunload async work (the lock POST) is usually dropped.
        window.addEventListener('pagehide', onUnloadLock)
        window.addEventListener('beforeunload', onUnloadLock)
    })

    onUnmounted(() => {
        clearVaultAutoLockTimer()
        window.removeEventListener('click', registerVaultActivity)
        window.removeEventListener('keydown', registerVaultActivity)
        window.removeEventListener('mousemove', registerVaultActivity)
        window.removeEventListener('scroll', registerVaultActivity, true)
        window.removeEventListener('pagehide', onUnloadLock)
        window.removeEventListener('beforeunload', onUnloadLock)
    })

    router.afterEach((to, from) => {
        to.meta.title = t('title.' + to.name)
        document.title = to.meta.title
    })

</script>

<template>
    <a href="#main-content" class="skip-to-content" @click.prevent="document.getElementById('main-content')?.focus()">
        {{ $t('label.skip_to_content') }}
    </a>
    <notifications
        id="vueNotification"
        role="alert"
        width="100%"
        position="top"
        :duration="4000"
        :speed="0"
        :max="1"
        classes="notification notification-banner is-radiusless" />
    <main id="main-content" class="main-section" role="main" tabindex="-1">
        <RouterView />
    </main>
    <!-- E4: PWA layer wired — update consent, offline badge, install prompt -->
    <UpdatePrompt />
    <OfflineIndicator />
    <PwaInstallPrompt />
    <Kicker
        v-if="mustKick && kickUserAfter > 0 && isProtectedRoute"
        :kickAfter="kickUserAfter"
        @kicked="() => user.logout({ kicked: true})"
    />
</template>

<style scoped>
.skip-to-content {
    position: absolute;
    left: -9999px;
    z-index: 999;
    padding: 0.5rem 1rem;
    background: #4f46e5;
    color: white;
    font-weight: 600;
    text-decoration: none;
    border-radius: 0 0 4px 0;
}
.skip-to-content:focus {
    left: 0;
    top: 0;
}
</style>