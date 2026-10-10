<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { storeToRefs } from 'pinia'
import { RouterLink } from 'vue-router'
import { useNotificationStore } from '@/stores/notifications'

const notificationStore = useNotificationStore()
const { notifications, page, total, unread: unreadTotal, loading, loadingMore, markingId: marking, error: errorMessage } = storeToRefs(notificationStore)
const filter = ref('all')

const visibleNotifications = computed(() =>
    filter.value === 'unread'
        ? notifications.value.filter((item) => !item.read_at)
        : notifications.value,
)
const hasMore = computed(() => notificationStore.hasMore)

async function loadNotifications({ append = false } = {}) {
    try {
        await notificationStore.fetchPage({ append })
    } catch { /* Store state contains the error message. */
    }
}

async function markRead(item) {
    try {
        await notificationStore.markRead(item)
    } catch { /* Store state contains the error message. */ }
}

function formatDate(value) {
    const date = new Date(value)
    return Number.isNaN(date.getTime())
        ? value
        : new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(
              date,
          )
}

function refresh() {
    loadNotifications()
}

onMounted(() => {
    loadNotifications()
    window.addEventListener('supportdesk:refresh', refresh)
})
onBeforeUnmount(() => window.removeEventListener('supportdesk:refresh', refresh))
</script>

<template>
    <main class="notifications-page">
        <header class="notifications-heading">
            <div>
                <span class="notifications-eyebrow">INBOX</span>
                <h1>Notifications</h1>
                <p>Updates about your tickets and team activity, all in one place.</p>
            </div>
            <button
                class="button button-secondary notifications-refresh"
                type="button"
                :disabled="loading"
                @click="refresh"
            >
                <span aria-hidden="true">↻</span> Refresh
            </button>
        </header>

        <section class="notifications-panel" aria-label="Notification inbox">
            <div class="notifications-toolbar">
                <div class="notifications-summary">
                    <strong>{{ unreadTotal }}</strong>
                    <span>{{
                        unreadTotal === 1 ? 'unread notification' : 'unread notifications'
                    }}</span>
                    <span class="notifications-summary-divider" aria-hidden="true">·</span>
                    <span>{{ total }} total</span>
                </div>
                <div class="notifications-filters" role="tablist" aria-label="Filter notifications">
                    <button
                        type="button"
                        role="tab"
                        :aria-selected="filter === 'all'"
                        :class="{ active: filter === 'all' }"
                        @click="filter = 'all'"
                    >
                        All <span>{{ total }}</span>
                    </button>
                    <button
                        type="button"
                        role="tab"
                        :aria-selected="filter === 'unread'"
                        :class="{ active: filter === 'unread' }"
                        @click="filter = 'unread'"
                    >
                        Unread <span>{{ unreadTotal }}</span>
                    </button>
                </div>
            </div>

            <div v-if="errorMessage" class="notifications-error" role="alert">
                {{ errorMessage }}
            </div>

            <div
                v-if="loading && !notifications.length"
                class="notifications-loading"
                aria-live="polite"
            >
                <span class="notifications-spinner" aria-hidden="true"></span> Loading your
                notifications…
            </div>

            <div v-else-if="!visibleNotifications.length" class="notifications-empty">
                <span class="notifications-empty-icon" aria-hidden="true">✓</span>
                <h2>{{ filter === 'unread' ? 'You’re all caught up' : 'Nothing to see yet' }}</h2>
                <p>
                    {{
                        filter === 'unread'
                            ? 'There are no unread notifications.'
                            : 'Ticket updates will show up here when they arrive.'
                    }}
                </p>
                <button
                    v-if="filter === 'unread'"
                    class="button button-secondary"
                    type="button"
                    @click="filter = 'all'"
                >
                    View all notifications
                </button>
            </div>

            <div v-else class="notification-list">
                <article
                    v-for="item in visibleNotifications"
                    :key="item.id"
                    class="notification-item"
                    :class="{ unread: !item.read_at }"
                >
                    <span
                        class="notification-item-icon"
                        :class="{ 'is-unread': !item.read_at }"
                        aria-hidden="true"
                    >
                        <svg viewBox="0 0 24 24" fill="none">
                            <path d="M4.75 6.75h14.5v10.5H4.75z" />
                            <path d="m5.5 7.5 6.5 5.25 6.5-5.25" />
                        </svg>
                    </span>
                    <div class="notification-item-content">
                        <div class="notification-item-topline">
                            <span v-if="item.ticket_id" class="notification-ticket-label"
                                >TICKET #{{ item.ticket_id }}</span
                            >
                            <span class="notification-timestamp">{{
                                formatDate(item.created_at)
                            }}</span>
                        </div>
                        <h2>
                            {{
                                item.subject ||
                                (item.ticket_id
                                    ? `Update on ticket #${item.ticket_id}`
                                    : 'Ticket update')
                            }}
                        </h2>
                        <p>{{ item.message }}</p>
                        <div class="notification-item-actions">
                            <RouterLink
                                v-if="item.ticket_id"
                                class="notification-open-link"
                                :to="`/tickets/${item.ticket_id}`"
                                >Open ticket <span aria-hidden="true">→</span></RouterLink
                            >
                            <span v-if="item.read_at" class="notification-read-label"
                                ><span aria-hidden="true">✓</span> Read</span
                            >
                            <button
                                v-else
                                class="notification-mark-read"
                                type="button"
                                :disabled="marking === item.id"
                                @click="markRead(item)"
                            >
                                {{ marking === item.id ? 'Saving…' : 'Mark as read' }}
                            </button>
                        </div>
                    </div>
                    <span
                        v-if="!item.read_at"
                        class="notification-unread-dot"
                        aria-label="Unread"
                    ></span>
                </article>
            </div>

            <div v-if="hasMore && notifications.length" class="notifications-load-more">
                <button
                    class="button button-secondary"
                    type="button"
                    :disabled="loadingMore"
                    @click="loadNotifications({ append: true })"
                >
                    {{ loadingMore ? 'Loading…' : 'Load more' }}
                </button>
                <span>Showing {{ notifications.length }} of {{ total }}</span>
            </div>
        </section>
    </main>
</template>
