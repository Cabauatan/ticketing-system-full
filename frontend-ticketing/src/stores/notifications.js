import { defineStore } from 'pinia'
import http, { apiError } from '@/api/http'

export const useNotificationStore = defineStore('notifications', {
    state: () => ({
        notifications: [],
        recent: [],
        page: 1,
        total: 0,
        unread: 0,
        loading: false,
        loadingMore: false,
        previewLoading: false,
        markingId: null,
        error: '',
    }),
    getters: {
        hasMore: (state) => state.notifications.length < state.total,
    },
    actions: {
        async fetchPage({ append = false } = {}) {
            if (append) this.loadingMore = true
            else this.loading = true
            this.error = ''
            try {
                const nextPage = append ? this.page + 1 : 1
                const { data } = await http.get('/api/notifications', { params: { page: nextPage } })
                const payload = data.data || {}
                const rows = payload.notifications || []
                this.notifications = append ? [...this.notifications, ...rows] : rows
                this.page = Number(payload.page || nextPage)
                this.total = Number(payload.total || 0)
                this.unread = Number(payload.unread || 0)
                return payload
            } catch (error) {
                this.error = apiError(error).message
                throw error
            } finally {
                this.loading = false
                this.loadingMore = false
            }
        },
        async fetchUnread() {
            try {
                const { data } = await http.get('/api/notifications', { params: { page: 1 } })
                this.unread = Number(data.data?.unread || 0)
                return this.unread
            } catch (error) {
                throw error
            }
        },
        async fetchPreview() {
            this.previewLoading = true
            try {
                const { data } = await http.get('/api/notifications', { params: { page: 1 } })
                const payload = data.data || {}
                this.recent = (payload.notifications || []).slice(0, 5)
                this.unread = Number(payload.unread || 0)
                return this.recent
            } finally {
                this.previewLoading = false
            }
        },
        async markRead(item) {
            if (item.read_at || this.markingId === item.id) return
            this.markingId = item.id
            this.error = ''
            try {
                await http.patch(`/api/notifications/${item.id}/read`)
                const readAt = new Date().toISOString()
                for (const list of [this.notifications, this.recent]) {
                    const row = list.find((entry) => entry.id === item.id)
                    if (row && !row.read_at) row.read_at = readAt
                }
                this.unread = Math.max(0, this.unread - 1)
                window.dispatchEvent(new CustomEvent('supportdesk:refresh'))
            } catch (error) {
                this.error = apiError(error).message
                throw error
            } finally {
                this.markingId = null
            }
        },
    },
})
