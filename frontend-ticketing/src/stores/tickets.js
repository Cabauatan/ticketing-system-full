import { defineStore } from 'pinia'
import http, { downloadFile } from '@/api/http'

export const useTicketStore = defineStore('tickets', {
    state: () => ({
        rows: [],
        current: null,
        messages: [],
        assets: [],
        attachments: [],
        agents: [],
        categories: [],
        canManage: false,
        canAssign: false,
        canChangePriority: false,
        canInternal: false,
    }),
    actions: {
        async fetchCategories() {
            const { data } = await http.get('/api/ticket-categories')
            this.categories = data.data.categories || []
            return this.categories
        },
        async fetchList(params = {}) {
            const { data } = await http.get('/api/tickets', { params })
            this.rows = data.data.tickets || []
            return data.data
        },
        async create(payload) {
            const { data } = await http.post('/api/tickets', payload)
            return data.data
        },
        async fetchDetail(id) {
            const { data } = await http.get(`/api/tickets/${id}`)
            const result = data.data
            this.current = result.ticket
            this.attachments = result.attachments || []
            this.agents = result.agents || []
            this.canManage = Boolean(result.canManage)
            this.canAssign = Boolean(result.canAssign)
            this.canChangePriority = Boolean(result.canChangePriority)
            this.canInternal = Boolean(result.canInternal)
            return result
        },
        async fetchChat(id) {
            const { data } = await http.get(`/api/tickets/${id}/chat`)
            this.messages = data.data.messages || []
            return this.messages
        },
        async fetchAssets(id) {
            const { data } = await http.get(`/api/tickets/${id}/assets`)
            this.assets = data.data || []
            return this.assets
        },
        async addComment(id, payload) {
            return http.post(`/api/tickets/${id}/comments`, payload)
        },
        async uploadAttachment(id, form) {
            return http.post(`/api/tickets/${id}/attachments`, form)
        },
        async update(id, payload) {
            return http.patch(`/api/tickets/${id}`, payload)
        },
        async reopen(id) {
            return http.post(`/api/tickets/${id}/reopen`)
        },
        async downloadAttachment(ticketId, attachmentId) {
            return downloadFile(`/api/tickets/${ticketId}/attachments/${attachmentId}`)
        },
    },
})
