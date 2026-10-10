<script setup>
import { computed, nextTick, onMounted, onBeforeUnmount, ref } from 'vue'
import { storeToRefs } from 'pinia'
import { RouterLink } from 'vue-router'
import { Chart, registerables } from 'chart.js'
import { useAuthStore } from '@/stores/auth'
import { useDashboardStore } from '@/stores/dashboard'

Chart.register(...registerables)
const auth = useAuthStore()
const dashboardStore = useDashboardStore()
const { summary, inventory } = storeToRefs(dashboardStore)
const days = ref(30)
const loading = ref(false)
const error = ref('')
const statusCanvas = ref(null)
const priorityCanvas = ref(null)
const categoryCanvas = ref(null)
const assetStatusCanvas = ref(null)
const assetTypeCanvas = ref(null)
const chartError = ref('')
const inventoryLoading = ref(false)
const inventoryError = ref('')
const canViewInventory = computed(() => auth.can('inventory.view'))
const charts = []
let themeObserver

const chartColors = {
    blue: ['#3977f6', '#6b9bff', '#9ab9ff', '#c4d6ff', '#244eae', '#7c6cf2', '#38a3a5'],
    status: {
        new: '#8193ad',
        open: '#3977f6',
        in_progress: '#8a73ec',
        pending: '#e6a23c',
        resolved: '#20a779',
        closed: '#43b7aa',
    },
    priority: { low: '#4bbd91', medium: '#e6b34f', high: '#f08a43', urgent: '#e85c63' },
}

const titleCase = (value) =>
    String(value || '')
        .replaceAll('_', ' ')
        .replace(/\b\w/g, (letter) => letter.toUpperCase())
const rowsFor = (dimension) =>
    summary.value.filter((row) => row.dimension === dimension && Number(row.total) > 0)
function chartTheme() {
    const dark = document.documentElement.dataset.theme === 'dark'
    return { text: dark ? '#a9b7ca' : '#68788e', grid: dark ? '#29364a' : '#e8edf4' }
}
function baseOptions() {
    const colors = chartTheme()
    return {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                labels: {
                    color: colors.text,
                    usePointStyle: true,
                    boxWidth: 8,
                    padding: 16,
                    font: { size: 11 },
                },
            },
            tooltip: { padding: 10, displayColors: true },
        },
    }
}
function currency(value) {
    return new Intl.NumberFormat('en-PH', {
        style: 'currency',
        currency: 'PHP',
        maximumFractionDigits: 0,
    }).format(Number(value || 0))
}
function number(value) {
    return Number(value || 0).toLocaleString('en-PH')
}
function destroyCharts() {
    charts.splice(0).forEach((chart) => chart.destroy())
}
function renderCharts() {
    destroyCharts()
    chartError.value = ''
    if (!summary.value.length) return
    try {
        const statusRows = rowsFor('status')
        const priorityRows = rowsFor('priority')
        const categoryRows = rowsFor('category')

        if (statusCanvas.value && statusRows.length) {
            const options = baseOptions()
            options.cutout = '68%'
            charts.push(
                new Chart(statusCanvas.value, {
                    type: 'doughnut',
                    data: {
                        labels: statusRows.map((row) => titleCase(row.label)),
                        datasets: [
                            {
                                data: statusRows.map((row) => Number(row.total)),
                                backgroundColor: statusRows.map(
                                    (row) =>
                                        chartColors.status[row.label] ||
                                        chartColors.blue[
                                            statusRows.indexOf(row) % chartColors.blue.length
                                        ],
                                ),
                                borderWidth: 0,
                                hoverOffset: 5,
                            },
                        ],
                    },
                    options,
                }),
            )
        }
        for (const [canvas, rows, palette] of [
            [priorityCanvas.value, priorityRows, chartColors.priority],
            [categoryCanvas.value, categoryRows, null],
        ]) {
            if (!canvas || !rows.length) continue
            const colors = chartTheme()
            charts.push(
                new Chart(canvas, {
                    type: 'bar',
                    data: {
                        labels: rows.map((row) => titleCase(row.label)),
                        datasets: [
                            {
                                label: 'Tickets',
                                data: rows.map((row) => Number(row.total)),
                                backgroundColor: rows.map(
                                    (row, index) =>
                                        palette?.[row.label] ||
                                        chartColors.blue[index % chartColors.blue.length],
                                ),
                                borderRadius: 6,
                                maxBarThickness: 30,
                            },
                        ],
                    },
                    options: {
                        ...baseOptions(),
                        indexAxis: 'y',
                        plugins: { ...baseOptions().plugins, legend: { display: false } },
                        scales: {
                            x: {
                                beginAtZero: true,
                                ticks: { precision: 0, color: colors.text },
                                grid: { color: colors.grid, drawBorder: false },
                            },
                            y: {
                                ticks: { color: colors.text },
                                grid: { display: false, drawBorder: false },
                            },
                        },
                    },
                }),
            )
        }
        for (const [canvas, rows, title] of [
            [assetStatusCanvas.value, inventory.value?.by_status || [], 'Units'],
            [assetTypeCanvas.value, inventory.value?.by_type || [], 'Units'],
        ]) {
            if (!canvas || !rows.length) continue
            const colors = chartTheme()
            charts.push(
                new Chart(canvas, {
                    type: 'bar',
                    data: {
                        labels: rows.map((row) => titleCase(row.label)),
                        datasets: [
                            {
                                label: title,
                                data: rows.map((row) => Number(row.units)),
                                backgroundColor: rows.map(
                                    (row, index) =>
                                        chartColors.blue[index % chartColors.blue.length],
                                ),
                                borderRadius: 6,
                                maxBarThickness: 30,
                            },
                        ],
                    },
                    options: {
                        ...baseOptions(),
                        indexAxis: 'y',
                        plugins: { ...baseOptions().plugins, legend: { display: false } },
                        scales: {
                            x: {
                                beginAtZero: true,
                                ticks: { precision: 0, color: colors.text },
                                grid: { color: colors.grid, drawBorder: false },
                            },
                            y: {
                                ticks: { color: colors.text },
                                grid: { display: false, drawBorder: false },
                            },
                        },
                    },
                }),
            )
        }
    } catch (failure) {
        destroyCharts()
        chartError.value = 'Charts could not be displayed. Refresh the page to try again.'
        console.error('Dashboard chart rendering failed', failure)
    }
}

