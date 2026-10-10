import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const routes = [
    { path: '/auth/login', name: 'login', component: () => import('@/views/auth/LoginView.vue'), meta: { guest: true } },
    { path: '/auth/register', name: 'register', component: () => import('@/views/auth/RegisterView.vue'), meta: { guest: true } },
    { path: '/auth/forgot-password', name: 'forgot-password', component: () => import('@/views/auth/ForgotPasswordView.vue'), meta: { guest: true } },
    { path: '/reset-password/:token', name: 'reset-password', component: () => import('@/views/auth/ResetPasswordView.vue'), meta: { guest: true } },
    { path: '/verify-email', name: 'verify-email', component: () => import('@/views/auth/VerifyEmailView.vue'), meta: { auth: true } },
    {
        path: '/', component: () => import('@/layout/AppLayout.vue'), meta: { auth: true },
        children: [
            { path: '', redirect: '/dashboard' },
            { path: 'dashboard', name: 'dashboard', component: () => import('@/views/DashboardView.vue'), meta: { permission: 'reports.view', title: 'Overview' } },
            { path: 'tickets', name: 'tickets', component: () => import('@/views/TicketsView.vue'), meta: { permission: 'tickets.view', title: 'Tickets' } },
            { path: 'tickets/new', name: 'ticket-create', component: () => import('@/views/TicketCreateView.vue'), meta: { permission: 'tickets.create', title: 'New ticket' } },
            { path: 'tickets/:id', name: 'ticket-detail', component: () => import('@/views/TicketDetailView.vue'), props: true, meta: { permission: 'tickets.view', title: 'Ticket details' } },
            { path: 'inventory', name: 'inventory', component: () => import('@/views/InventoryView.vue'), meta: { permission: 'inventory.view', title: 'Inventory' } },
            { path: 'inventory/new', name: 'asset-create', component: () => import('@/views/AssetCreateView.vue'), meta: { permission: 'inventory.manage', title: 'New asset' } },
            { path: 'inventory/:id', name: 'asset-detail', component: () => import('@/views/AssetDetailView.vue'), props: true, meta: { permission: 'inventory.view', title: 'Asset details' } },
            { path: 'reports', name: 'reports', component: () => import('@/views/ReportsView.vue'), meta: { permission: 'reports.view', title: 'Reports' } },
            { path: 'notifications', name: 'notifications', component: () => import('@/views/NotificationsView.vue'), meta: { permission: 'notifications.view', title: 'Notifications' } },
            { path: 'admin', name: 'admin', component: () => import('@/views/AdminView.vue'), meta: { permission: 'users.view', title: 'Administration' } },
            { path: 'settings/profile', name: 'profile', component: () => import('@/views/ProfileView.vue'), meta: { title: 'Account settings' } },
        ],
    },
    { path: '/:pathMatch(.*)*', name: 'not-found', component: () => import('@/views/NotFoundView.vue') },
]

const router = createRouter({
    history: createWebHistory(import.meta.env.BASE_URL),
    routes,
})

router.beforeEach(async (to) => {
    const authStore = useAuthStore()
    try { await authStore.restoreSession() } catch { /* API errors are shown on the destination page. */ }
    if (to.meta.guest) return authStore.authenticated ? { name: authStore.emailUnverified ? 'verify-email' : 'dashboard' } : true
    if (to.meta.auth && !authStore.authenticated) return { name: 'login', query: { redirect: to.fullPath } }
    if (!to.meta.auth && !to.meta.guest && !authStore.authenticated) return { name: 'login', query: { redirect: to.fullPath } }
    if (authStore.authenticated && authStore.emailUnverified && to.name !== 'verify-email') return { name: 'verify-email' }
    if (to.meta.permission && !authStore.can(to.meta.permission)) return { name: 'forbidden' }
    return true
})

router.addRoute({ path: '/forbidden', name: 'forbidden', component: () => import('@/views/ForbiddenView.vue') })

export default router
