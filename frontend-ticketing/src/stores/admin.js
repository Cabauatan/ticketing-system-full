import { defineStore } from 'pinia'
import http from '@/api/http'

export const useAdminStore = defineStore('admin', {
    state: () => ({ users: [], audit: [], categories: [], canManageSuperadmins: false, permissions: null }),
    actions: {
        async fetchWorkspace(search = '') {
            const { data } = await http.get('/api/admin', { params: { search } })
            const payload = data.data
            this.users = (payload.users || []).map((user) => ({ ...user, roles: user.roles || [user.role] }))
            this.audit = payload.audit || []
            this.categories = payload.categories || []
            this.canManageSuperadmins = Boolean(payload.canManageSuperadmins)
            return payload
        },
        async fetchPermissions() {
            const { data } = await http.get('/api/admin/permissions')
            this.permissions = data.data
            return this.permissions
        },
        updateUserRoles(id, roles) {
            return http.patch(`/api/admin/users/${id}/role`, { roles })
        },
        createCategory(payload) {
            return http.post('/api/admin/categories', payload)
        },
        updateRolePermissions(role, payload) {
            return http.put(`/api/admin/permissions/${role}`, payload)
        },
    },
})
