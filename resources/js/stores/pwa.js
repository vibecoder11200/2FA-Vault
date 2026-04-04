import { defineStore } from 'pinia'
import { ref } from 'vue'

export const usePwaStore = defineStore('pwa', () => {
    const isInstalled = ref(false)
    const isOnline = ref(navigator.onLine)
    const canInstall = ref(false)
    const hasUpdate = ref(false)
    let deferredPrompt = null

    window.addEventListener('beforeinstallprompt', (e) => {
        e.preventDefault()
        deferredPrompt = e
        canInstall.value = true
    })

    window.addEventListener('appinstalled', () => {
        isInstalled.value = true
        canInstall.value = false
        deferredPrompt = null
    })

    window.addEventListener('online', () => { isOnline.value = true })
    window.addEventListener('offline', () => { isOnline.value = false })

    if (window.matchMedia('(display-mode: standalone)').matches) {
        isInstalled.value = true
    }

    async function promptInstall() {
        if (!deferredPrompt) return
        deferredPrompt.prompt()
        await deferredPrompt.userChoice
        deferredPrompt = null
    }

    function applyUpdate() {
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.getRegistration().then(reg => {
                if (reg && reg.waiting) {
                    reg.waiting.postMessage({ type: 'SKIP_WAITING' })
                }
            })
        }
        window.location.reload()
    }

    return { isInstalled, isOnline, canInstall, hasUpdate, promptInstall, applyUpdate }
})
