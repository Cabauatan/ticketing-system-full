<script setup>
import { computed, onMounted, onBeforeUnmount, ref, watch } from 'vue'
import { storeToRefs } from 'pinia'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useNotificationStore } from '@/stores/notifications'
import { connectRealtime, disconnectRealtime } from '@/realtime/echo'
import { roleLabel } from '@/utils/roleLabel'

const auth = useAuthStore()
const notificationStore = useNotificationStore()
const { unread: unreadNotifications, recent: recentNotifications, previewLoading: notificationPreviewLoading, markingId: markingNotificationId } = storeToRefs(notificationStore)
const route = useRoute()
const router = useRouter()
const mobileNavOpen = ref(false)
const notificationPopoverOpen = ref(false)
const notificationActionError = ref('')
const theme = ref(document.documentElement.dataset.theme || 'light')
let notificationRefreshTimer
const title = computed(() => route.meta.title || 'Workspace')
const links = [
    { to: '/dashboard', label: 'Overview', icon: 'pi pi-home', permission: 'reports.view' },
    { to: '/tickets', label: 'Tickets', icon: 'pi pi-inbox', permission: 'tickets.view' },
    { to: '/inventory', label: 'Inventory', icon: 'pi pi-desktop', permission: 'inventory.view' },
    { to: '/reports', label: 'Reports', icon: 'pi pi-chart-bar', permission: 'reports.view' },
    { to: '/notifications', label: 'Notifications', icon: 'pi pi-bell', permission: 'notifications.view' },
    { to: '/admin', label: 'Administration', icon: 'pi pi-shield', permission: 'users.view' },
]
async function signOut() { await auth.signOut(); disconnectRealtime(); router.replace('/auth/login') }
function closeMobileNav() { mobileNavOpen.value = false }
function toggleTheme() {
    theme.value = theme.value === 'dark' ? 'light' : 'dark'
    document.documentElement.dataset.theme = theme.value
    localStorage.setItem('supportdesk-theme', theme.value)
}
async function loadUnreadNotifications() {
    if (!auth.can('notifications.view')) {
        notificationStore.unread = 0
        return
    }
    try { await notificationStore.fetchUnread() } catch {
        // The bell stays usable if the notification endpoint is temporarily unavailable.
    }
}
async function openNotifications() {
    notificationPopoverOpen.value = !notificationPopoverOpen.value
    if (!notificationPopoverOpen.value) return
    try {
        await notificationStore.fetchPreview()
    } catch {
        notificationStore.recent = []
    }
}
function closeNotificationsOnOutsideClick(event) {
    if (!event.target.closest('.notification-menu')) notificationPopoverOpen.value = false
}
function closeNotificationsOnEscape(event) {
    if (event.key === 'Escape') notificationPopoverOpen.value = false
}
async function markNotificationRead(item) {
    notificationActionError.value = ''
    try {
        await notificationStore.markRead(item)
    } catch {
        notificationActionError.value = 'Hindi namarkahang read. Subukan ulit.'
    }
}
watch(() => route.fullPath, () => { closeMobileNav(); notificationPopoverOpen.value = false })
onMounted(() => {
    connectRealtime()
    loadUnreadNotifications()
    window.addEventListener('supportdesk:refresh', loadUnreadNotifications)
    document.addEventListener('click', closeNotificationsOnOutsideClick)
    document.addEventListener('keydown', closeNotificationsOnEscape)
    notificationRefreshTimer = window.setInterval(loadUnreadNotifications, 60000)
})
onBeforeUnmount(() => {
    window.removeEventListener('supportdesk:refresh', loadUnreadNotifications)
    document.removeEventListener('click', closeNotificationsOnOutsideClick)
    document.removeEventListener('keydown', closeNotificationsOnEscape)
    window.clearInterval(notificationRefreshTimer)
    disconnectRealtime()
})
</script>

