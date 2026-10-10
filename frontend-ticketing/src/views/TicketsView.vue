<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { storeToRefs } from 'pinia'
import { RouterLink } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useTicketStore } from '@/stores/tickets'
const auth = useAuthStore()
const ticketStore = useTicketStore()
const { rows } = storeToRefs(ticketStore)
const search = ref(''),
    status = ref(''),
    loading = ref(false),
    error = ref('')
let timer
async function load() {
    loading.value = true
    try {
        await ticketStore.fetchList({ search: search.value, status: status.value })
        error.value = ''
    } catch (e) {
        error.value = e.response?.data?.message || 'Hindi makuha ang tickets.'
    } finally {
        loading.value = false
    }
}
const refresh = () => load()
onMounted(() => {
    load()
    window.addEventListener('supportdesk:refresh', refresh)
})
onBeforeUnmount(() => {
    clearTimeout(timer)
    window.removeEventListener('supportdesk:refresh', refresh)
})
function searchLater() {
    clearTimeout(timer)
    timer = setTimeout(load, 250)
}
</script>
<template>
    <div class="page-heading">
        <div>
            <h2>Tickets</h2>
            <p>Track requests and follow up with your support team.</p>
        </div>
        <RouterLink
            v-if="auth.can('tickets.create')"
            class="button button-primary"
            to="/tickets/new"
            ><i class="pi pi-plus" /> Create ticket</RouterLink
        >
    </div>
    <div class="panel">
        <div class="toolbar">
            <input
                v-model="search"
                class="input"
                placeholder="Search tickets…"
                @input="searchLater"
            /><select v-model="status" class="select" style="max-width: 190px" @change="load">
                <option value="">All statuses</option>
                <option
                    v-for="s in ['new', 'open', 'in_progress', 'pending', 'resolved', 'closed']"
                    :key="s"
                    :value="s"
                >
                    {{ s.replace('_', ' ') }}
                </option></select
            ><span v-if="loading" class="muted">Loading…</span>
        </div>
        <p v-if="error" class="error-box">{{ error }}</p>
        <div v-if="rows.length" class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Ticket</th>
                        <th>Subject</th>
                        <th>Requester</th>
                        <th>Priority</th>
                        <th>Status</th>
                        <th>Updated</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="t in rows" :key="t.id">
                        <td>
                            <RouterLink :to="`/tickets/${t.id}`">#{{ t.id }}</RouterLink>
                        </td>
                        <td>{{ t.subject }}</td>
                        <td>{{ t.requester_name || t.name || '—' }}</td>
                        <td>
                            <span class="badge">{{ t.priority }}</span>
                        </td>
                        <td>
                            <span class="badge" :class="`badge-${t.status}`">{{
                                (t.status || '').replace('_', ' ')
                            }}</span>
                        </td>
                        <td>{{ t.updated_at || '—' }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div v-else-if="!loading" class="empty-state">Walang ticket na makita.</div>
    </div>
</template>
