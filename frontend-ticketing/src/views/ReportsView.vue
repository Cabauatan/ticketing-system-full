<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { storeToRefs } from 'pinia'
import { useAuthStore } from '@/stores/auth'
import { useReportStore } from '@/stores/reports'

const auth = useAuthStore()
const reportStore = useReportStore()
const { tickets: report, inventory } = storeToRefs(reportStore)
const from = ref('')
const to = ref('')
const error = ref('')
const inventoryError = ref('')
const busy = ref(false)
const inventoryBusy = ref(false)
const inventoryVisible = computed(() => auth.can('inventory.view'))

function currency(value) {
    return new Intl.NumberFormat('en-PH', {
        style: 'currency',
        currency: 'PHP',
        maximumFractionDigits: 2,
    }).format(Number(value || 0))
}

function count(value) {
    return Number(value || 0).toLocaleString('en-PH')
}

function label(value) {
    return String(value || '')
        .replaceAll('_', ' ')
        .replace(/\b\w/g, (letter) => letter.toUpperCase())
}

async function loadTickets() {
    busy.value = true
    try {
        await reportStore.fetchTickets({ from: from.value || undefined, to: to.value || undefined })
        error.value = ''
    } catch (failure) {
        error.value = failure.response?.data?.message || 'Could not load the ticket report.'
    } finally {
        busy.value = false
    }
}

async function loadInventory() {
    if (!inventoryVisible.value) return
    inventoryBusy.value = true
    try {
        await reportStore.fetchInventory()
        inventoryError.value = ''
    } catch (failure) {
        inventoryError.value =
            failure.response?.data?.message || 'Could not load the inventory report.'
    } finally {
        inventoryBusy.value = false
    }
}

async function exportTickets() {
    try {
        await reportStore.exportTickets({
            from: from.value || undefined,
            to: to.value || undefined,
        })
    } catch (failure) {
        error.value = failure.response?.data?.message || 'Could not export the ticket report.'
    }
}

async function exportInventory() {
    try {
        await reportStore.exportInventory()
    } catch (failure) {
        inventoryError.value =
            failure.response?.data?.message || 'Could not export the inventory report.'
    }
}

onMounted(() => {
    loadTickets()
    loadInventory()
    window.addEventListener('supportdesk:refresh', refreshReports)
})
onBeforeUnmount(() => window.removeEventListener('supportdesk:refresh', refreshReports))

function refreshReports() {
    loadTickets()
    loadInventory()
}
</script>

