import i18n from './i18n'
import Notifications from '@kyvg/vue3-notification'
import App from './App.vue'
import router from './router'
import { configureErrorHandlerRouter } from '@2fauth/stores'

import '@2fauth/styles/src/app.scss';

// Register the router with the errorHandler store so its show()/notFound()
// actions can navigate even when invoked from a non-component context (e.g.
// Axios interceptors in service modules, where `this.$router` is unavailable).
configureErrorHandlerRouter(router)

const app = createApp(App)

// Immutable app properties provided by the laravel blade view
const $2fauth = {
    prefix: '2fauth_',
    config: window.appConfig,
    version: window.appVersion,
    isDemoApp: window.isDemoApp,
    isTestingApp: window.isTestingApp,
    langs: window.appLocales,
    urls: window.urls,
    context: 'webapp',
}
app.provide('2fauth', readonly($2fauth))

// Localization
app.use(i18n)

// Stores
const pinia = createPinia()
pinia.use(({ store }) => {
    store.$2fauth = $2fauth
    store.$i18n = i18n
    store.$router = markRaw(router)
})
app.use(pinia)

// Router
app.use(router)

// Notifications
app.use(Notifications)

// Global components registration
import {
    FormField,
    FormPasswordField,
    FormFieldError,
    FormCheckbox,
    FormSelect,
    FormToggle,
    FormButtons,
    NavigationButton,
    VueButton
} from '@2fauth/formcontrols'

import {
    StackLayout,
    FormWrapper,
    Modal,
    ResponsiveWidthWrapper,
    Spinner,
    VueFooter,
} from '@2fauth/ui'

app
    .component('ResponsiveWidthWrapper', ResponsiveWidthWrapper)
    .component('Spinner', Spinner)
    .component('StackLayout', StackLayout)
    .component('FormWrapper', FormWrapper)
    .component('VueFooter', VueFooter)
    .component('Modal', Modal)
    .component('VueButton', VueButton)
    .component('NavigationButton', NavigationButton)
    .component('FormFieldError', FormFieldError)
    .component('FormField', FormField)
    .component('FormPasswordField', FormPasswordField)
    .component('FormSelect', FormSelect)
    .component('FormToggle', FormToggle)
    .component('FormCheckbox', FormCheckbox)
    .component('FormButtons', FormButtons)

// Global error handling
app.config.errorHandler = (err, instance, info) => {
    if (import.meta.env.DEV) {
        console.error('[2FA-Vault] Unhandled error:', err, info)
    }
}

// Helpers
// app.config.globalProperties.$helpers = helpers

// App inject for footer
// TODO : Try to avoid those global injection
import { useUserStore } from '@/stores/user'
import { useAppSettingsStore } from '@/stores/appSettings'

const user = useUserStore()
const appSettings = useAppSettingsStore()

user.applyUserPrefs()

app.provide('userStore', user)
app.provide('appSettingsStore', appSettings)

// App mounting
app.mount('#app')

// E4: register the service worker AFTER mount so first paint is never
// blocked. The SW scope is the app base (sw.js is served from public/).
if ('serviceWorker' in navigator && !import.meta.env.DEV) {
    window.addEventListener('load', () => {
        import('./services/pwa')
            .then(({ default: pwaService }) => pwaService.register())
            .catch(error => console.debug('PWA registration failed', error))
    })
}