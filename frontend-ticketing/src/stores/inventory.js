import { defineStore } from 'pinia'
import http, { downloadFile } from '@/api/http'

export const useInventoryStore = defineStore('inventory', {
    state: () => ({ rows: [], total: 0, current: null }),
    actions: {
        async fetchList(params = {}) {
            const { data } = await http.get('/api/inventory', { params })
            this.rows = data.data.assets || []
            this.total = Number(data.data.total || 0)
            return data.data
        },
        async fetchDetail(id) {
            const { data } = await http.get(`/api/inventory/${id}`)
            this.current = data.data.asset
            return this.current
        },
        async create(payload) {
            const { data } = await http.post('/api/inventory', payload)
            return data.data
        },
        async update(id, payload) {
            return http.patch(`/api/inventory/${id}`, payload)
        },
        async recordMovement(id, payload) {
            return http.post(`/api/inventory/${id}/movements`, payload)
        },
        async recordStock(id, payload) {
            return http.post(`/api/inventory/${id}/stock`, payload)
        },
        async linkTicket(id, payload) {
            return http.post(`/api/inventory/${id}/tickets`, payload)
        },
        async uploadAttachment(id, form) {
            return http.post(`/api/inventory/${id}/attachments`, form)
        },
        async downloadAttachment(assetId, attachmentId) {
            return downloadFile(`/api/inventory/${assetId}/attachments/${attachmentId}`)
        },
    },
})
