/**
 * Enforce encryption setup/unlock routing for authenticated users
 * without creating redirect loops.
 */
export default function encryptionGate({ to, next, nextMiddleware, stores }) {
    const { user, cryptoStore } = stores

    if (!user.isAuthenticated) {
        nextMiddleware()
        return
    }

    const routeName = to.name
    const isSetupRoute = routeName === 'setup-encryption'
    const isUnlockRoute = routeName === 'unlock-vault'
    const hasEncryption = user.encryption_version > 0
    const requiresSetup = user.e2ee_required === true && !hasEncryption

    if (!hasEncryption) {
        cryptoStore.disableEncryption()
        user.vault_locked = false

        if (requiresSetup && !isSetupRoute) {
            next({ name: 'setup-encryption' })
            return
        }

        if (isUnlockRoute) {
            next({ name: 'accounts' })
            return
        }

        nextMiddleware()
        return
    }

    if (!cryptoStore.isVaultUnlocked) {
        if (!cryptoStore.isEncryptionEnabled) {
            cryptoStore.enableEncryption(null)
        }

        if (user.vault_locked !== true) {
            user.vault_locked = true
        }

        if (!isUnlockRoute) {
            next({ name: 'unlock-vault' })
            return
        }

        nextMiddleware()
        return
    }

    user.vault_locked = false

    if (isUnlockRoute || (isSetupRoute && user.e2ee_required === true)) {
        next({ name: 'accounts' })
        return
    }

    nextMiddleware()
}
