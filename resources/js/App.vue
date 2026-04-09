<script setup>
    import { RouterView } from 'vue-router'
    import { Kicker } from '@2fauth/ui'
    import { useCryptoStore } from '@/stores/crypto'

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

        try {
            const { default: httpClientFactory } = await import('@/services/httpClientFactory')
            await httpClientFactory('api').post('/encryption/lock')
        } catch (error) {
            console.debug('Vault lock sync failed', error)
        }
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
        }
    )

    onMounted(() => {
        window.addEventListener('click', registerVaultActivity)
        window.addEventListener('keydown', registerVaultActivity)
        window.addEventListener('mousemove', registerVaultActivity)
        window.addEventListener('scroll', registerVaultActivity, true)
        window.addEventListener('beforeunload', lockVaultSession)
    })

    onUnmounted(() => {
        clearVaultAutoLockTimer()
        window.removeEventListener('click', registerVaultActivity)
        window.removeEventListener('keydown', registerVaultActivity)
        window.removeEventListener('mousemove', registerVaultActivity)
        window.removeEventListener('scroll', registerVaultActivity, true)
        window.removeEventListener('beforeunload', lockVaultSession)
    })

    router.afterEach((to, from) => {
        to.meta.title = t('title.' + to.name)
        document.title = to.meta.title
    })

</script>

<template>
    <notifications
        id="vueNotification"
        role="alert"
        width="100%"
        position="top"
        :duration="4000"
        :speed="0"
        :max="1"
        classes="notification notification-banner is-radiusless" />
    <main class="main-section">
        <RouterView />
    </main>
    <Kicker
        v-if="mustKick && kickUserAfter > 0 && isProtectedRoute"
        :kickAfter="kickUserAfter"
        @kicked="() => user.logout({ kicked: true})"
    />
</template>