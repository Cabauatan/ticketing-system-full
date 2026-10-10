import axios from 'axios'

let csrfRequest
let csrfToken = ''

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
        csrfRequest = http.get('/api/csrf-token')
            .then(({ data }) => {
                csrfToken = data.token
                return csrfToken
            })
            .finally(() => { csrfRequest = undefined })
    }
    return csrfRequest
}

export async function refreshCsrf() {
    csrfToken = ''
    csrfRequest = undefined
    return ensureCsrf()
}

http.interceptors.request.use(async (config) => {
    if (!['get', 'head', 'options'].includes((config.method || 'get').toLowerCase())) {
        const token = await ensureCsrf()
        config.headers['X-CSRF-TOKEN'] = token
    }
    return config
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
