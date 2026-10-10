import { defineStore } from 'pinia'
import http, { downloadFile } from '@/api/http'

export const useReportStore = defineStore('reports', {
    state: () => ({
        tickets: { summary: {}, breakdown: [], trend: [], agents: [] },
        inventory: { summary: {}, by_status: [], by_type: [], by_division: [], canExportInventory: false },
    }),
    actions: {
        async fetchTickets(params = {}) {
            const { data } = await http.get('/api/reports', { params })
            this.tickets = data.data
            return this.tickets
        },
        async fetchInventory() {
            const { data } = await http.get('/api/reports/inventory')
            this.inventory = data.data
            return this.inventory
        },
        exportTickets(params = {}) {
            return downloadFile('/api/reports/export.xlsx', params)
        },
        exportInventory() {
            return downloadFile('/api/reports/inventory.xlsx')
        },
    },
})