<template>
    <div class="page-heading reports-page-heading">
        <div>
            <h2>Reports</h2>
            <p>Ticket performance and current inventory at a glance.</p>
        </div>
    </div>

    <section class="report-section">
        <div class="report-section-heading">
            <div>
                <span class="report-eyebrow">SERVICE DESK</span>
                <h3>Ticket and SLA report</h3>
                <p>Ticket summary, SLA health, and agent performance for the selected period.</p>
            </div>
            <button class="button button-primary" @click="exportTickets">
                <i class="pi pi-file-excel" /> Export Excel
            </button>
        </div>

        <section class="panel">
            <div class="toolbar report-filter-toolbar">
                <label class="field-label"
                    >From<input v-model="from" type="date" class="input"
                /></label>
                <label class="field-label"
                    >To<input v-model="to" type="date" class="input"
                /></label>
                <button class="button button-secondary" :disabled="busy" @click="loadTickets">
                    {{ busy ? 'Loading…' : 'Apply dates' }}
                </button>
            </div>
            <p v-if="error" class="error-box">{{ error }}</p>
            <div class="stat-grid">
                <article v-for="(value, key) in report.summary" :key="key" class="stat-card">
                    <label>{{ label(key) }}</label>
                    <strong>{{ count(value) }}</strong>
                </article>
            </div>
            <div class="chart-grid">
                <section class="panel report-table-panel">
                    <h3>Ticket breakdown</h3>
                    <div class="report-table-scroll">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Dimension</th>
                                    <th>Group</th>
                                    <th>Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="(row, index) in report.breakdown" :key="index">
                                    <td>{{ label(row.dimension) }}</td>
                                    <td>{{ row.label }}</td>
                                    <td>{{ count(row.total) }}</td>
                                </tr>
                                <tr v-if="!report.breakdown.length">
                                    <td colspan="3" class="report-empty">
                                        No ticket breakdown data for this period.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>
                <section v-if="report.isStaff" class="panel report-table-panel">
                    <h3>Agent performance</h3>
                    <div class="report-table-scroll">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Agent</th>
                                    <th>Active</th>
                                    <th>Completed</th>
                                    <th>Overdue</th>
                                    <th>First reply (min)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="agent in report.agents" :key="agent.id">
                                    <td>{{ agent.name }}</td>
                                    <td>{{ count(agent.active) }}</td>
                                    <td>{{ count(agent.completed) }}</td>
                                    <td>{{ count(agent.overdue) }}</td>
                                    <td>{{ agent.first_reply_minutes ?? '—' }}</td>
                                </tr>
                                <tr v-if="!report.agents.length">
                                    <td colspan="5" class="report-empty">
                                        No agent activity for this period.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
            <section class="panel report-table-panel">
                <h3>Daily ticket trend</h3>
                <div class="report-table-scroll">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Date (UTC)</th>
                                <th>Created</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in report.trend" :key="row.date">
                                <td>{{ row.date }}</td>
                                <td>{{ count(row.total) }}</td>
                            </tr>
                            <tr v-if="!report.trend.length">
                                <td colspan="2" class="report-empty">
                                    No daily trend data for this period.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </section>
    </section>

    <section v-if="inventoryVisible" class="report-section inventory-report-section">
        <div class="report-section-heading">
            <div>
                <span class="report-eyebrow">ASSET MANAGEMENT</span>
                <h3>Inventory report</h3>
                <p>
                    Current asset counts, stock levels, lifecycle status, and recorded purchase
                    value.
                </p>
            </div>
            <button
                v-if="inventory.canExportInventory"
                class="button button-primary"
                :disabled="inventoryBusy"
                @click="exportInventory"
            >
                <i class="pi pi-file-excel" /> Export inventory Excel
            </button>
        </div>

        <section class="panel">
            <p v-if="inventoryError" class="error-box">{{ inventoryError }}</p>
            <p v-if="inventory.databaseReady && inventory.generatedAt" class="report-generated">
                Snapshot generated {{ new Date(inventory.generatedAt).toLocaleString() }}
            </p>
            <div v-if="inventoryBusy" class="report-loading">
                <i class="pi pi-spin pi-spinner" /> Loading inventory report…
            </div>
            <template v-else>
                <div class="stat-grid inventory-stat-grid">
                    <article class="stat-card">
                        <label>Asset records</label
                        ><strong>{{ count(inventory.summary.asset_records) }}</strong>
                    </article>
                    <article class="stat-card">
                        <label>Units tracked</label
                        ><strong>{{ count(inventory.summary.tracked_units) }}</strong>
                    </article>
                    <article class="stat-card">
                        <label>Deployed units</label
                        ><strong>{{ count(inventory.summary.deployed_units) }}</strong>
                    </article>
                    <article class="stat-card">
                        <label>Available / returned</label
                        ><strong>{{ count(inventory.summary.available_units) }}</strong>
                    </article>
                    <article class="stat-card">
                        <label>Recorded value</label
                        ><strong>{{ currency(inventory.summary.recorded_value) }}</strong>
                    </article>
                    <article class="stat-card">
                        <label>Damaged assets</label
                        ><strong>{{ count(inventory.summary.damaged_assets) }}</strong>
                    </article>
                    <article class="stat-card">
                        <label>In repair</label
                        ><strong>{{ count(inventory.summary.repair_assets) }}</strong>
                    </article>
                    <article class="stat-card">
                        <label>Out of stock</label
                        ><strong>{{ count(inventory.summary.out_of_stock_assets) }}</strong>
                    </article>
                </div>
                <p v-if="inventory.summary.assets_without_cost" class="report-footnote">
                    {{ count(inventory.summary.assets_without_cost) }} asset record(s) have no unit
                    cost and are excluded from the recorded value.
                </p>
                <div class="chart-grid inventory-breakdown-grid">
                    <section
                        v-for="section in [
                            { key: 'by_status', title: 'By status' },
                            { key: 'by_type', title: 'By asset type' },
                        ]"
                        :key="section.key"
                        class="panel report-table-panel"
                    >
                        <h3>{{ section.title }}</h3>
                        <div class="report-table-scroll">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Group</th>
                                        <th>Records</th>
                                        <th>Units</th>
                                        <th>Value</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="row in inventory[section.key]" :key="row.label">
                                        <td>{{ label(row.label) }}</td>
                                        <td>{{ count(row.asset_records) }}</td>
                                        <td>{{ count(row.units) }}</td>
                                        <td>{{ currency(row.recorded_value) }}</td>
                                    </tr>
                                    <tr v-if="!inventory[section.key]?.length">
                                        <td colspan="4" class="report-empty">
                                            No inventory records found.
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </section>
                </div>
                <section class="panel report-table-panel">
                    <h3>By division</h3>
                    <div class="report-table-scroll">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Division</th>
                                    <th>Records</th>
                                    <th>Units</th>
                                    <th>Recorded value</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="row in inventory.by_division" :key="row.label">
                                    <td>{{ row.label }}</td>
                                    <td>{{ count(row.asset_records) }}</td>
                                    <td>{{ count(row.units) }}</td>
                                    <td>{{ currency(row.recorded_value) }}</td>
                                </tr>
                                <tr v-if="!inventory.by_division.length">
                                    <td colspan="4" class="report-empty">
                                        No inventory records found.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>
            </template>
        </section>
    </section>
