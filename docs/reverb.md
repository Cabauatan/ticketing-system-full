# Laravel Reverb and Vue Echo

The backend uses `laravel/reverb`; Vue uses `laravel-echo` and `pusher-js`.
The public application key is built into Vite assets. The secret stays in
the server `.env` and must never use a `VITE_` prefix.

## Local startup

Run `run-dev.bat`. It starts Laravel on port `8001`, Vue on `5001`, Reverb on
`8081`, the SLA scheduler, and the queue worker. Laravel, Vue, and Reverb bind
to `0.0.0.0` so devices on the LAN can connect. The Vue address is
`http://192.168.100.51:5001`; its page hostname is also the browser's WebSocket host.
For hidden background startup, `scripts/start-local.ps1` writes Reverb logs to
`.runtime/reverb.log` and `.runtime/reverb-error.log`. The local `.env` has generated Reverb credentials.
When changing any frontend `VITE_REVERB_*` setting, rebuild the Vue assets.

For a fresh machine, install Composer/NPM dependencies, generate independent
random `REVERB_APP_KEY` and `REVERB_APP_SECRET` values, and configure:

```dotenv
BROADCAST_CONNECTION=reverb
REVERB_APP_ID=ticketing
REVERB_APP_KEY=replace-with-random-key
REVERB_APP_SECRET=replace-with-random-secret
APP_URL=http://192.168.100.51:8001
FRONTEND_URL=http://192.168.100.51:5001
REVERB_SERVER_HOST=0.0.0.0
REVERB_SERVER_PORT=8081
REVERB_HOST=192.168.100.51
REVERB_PORT=8081
REVERB_SCHEME=http
REVERB_ALLOWED_ORIGINS=192.168.100.51,localhost,127.0.0.1
VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"
VITE_REVERB_HOST=""
VITE_REVERB_PORT="${REVERB_PORT}"
VITE_REVERB_SCHEME="${REVERB_SCHEME}"
```

Run `php artisan config:clear`, build assets, then run
`php artisan reverb:start --host=0.0.0.0 --port=8081`.
Restart the long-running Reverb process after configuration/dependency changes.

Allowed origins are hostname/IP values without a scheme or port. For Windows
Firewall access from the local subnet, run `run-dev.bat firewall` in an
Administrator terminal once.

## Channels and delivery

- `private-tickets.{id}`: authorized requester or support staff with ticket-view
  permission; public message and ticket change signals.
- `private-tickets.{id}.staff`: authorized staff only; internal-note and internal
  typing signals.
- `private-users.{id}`: only that verified user with notification-view permission;
  refreshes the notification bell for assignments/replies/status/read changes
  and SLA escalations.

`POST /broadcasting/auth` uses verified Laravel session authentication.
The Vue Pusher client attaches the XSRF cookie header. The browser cannot
broadcast client events: typing is validated and persisted by the HTTP API
before the server publishes a signal.

Events contain no message bodies, names, or private-note contents. Vue re-fetches
through the existing API, which checks current role, permissions and ownership.
Revoked subscriptions may remain connected until reconnect, but cannot retrieve
new protected content. Server-side writes through `TicketStore` publish only
after the stored procedure has returned successfully. Direct SQL maintenance
calls do not publish application events.

Broadcasts are synchronous (`ShouldBroadcastNow`), so this setup does not need
a broadcast queue worker. A transport failure is logged without turning a
committed message into a failed write; reconnect/polling recovers the data.
Clients re-fetch after re-subscription and when a background tab becomes visible.

## Deployment

Use a process supervisor and a TLS reverse proxy with WebSocket upgrade support.
Set the browser host/scheme/port to the public `wss` endpoint and allowed origins
to your actual hostnames. Keep secrets server-side and use different credentials
for testing and production. The local loopback configuration is not a public
deployment. See the official [Reverb documentation](https://laravel.com/docs/13.x/reverb).

## Workspace live updates

Tickets, ticket details, dashboard, reports, administration and the notification page now refresh through Reverb. Staff subscribe to private workspace.staff; requesters subscribe to their own workspace.user.ID. Public ticket writes invalidate the staff workspace and ticket owner's workspace. Internal notes and typing do not broadcast workspace events. Admin changes and committed user profile/registration changes refresh staff pages. All payloads are empty and subsequent HTTP reads enforce current permissions.

Page refreshes preserve filters and pause while editing ticket, category, or user-role forms. A pending indicator explains deferred updates. Shared subscriptions are reference counted, so leaving the notifications page does not disconnect the bell. Reconnection triggers reconciliation; periodic refresh covers missed events, time-based SLA changes and direct SQL maintenance.
