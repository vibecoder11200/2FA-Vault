import axios from "axios"
import { useUserStore } from '@/stores/user'
import { useErrorHandler } from '@2fauth/stores'

const getCookie = (name) => {
	const cookie = document.cookie
		.split('; ')
		.find((entry) => entry.startsWith(`${name}=`))

	if (!cookie) {
		return null
	}

	return decodeURIComponent(cookie.substring(name.length + 1))
}

const getCsrfToken = () => getCookie('XSRF-TOKEN')

export const httpClientFactory = (endpoint = 'api') => {
	let baseURL
    const subdir = window.appConfig.subdirectory

	if (endpoint === 'web') {
		baseURL = subdir + '/'
	} else {
		baseURL = subdir + '/api/v1'
	}

	const httpClient = axios.create({
		baseURL,
		headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/json' },
		withCredentials: true,
		xsrfCookieName: 'XSRF-TOKEN',
		xsrfHeaderName: 'X-XSRF-TOKEN',
		withXSRFToken: true,
	})

	httpClient.interceptors.request.use(
		async function (config) {
			const method = (config.method ?? 'get').toLowerCase()
			const isUnsafeMethod = ['post', 'put', 'patch', 'delete'].includes(method)

			if (!isUnsafeMethod) {
				return config
			}

			let csrfToken = getCsrfToken()

			if (!csrfToken) {
				await httpClient.get('/refresh-csrf')
				csrfToken = getCsrfToken()
			}

			if (csrfToken) {
				config.headers = {
					...config.headers,
					'X-XSRF-TOKEN': csrfToken,
				}
			}

			return config
		},
		(error) => Promise.reject(error)
	)

    httpClient.interceptors.response.use(
        (response) => {
            return response;
        },
		async function (error) {
			const originalRequestConfig = error.config

            // Here we handle a missing/invalid CSRF cookie
            // We try to get a fresh on, but only once.
			if (error.response.status === 419 && ! originalRequestConfig._retried) {
				originalRequestConfig._retried = true;
				delete originalRequestConfig.headers?.['X-XSRF-TOKEN']
				await httpClient.get('/refresh-csrf')

				return httpClient.request(originalRequestConfig)
			}

            // api calls are stateless so when user inactivity is detected
            // by the backend middleware, it cannot logout the user directly
            // so it returns a 418 response.
            // We catch the 418 response and log the user out
            if (error.response.status === 418) {
                const user = useUserStore()
                user.logout({ kicked: true})
            }
            
            if (error.response && [407].includes(error.response.status)) {
                useErrorHandler().show(error)
                return new Promise(() => {})
            }

            // Return the error when we need to handle it at component level
            if (error.config.hasOwnProperty('returnError') && error.config.returnError === true) {
                return Promise.reject(error)
            }
            
            if (error.response && [401].includes(error.response.status)) {
                const user = useUserStore()
                user.tossOut()
            }

            // Always return the form validation errors
            if (error.response.status === 422) {
                return Promise.reject(error)
            }

            // Not found
            if (error.response.status === 404) {
                useErrorHandler().notFound()
                return new Promise(() => {})
            }

            useErrorHandler().show(error)
            return new Promise(() => {})
        }
    )

	return httpClient
}

// Default export for backward compatibility
export default httpClientFactory
