import { httpClientFactory } from '@/services/httpClientFactory'

const webClient = httpClientFactory('web')
const apiClient = httpClientFactory('api')

export default {
    /**
     * A10: logout is POST + CSRF-protected. The CSRF token is picked up from
     * the XSRF cookie by the axios client automatically.
     */
    logout(config = {}) {
        return webClient.post('/user/logout', { }, { ...config })
    },

    /**
     * 
     */
    async getCurrentUser(config = {}) {
        return apiClient.get('/user', { ...config })
    },
    
}