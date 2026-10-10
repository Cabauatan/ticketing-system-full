import Echo from 'laravel-echo'
import Pusher from 'pusher-js'
import { useAuthStore } from '@/stores/auth'
import http from '@/api/http'

let echo
let refreshTimer

function scheduleWorkspaceRefresh() {
    if (refreshTimer) return

    // A single write can signal several private channels. Collapse that burst
    // into one page refresh so every subscribed view does not repeat its GETs.
    refreshTimer = window.setTimeout(() => {
        refreshTimer = undefined
        window.dispatchEvent(new CustomEvent('supportdesk:refresh'))
    }, 250)
}

export function connectRealtime() {
    if (echo || !import.meta.env.VITE_REVERB_APP_KEY) return echo
    window.Pusher = Pusher
    const scheme = import.meta.env.VITE_REVERB_SCHEME || 'http'
    const secure = scheme === 'https'
    echo = new Echo({
        broadcaster: 'reverb',
        key: import.meta.env.VITE_REVERB_APP_KEY,
        wsHost: import.meta.env.VITE_REVERB_HOST || window.location.hostname,
        wsPort: Number(import.meta.env.VITE_REVERB_PORT || 8081),
        wssPort: Number(import.meta.env.VITE_REVERB_PORT || 8081),
        forceTLS: secure,
        enabledTransports: secure ? ['wss'] : ['ws'],
        // Use the API client's session and CSRF token even on a separate frontend domain.
        channelAuthorization: {
            customHandler({ socketId, channelName }, callback) {
                http.post('/broadcasting/auth', {
                    socket_id: socketId,
                    channel_name: channelName,
                }).then(({ data }) => callback(null, data))
                    .catch((error) => callback(error, null))
            },
        },
        withCredentials: true,
    })
    const auth = useAuthStore()
    const refresh = scheduleWorkspaceRefresh
    echo.private(`workspace.user.${auth.user.id}`)
        .listen('.workspace.changed', refresh)
        .listen('.notifications.changed', refresh)
        .listen('.account.changed', async () => {
            auth.ready = false
            try { await auth.restoreSession() } finally { refresh() }
        })
    if (auth.isStaff) echo.private('workspace.staff').listen('.workspace.changed', refresh).listen('.notifications.changed', refresh)
    if (auth.can('inventory.view')) echo.private('inventory').listen('.inventory.changed', refresh)
    if (auth.can('notifications.view')) echo.private(`users.${auth.user.id}`).listen('.notifications.changed', refresh)
    return echo
}
export function ticketChannel(id) { return echo?.private(`tickets.${id}`) }
export function ticketStaffChannel(id) { return echo?.private(`tickets.${id}.staff`) }
export function disconnectRealtime() {
    if (refreshTimer) window.clearTimeout(refreshTimer)
    refreshTimer = undefined
    echo?.disconnect()
    echo = undefined
}
