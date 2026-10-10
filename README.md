# Support Desk

A local helpdesk backend using Laravel and **MySQL stored procedures**. The standalone Vue frontend is kept in `frontend-ticketing/` and connects through the same-origin JSON API.

## Included workflows

- Registration, login, email verification, password reset, two-factor authentication, passkeys, and profile/security settings through Laravel Fortify.
- Requester, agent, administrator and superadmin roles; requesters see only their own tickets.
- Tickets with category, priority, assignment, comments, staff-only notes, status history and optimistic edit-conflict detection.
- Secure attachments (PDF, JPG/JPEG, PNG, TXT, maximum 2 MB), private random file paths, authorized downloads, and audit history.
- Controlled new → open → in progress → resolved → closed workflow, pending status, and requester/agent reopening.
- Vue dashboard with live metrics, overdue counts, category/priority/status breakdowns, active workload, period filters and spreadsheet-safe Excel export.
- Admin Vue screen for registered-user roles, categories, auto-assignment rules and the last 100 administrative audit entries. Self-demotion is blocked, and support users must be removed from active assignments/routing rules before demotion.
- 24/7 resolution SLA: urgent 4h, high 8h, medium 24h, low 72h from creation. Pending time counts. Priority changes recalculate from creation; reopening retains the creation baseline.
- In-app alerts for assignment, public replies, staff notes, status changes, reopening and overdue escalation. Private to recipients, with current-role checks for staff-only alerts. Inbox refreshes every 30 seconds while visible.
- Scheduled overdue detection, once per ticket/deadline, notifies assignee and administrators and writes a system audit event. Each run processes up to 500 overdue tickets; later runs continue the backlog.

## Start this local installation

PHP **8.5.10** is installed under the ignored `.runtime` directory. The database connection is configured in the ignored `.env`.

```powershell
.\run-dev.bat
```

The batch file starts Laravel, Vue, Reverb, the SLA scheduler, and the queue worker. Laravel configuration is refreshed before startup. The three network services bind to `0.0.0.0`, and occupied ports are reported instead of switching to another port.

| Service | LAN address |
| --- | --- |
| Vue frontend | http://192.168.100.51:5001 |
| Laravel API | http://192.168.100.51:8001 |
| Reverb WebSocket | ws://192.168.100.51:8081 |

Open the Vue address from a device on the same network. Vue proxies API and authentication requests to Laravel and uses the page hostname for Reverb. Session cookies use the LAN hostname automatically.

To enable inbound Windows Firewall access for these three TCP ports from the local subnet, run `run-dev.bat firewall` once in an Administrator terminal. The rule is named `Ticketing-System-LAN-8001-5001-8081` and is limited to the local subnet.

Individual services can be started with `run-dev.bat backend`, `frontend`, `reverb`, `scheduler`, or `worker`. For hidden background startup, use `scripts/start-local.ps1`; it reads ports and hostnames from `.env`, checks the configured database, runs migrations, and writes logs under `.runtime`.

If `ipconfig` shows a changed IPv4 address, update `APP_URL`, `FRONTEND_URL`, `REVERB_HOST`, and `REVERB_ALLOWED_ORIGINS` in the root `.env`, plus `VITE_API_PROXY_TARGET` in `frontend-ticketing/.env`, then restart the services. Keep `VITE_API_BASE_URL` and `VITE_REVERB_HOST` empty for the automatic same-host browser connections. Runtime binaries and database data remain outside Git.

## First administrator

Register through the Vue frontend using your own email/password once it is connected. No default or shared administrative password is seeded. To authorize the first registered account as admin, run from a trusted project terminal:

```powershell
php artisan tickets:bootstrap-admin your-email@example.com --verify-local
```

Replace the email with the actual registered account. The existing password is unchanged. `--verify-local` is permitted only in `APP_ENV=local`; omit it in production and verify the email normally. This command refuses once any admin exists. Subsequent role changes happen in Administration. A requester can otherwise use the app normally after verification. Local verification/password-reset emails are written to `storage/logs/laravel.log` by the log mail driver.

## Fresh-machine setup

Install PHP 8.5.10 with PDO_MYSQL, PDO_SQLITE (tests), mbstring, fileinfo, OpenSSL and normal Laravel extensions; MySQL 8.0+; and Composer. Copy `.env.example` to `.env` and configure the MySQL host, port, database, username and password. Create an empty database and a user scoped to it. Run:

```text
composer install
php artisan key:generate
php artisan migrate
php artisan serve
```

Migrations install stored procedures in version order. Baseline SQL is under `database/procedures`, SLA revisions under `sla`, and operational revisions under `complete`. Do not manually run every file indiscriminately; use migrations so later versions replace earlier ones. Authentication retains Fortify's Laravel persistence. SQLite supports isolated HTTP tests and setup screens only; operational ticketing requires MySQL.

For scheduled escalation, run `php artisan schedule:work` locally, or configure a production scheduler to run `php artisan schedule:run` every minute. To process once, use `php artisan tickets:process-sla`. No external messages are sent by the SLA job; alerts stay in the application.

## Superadmin

Superadmins have all administrative and support permissions. Only a superadmin
may grant the superadmin role or change another superadmin's role. Users cannot
change their own role or delete an administrator account without demotion.
To promote an existing registered account when no superadmin exists, run
`php artisan tickets:bootstrap-superadmin your-email@example.com`.
This preserves the account password and email verification status.

## JSON API endpoints

Tickets include a [live chat panel](docs/chat.md), with [Laravel Reverb and Vue Echo](docs/reverb.md),
typing indicators, per-user unread tracking and private staff notes.

Superadmin can configure role permissions using the
[permission-management API](docs/permissions.md). API routes and MySQL ticket
procedures enforce these permissions. Existing roles start with their
previous access; no existing user is restricted by the migration.

The same-origin session API is registered in `routes/api.php` under `/api`.
See [API endpoints and request examples](docs/api.md) for authentication,
CSRF headers, request bodies, responses, and error codes. Fortify authentication
and account settings remain on Laravel's session guard; the Vue app owns its
screens and calls these same-origin endpoints.

## Verification commands

```powershell
. .\scripts\use-php.ps1
php artisan test --compact
php scripts/verify-mysql.php
php vendor/bin/phpstan analyse --no-progress --memory-limit=512M
php vendor/bin/pint --dirty --test
```

The MySQL integration script uses the separate `ticketing_test` database and requires credentials with access to it. It migrates that database without migrate:fresh, exercises stored procedures, and cleans up only its fixture records. Never point this script at a production database. It covers access restrictions, attachments, lifecycle, stale writes, notes, metrics, notifications, role changes, auto-assignment, reopening and idempotent escalation.

## Deployment boundaries

This v1 is ready for local use and acceptance testing. Before public deployment, configure HTTPS, `APP_DEBUG=false`, a real mail provider for authentication emails, database/file backups, secure runtime credentials, persistent storage, and a monitored scheduler. Back up both MySQL and `storage/app/ticket-attachments`; restoring only one loses attachment consistency. Business-hour calendars, antivirus scanning, retention policies and external notification delivery are not included in this v1. Excel metrics reflect current ticket status for tickets created in the chosen rolling UTC period, not historical resolution throughput.

## Cloud deployment

See [RENDER_DEPLOYMENT.md](RENDER_DEPLOYMENT.md) for the Docker backend on Render, verified Aiven MySQL TLS, and the separate Vue app on Cloudflare Pages. Production settings are in [.env.render.example](.env.render.example); keep local .env unchanged. Migrations are disabled by default.
