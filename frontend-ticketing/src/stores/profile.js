import { defineStore } from 'pinia'
import http from '@/api/http'

export const useProfileStore = defineStore('profile', {
    state: () => ({ profile: null }),
    actions: {
        async fetch() {
            const { data } = await http.get('/api/settings/profile')
            this.profile = data.data.user || data.data
            return this.profile
        },
        async save(payload) {
            const { data } = await http.patch('/api/settings/profile', payload)
            if (data.data?.user) this.profile = data.data.user
            return data.data
        },
        async uploadAvatar(form) {
            const { data } = await http.post('/api/settings/profile/avatar', form)
            this.profile = data.data.user
            return this.profile
        },
        async removeAvatar() {
            const { data } = await http.delete('/api/settings/profile/avatar')
            this.profile = data.data.user
            return this.profile
        },
        updatePassword(payload) {
            return http.put('/api/settings/password', payload)
        },
    },
})
