import { defineStore } from 'pinia'
import http from '@/api/http'

export const useDashboardStore = defineStore('dashboard', {
    state: () => ({ summary: [], inventory: null }),
    actions: {
        async fetchSummary(days = 30) {
            const { data } = await http.get('/api/dashboard', { params: { days } })
            this.summary = data.data.report || []
            return this.summary
        },
        async fetchInventory() {
            const { data } = await http.get('/api/reports/inventory')
            this.inventory = data.data
            return this.inventory
        },
    },
})
