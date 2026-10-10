import { defineStore } from 'pinia'
import http, { ensureCsrf, refreshCsrf } from '@/api/http'

export const useAuthStore = defineStore('auth', {
    state: () => ({ user: null, roles: [], permissions: [], ready: false, emailUnverified: false }),
    getters: {
        authenticated: (state) => Boolean(state.user),
        role: (state) => state.user?.role || 'requester',
        isStaff: (state) => state.roles.some((role) => ['agent', 'dispatcher', 'admin', 'superadmin'].includes(role)),
        isAdmin: (state) => state.roles.some((role) => ['admin', 'superadmin'].includes(role)),
        can: (state) => (permission) => state.permissions.includes(permission),
    },
    actions: {
        async restoreSession() {
            if (this.ready) return this.authenticated
            try {
                await ensureCsrf()
                const { data } = await http.get('/api/me')
                this.user = data.data.user
                this.roles = data.data.user.roles || [data.data.user.role]
                this.emailUnverified = !data.data.email_verified
                if (!this.emailUnverified) {
                    const permissions = await http.get('/api/permissions')
                    this.permissions = permissions.data.data.permissions || []
                }
            } catch (error) {
                if (error.response?.status !== 401) throw error
                this.user = null
                this.roles = []
                this.permissions = []
                this.emailUnverified = false
            } finally {
                this.ready = true
            }
            return this.authenticated
        },
        async signIn(credentials) {
            await ensureCsrf()
            const { data } = await http.post('/login', credentials)
            await refreshCsrf()
            if (data?.two_factor) return { twoFactor: true }
            this.ready = false
            await this.restoreSession()
            return { twoFactor: false, emailUnverified: this.emailUnverified }
        },
        async completeTwoFactor(payload) {
            await http.post('/two-factor-challenge', payload)
            await refreshCsrf()
            this.ready = false
            await this.restoreSession()
            return { emailUnverified: this.emailUnverified }
        },
        async register(form) {
            await http.post('/register', form)
            await refreshCsrf()
            this.ready = false
            await this.restoreSession()
            return this.emailUnverified
        },
        async resendVerification() {
            return http.post('/email/verification-notification')
        },
        async requestPasswordReset(email) {
            const { data } = await http.post('/forgot-password', { email })
            return data
        },
        async resetPassword(payload) {
            return http.post('/reset-password', payload)
        },
        async refreshPermissions() {
            const { data } = await http.get('/api/permissions')
            this.permissions = data.data.permissions || []
        },
        async signOut() {
            try { await http.post('/logout') } finally {
                this.user = null
                this.roles = []
                this.permissions = []
                this.emailUnverified = false
                this.ready = true
            }
        },
    },
})