async function loadInventory() {
    if (!canViewInventory.value) return
    inventoryLoading.value = true
    inventoryError.value = ''
    try {
        await dashboardStore.fetchInventory()
        await nextTick()
        renderCharts()
    } catch (failure) {
        inventoryError.value =
            failure.response?.data?.message || 'Could not load the inventory overview.'
    } finally {
        inventoryLoading.value = false
    }
}

async function load() {
    loading.value = true
    try {
        await dashboardStore.fetchSummary(days.value)
        error.value = ''
        await nextTick()
        renderCharts()
    } catch (failure) {
        error.value = failure.response?.data?.message || 'Hindi makuha ang dashboard.'
    } finally {
        loading.value = false
    }
}
const refresh = () => load()
const count = (dimension, label) =>
    Number(
        summary.value.find((row) => row.dimension === dimension && row.label === label)?.total || 0,
    )
const total = () =>
    summary.value
        .filter((row) => row.dimension === 'status')
        .reduce((sum, row) => sum + Number(row.total || 0), 0)
onMounted(() => {
    load()
    loadInventory()
    window.addEventListener('supportdesk:refresh', refresh)
    themeObserver = new MutationObserver(() => renderCharts())
    themeObserver.observe(document.documentElement, {
        attributes: true,
        attributeFilter: ['data-theme'],
    })
})
onBeforeUnmount(() => {
    window.removeEventListener('supportdesk:refresh', refresh)
    themeObserver?.disconnect()
    destroyCharts()
})
</script>

