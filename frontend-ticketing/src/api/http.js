import axios from 'axios'

let csrfRequest
let csrfRefreshRequest
let csrfToken = ''
let csrfGeneration = 0

export const http = axios.create({
    baseURL: (import.meta.env.VITE_API_URL || import.meta.env.VITE_API_BASE_URL || '').replace(/\/+$/, ''),
    timeout: 30000,
    withCredentials: true,
    withXSRFToken: true,
    headers: { Accept: 'application/json' },
})

export async function ensureCsrf() {
    if (csrfToken) return csrfToken
    if (!csrfRequest) {
        const generation = csrfGeneration
        const request = http.get('/api/csrf-token')
            .then(({ data }) => {
                if (generation !== csrfGeneration) return ensureCsrf()
                csrfToken = data.token
                return csrfToken
            })
            .finally(() => { if (csrfRequest === request) csrfRequest = undefined })
        csrfRequest = request
    }
    return csrfRequest
}

export function resetCsrf() {
    csrfGeneration += 1
    csrfToken = ''
    csrfRequest = undefined
    csrfRefreshRequest = undefined
}

export async function refreshCsrf() {
    if (!csrfRefreshRequest) {
        resetCsrf()
        const request = ensureCsrf()
            .finally(() => { if (csrfRefreshRequest === request) csrfRefreshRequest = undefined })
        csrfRefreshRequest = request
    }
    return csrfRefreshRequest
}

const isMutation = (config) => !['get', 'head', 'options'].includes((config.method || 'get').toLowerCase())

http.interceptors.request.use(async (config) => {
    if (isMutation(config)) {
        const token = await ensureCsrf()
        config.headers ||= {}
        config.headers['X-CSRF-TOKEN'] = token
    }
    return config
})

http.interceptors.response.use(undefined, async (error) => {
    const config = error.config
    if (error.response?.status !== 419 || !config || !isMutation(config) || config._csrfRetried) throw error

    config._csrfRetried = true
    const rejectedToken = config.headers?.get?.('X-CSRF-TOKEN') || config.headers?.['X-CSRF-TOKEN']
    if (!csrfToken || !rejectedToken || rejectedToken === csrfToken) await refreshCsrf()
    return http.request(config)
})

export function apiError(error) {
    const response = error?.response?.data
    return {
        message: response?.message || error?.message || 'Hindi makakonekta sa server. Subukan ulit.',
        errors: response?.errors || {},
        status: error?.response?.status,
    }
}

export async function downloadFile(url, params = {}) {
    const response = await http.get(url, { params, responseType: 'blob' })
    const disposition = response.headers['content-disposition'] || ''
    const filename = disposition.match(/filename="?([^";]+)"?/i)?.[1] || 'report.xlsx'
    const href = URL.createObjectURL(response.data)
    const link = document.createElement('a')
    link.href = href
    link.download = filename
    document.body.append(link)
    link.click()
    link.remove()
    window.setTimeout(() => URL.revokeObjectURL(href), 1000)
}

export default http