</template>

<style scoped>
.report-section {
    margin-top: 26px;
}
.report-section-heading {
    display: flex;
    align-items: flex-end;
    justify-content: space-between;
    gap: 18px;
    margin: 0 0 14px;
}
.report-section-heading h3 {
    margin: 4px 0 0;
    color: #1b2b41;
    font-size: 18px;
}
.report-section-heading p {
    margin: 6px 0 0;
    color: #8290a2;
    font-size: 12px;
}
.report-eyebrow {
    color: #7588a1;
    font-size: 10px;
    font-weight: 800;
    letter-spacing: 0.12em;
}
.report-filter-toolbar {
    align-items: end;
    margin-bottom: 20px;
}
.report-filter-toolbar .field-label {
    min-width: 180px;
}
.report-table-panel {
    min-width: 0;
    margin: 0 0 18px;
}
.report-table-panel h3 {
    margin: 0 0 12px;
    font-size: 14px;
}
.report-table-scroll {
    overflow-x: auto;
}
.report-empty {
    color: #8794a5 !important;
    text-align: center;
    padding: 20px !important;
}
.report-generated,
.report-footnote {
    margin: -4px 0 16px;
    color: #8290a2;
    font-size: 11px;
}
.report-footnote {
    margin: -5px 0 18px;
}
.report-loading {
    padding: 28px;
    text-align: center;
    color: #7588a1;
}
.inventory-stat-grid .stat-card strong {
    font-size: clamp(18px, 2vw, 26px);
}
.inventory-breakdown-grid {
    margin-bottom: 18px;
}
:global(html[data-theme='dark']) .report-section-heading h3 {
    color: #e6edf7;
}
:global(html[data-theme='dark']) .report-section-heading p,
:global(html[data-theme='dark']) .report-eyebrow,
:global(html[data-theme='dark']) .report-empty,
:global(html[data-theme='dark']) .report-generated,
:global(html[data-theme='dark']) .report-footnote,
:global(html[data-theme='dark']) .report-loading {
    color: #9aa9bd !important;
}
@media (max-width: 650px) {
    .report-section-heading {
        align-items: flex-start;
        flex-direction: column;
    }
    .report-filter-toolbar {
        align-items: stretch;
        flex-direction: column;
    }
    .report-filter-toolbar .field-label {
        min-width: 0;
    }
}
</style>