<template>
    <div class="page-heading">
        <div>
            <h2>Welcome back, {{ auth.user?.name || 'to your workspace' }}</h2>
            <p>Here’s the latest activity from your support desk.</p>
        </div>
        <select v-model="days" class="select" style="width: 160px" @change="load">
            <option :value="7">Last 7 days</option>
            <option :value="30">Last 30 days</option>
            <option :value="90">Last 90 days</option>
            <option :value="0">All time</option>
        </select>
    </div>
    <p v-if="error" class="error-box">{{ error }}</p>
    <div class="stat-grid">
        <article class="stat-card">
            <label>All tickets</label><strong>{{ total() }}</strong
            ><small>Tickets in selected period</small>
        </article>
        <article class="stat-card">
            <label>Open</label
            ><strong>{{
                count('status', 'open') +
                count('status', 'new') +
                count('status', 'in_progress') +
                count('status', 'pending')
            }}</strong
            ><small>Needs attention</small>
        </article>
        <article class="stat-card">
            <label>Resolved</label
            ><strong>{{ count('status', 'resolved') + count('status', 'closed') }}</strong
            ><small>Completed tickets</small>
        </article>
        <article class="stat-card">
            <label>High priority</label
            ><strong>{{ count('priority', 'high') + count('priority', 'urgent') }}</strong
            ><small>High and urgent</small>
        </article>
    </div>
    <section class="overview-charts">
        <div class="overview-chart-heading">
            <div>
                <h2>Ticket analytics</h2>
                <p>
                    {{
                        loading
                            ? 'Updating charts…'
                            : 'Explore ticket distribution for the selected period.'
                    }}
                </p>
            </div>
            <RouterLink class="button button-primary" to="/tickets"
                ><i class="pi pi-arrow-right" /> View tickets</RouterLink
            >
        </div>
        <p v-if="chartError" class="error-box">{{ chartError }}</p>
        <div v-if="summary.length" class="overview-chart-grid">
            <section class="panel overview-chart-card">
                <div class="overview-chart-title">
                    <div>
                        <h3>By status</h3>
                        <p>Where tickets are in the workflow</p>
                    </div>
                    <span class="overview-chart-total">{{ total() }}</span>
                </div>
                <div
                    v-if="rowsFor('status').length"
                    class="overview-chart-canvas overview-doughnut"
                >
                    <canvas
                        ref="statusCanvas"
                        aria-label="Ticket count by status"
                        role="img"
                    ></canvas>
                    <div class="doughnut-center">
                        <strong>{{ total() }}</strong
                        ><span>tickets</span>
                    </div>
                </div>
                <div v-else class="overview-chart-empty">No status data for this period.</div>
            </section>
            <section class="panel overview-chart-card">
                <div class="overview-chart-title">
                    <div>
                        <h3>By priority</h3>
                        <p>Tickets by urgency</p>
                    </div>
                </div>
                <div v-if="rowsFor('priority').length" class="overview-chart-canvas">
                    <canvas
                        ref="priorityCanvas"
                        aria-label="Ticket count by priority"
                        role="img"
                    ></canvas>
                </div>
                <div v-else class="overview-chart-empty">No priority data for this period.</div>
            </section>
            <section class="panel overview-chart-card">
                <div class="overview-chart-title">
                    <div>
                        <h3>By category</h3>
                        <p>Most common request types</p>
                    </div>
                </div>
                <div v-if="rowsFor('category').length" class="overview-chart-canvas">
                    <canvas
                        ref="categoryCanvas"
                        aria-label="Ticket count by category"
                        role="img"
                    ></canvas>
                </div>
                <div v-else class="overview-chart-empty">No category data for this period.</div>
            </section>
        </div>
        <div v-else-if="!loading" class="panel overview-chart-empty">
            Wala pang ticket data para sa napiling panahon.
        </div>
    </section>
    <section v-if="canViewInventory" class="overview-inventory">
        <div class="overview-chart-heading">
            <div>
                <h2>Inventory overview</h2>
                <p>
                    {{
                        inventoryLoading
                            ? 'Updating inventory…'
                            : 'Asset quantities and lifecycle at a glance.'
                    }}
                </p>
            </div>
            <RouterLink class="button button-secondary" to="/inventory"
                ><i class="pi pi-desktop" /> View inventory</RouterLink
            >
        </div>
        <p v-if="inventoryError" class="error-box">{{ inventoryError }}</p>
        <template v-if="inventory">
            <div class="stat-grid overview-inventory-stats">
                <article class="stat-card">
                    <label>Asset records</label
                    ><strong>{{ number(inventory.summary.asset_records) }}</strong
                    ><small>Tracked inventory items</small>
                </article>
                <article class="stat-card">
                    <label>Tracked units</label
                    ><strong>{{ number(inventory.summary.tracked_units) }}</strong
                    ><small>Total recorded quantity</small>
                </article>
                <article class="stat-card">
                    <label>Deployed units</label
                    ><strong>{{ number(inventory.summary.deployed_units) }}</strong
                    ><small>Currently assigned or deployed</small>
                </article>
                <article class="stat-card">
                    <label>Recorded value</label
                    ><strong>{{ currency(inventory.summary.recorded_value) }}</strong
                    ><small>Based on recorded unit costs</small>
                </article>
            </div>
            <div class="overview-chart-grid overview-inventory-grid">
                <section class="panel overview-chart-card">
                    <div class="overview-chart-title">
                        <div>
                            <h3>Units by status</h3>
                            <p>Availability and lifecycle state</p>
                        </div>
                    </div>
                    <div v-if="inventory.by_status?.length" class="overview-chart-canvas">
                        <canvas
                            ref="assetStatusCanvas"
                            aria-label="Inventory units by status"
                            role="img"
                        ></canvas>
                    </div>
                    <div v-else class="overview-chart-empty">No inventory status data yet.</div>
                </section>
                <section class="panel overview-chart-card">
                    <div class="overview-chart-title">
                        <div>
                            <h3>Units by asset type</h3>
                            <p>Equipment and consumables</p>
                        </div>
                    </div>
                    <div v-if="inventory.by_type?.length" class="overview-chart-canvas">
                        <canvas
                            ref="assetTypeCanvas"
                            aria-label="Inventory units by asset type"
                            role="img"
                        ></canvas>
                    </div>
                    <div v-else class="overview-chart-empty">No inventory type data yet.</div>
                </section>
            </div>
        </template>
        <div v-else-if="inventoryLoading" class="panel overview-chart-empty">
            <i class="pi pi-spin pi-spinner" /> Loading inventory summary…
        </div>
    </section>
    <section class="panel overview-breakdown">
        <div class="page-heading">
            <div>
                <h2>Ticket breakdown</h2>
                <p>Ticket totals grouped by status, priority, and category.</p>
            </div>
        </div>
        <div v-if="summary.length" class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Group</th>
                        <th>Category</th>
                        <th>Tickets</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(row, i) in summary" :key="i">
                        <td>{{ titleCase(row.dimension) }}</td>
                        <td>{{ titleCase(row.label) }}</td>
                        <td>{{ row.total }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div v-else class="empty-state">Wala pang ticket data para sa napiling panahon.</div>
    </section>
</template>