<template>
  <div class="app-shell">
    <button v-if="mobileNavOpen" class="mobile-nav-backdrop" aria-label="Close navigation menu" @click="closeMobileNav" />
    <aside class="sidebar" :class="{ 'mobile-open': mobileNavOpen }">
      <RouterLink class="brand" to="/dashboard"><span class="brand-mark"><img src="/SystemIcon.png" alt=""></span><span><b>Support Desk</b><small>IT Service Workspace</small></span></RouterLink>
      <p class="nav-caption">WORKSPACE</p>
      <nav class="nav-list"><RouterLink v-for="link in links.filter(item => auth.can(item.permission))" :key="link.to" :to="link.to" class="nav-link" @click="closeMobileNav"><i :class="link.icon"/><span>{{ link.label }}</span></RouterLink></nav>
      <div class="sidebar-bottom"><RouterLink to="/settings/profile" class="nav-link" @click="closeMobileNav"><i class="pi pi-user"/><span>My profile</span></RouterLink><button class="nav-link signout" @click="signOut"><i class="pi pi-sign-out"/><span>Sign out</span></button><div class="sidebar-user"><span class="avatar"><img v-if="auth.user?.avatar_url" :src="auth.user.avatar_url" alt=""><template v-else>{{ auth.user?.name?.slice(0,1)?.toUpperCase() }}</template></span><span><b>{{ auth.user?.name }}</b><small>{{ auth.roles.map(roleLabel).join(' · ') }}</small></span></div></div>
    </aside>
    <main class="workspace"><header class="topbar"><div class="topbar-title"><button class="mobile-menu-button" aria-label="Open navigation menu" :aria-expanded="mobileNavOpen" @click="mobileNavOpen = !mobileNavOpen"><i class="pi pi-bars"/></button><div><span class="breadcrumb">Support Desk /</span><h1>{{ title }}</h1></div></div><div class="top-actions"><span class="live-indicator"><i/> Service online</span><button class="theme-toggle" type="button" :aria-label="theme === 'dark' ? 'Switch to light mode' : 'Switch to dark mode'" :title="theme === 'dark' ? 'Switch to light mode' : 'Switch to dark mode'" @click="toggleTheme"><i :class="theme === 'dark' ? 'pi pi-sun' : 'pi pi-moon'"/><span>{{ theme === 'dark' ? 'Light' : 'Dark' }}</span></button><div v-if="auth.can('notifications.view')" class="notification-menu"><button class="icon-link notification-link" type="button" :aria-label="unreadNotifications ? `Notifications, ${unreadNotifications} unread` : 'Notifications'" :aria-expanded="notificationPopoverOpen" aria-haspopup="dialog" @click.stop="openNotifications"><i class="pi pi-bell"/><span v-if="unreadNotifications" class="notification-count">{{ unreadNotifications > 99 ? '99+' : unreadNotifications }}</span></button><section v-if="notificationPopoverOpen" class="notification-popover" role="dialog" aria-label="Recent notifications"><header class="notification-popover-header"><span><b>Notifications</b><small>{{ unreadNotifications ? `${unreadNotifications} unread` : 'You’re all caught up' }}</small></span><RouterLink to="/notifications" class="notification-view-all">View all</RouterLink></header><div v-if="notificationActionError" class="notification-popover-error" role="alert">{{ notificationActionError }}</div><div v-if="notificationPreviewLoading" class="notification-popover-state">Loading notifications…</div><div v-else-if="!recentNotifications.length" class="notification-popover-empty"><span class="notification-empty-mark"><i class="pi pi-bell-slash"/></span><b>No notifications yet</b><p>When there’s an update on your tickets, it will appear here.</p></div><div v-else class="notification-popover-list"><article v-for="item in recentNotifications" :key="item.id" class="notification-popover-item" :class="{ unread: !item.read_at }"><span class="notification-popover-dot" :class="{ visible: !item.read_at }"/><div><RouterLink :to="item.ticket_id ? `/tickets/${item.ticket_id}` : '/notifications'" class="notification-popover-title">{{ item.subject || `Ticket #${item.ticket_id}` }}</RouterLink><p>{{ item.message }}</p><button v-if="!item.read_at" type="button" class="notification-popover-read" :disabled="markingNotificationId === item.id" @click="markNotificationRead(item)">{{ markingNotificationId === item.id ? 'Saving…' : 'Mark as read' }}</button></div></article></div><footer v-if="recentNotifications.length" class="notification-popover-footer"><RouterLink to="/notifications">Open notification center <span aria-hidden="true">→</span></RouterLink></footer></section></div></div></header><section class="page-content"><RouterView /></section></main>
  </div>
</template>
