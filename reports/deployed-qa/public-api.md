# Live public deployment audit — 11 October 2026

Recorded: 11 October 2026, 14:05 Singapore time (2026-10-11T06:05:01.100Z UTC).

Live read-only public HTTP and unauthenticated access controls. No email sent, authenticated writes, database changes, or load testing.

## Findings

**BLOCKER — Session/API requests currently fail with HTTP 500.** The root endpoint, CSRF bootstrap, and all 23 protected API GETs below returned the generic production response `{"message":"Server Error"}`. This prevents login and blocks ticket, inventory, report, administration, profile, notification, and export acceptance tests. These are observations of a shared backend failure; they do not prove that each module has an independent bug. The server exception is required to identify the cause.

**Health coverage gap.** `/up` returned 200 while CSRF bootstrap and normal requests failed. Render can mark this instance healthy even when users cannot authenticate. Source inspection confirms the default Laravel health route has no custom DB/session readiness check.

**CORS passed.** Production and the known preview origin received their exact origin and credentials support. The untrusted origin received no allow-origin header. The frontend HTML and current entry bundle loaded successfully.

**Cookie verification blocked.** The failed CSRF response issued no cookies and no token, so live session cookie security attributes could not be verified.

## Read-only source diagnosis

The production template uses `SESSION_DRIVER=database` and `CACHE_STORE=database`, with `RUN_MIGRATIONS=false`. The affected routes use Laravel's web session middleware; the health endpoint does not exercise it. A missing sessions table, database connectivity/TLS/authentication failure, or an invalid application encryption key could produce this pattern. None is confirmed without the latest server-side exception. Startup validates that `APP_KEY` is nonempty, stages the CA for the application account when necessary, then caches environment configuration. No definite cause was found in source and no environment settings were changed.

| Check | Result | Evidence |
| --- | --- | --- |
| Backend root | HTTP 500 | Observed live |
| Backend health | HTTP 200 | Observed live |
| Frontend HTML | HTTP 200 | index-CVLvJGxz.js |
| CSRF bootstrap | HTTP 500 | Observed live |
| CORS: production | HTTP 204 | Passed |
| CORS: preview | HTTP 204 | Passed |
| CORS: untrusted origin | HTTP 204 | Passed |
| Protected: /api/me | HTTP 500 | Requires investigation |
| Protected: /api/user | HTTP 500 | Requires investigation |
| Protected: /api/dashboard | HTTP 500 | Requires investigation |
| Protected: /api/permissions | HTTP 500 | Requires investigation |
| Protected: /api/tickets | HTTP 500 | Requires investigation |
| Protected: /api/tickets/1 | HTTP 500 | Requires investigation |
| Protected: /api/tickets/1/chat | HTTP 500 | Requires investigation |
| Protected: /api/tickets/1/assets | HTTP 500 | Requires investigation |
| Protected: /api/inventory | HTTP 500 | Requires investigation |
| Protected: /api/inventory/1 | HTTP 500 | Requires investigation |
| Protected: /api/inventory/1/attachments/1 | HTTP 500 | Requires investigation |
| Protected: /api/reports | HTTP 500 | Requires investigation |
| Protected: /api/reports/export.xlsx | HTTP 500 | Requires investigation |
| Protected: /api/reports/inventory | HTTP 500 | Requires investigation |
| Protected: /api/reports/inventory.xlsx | HTTP 500 | Requires investigation |
| Protected: /api/reports/tickets.xlsx | HTTP 500 | Requires investigation |
| Protected: /api/inventory/export.xlsx | HTTP 500 | Requires investigation |
| Protected: /api/admin | HTTP 500 | Requires investigation |
| Protected: /api/admin/permissions | HTTP 500 | Requires investigation |
| Protected: /api/settings/profile | HTTP 500 | Requires investigation |
| Protected: /api/settings/profile/avatar | HTTP 500 | Requires investigation |
| Protected: /api/settings/security | HTTP 500 | Requires investigation |
| Protected: /api/notifications | HTTP 500 | Requires investigation |
| Frontend entry bundle | HTTP 200 | Observed live |

## Scope limits

- Authenticated CRUD, role-specific access, real-time private subscriptions, email inbox delivery, and Excel content require separate authenticated checks.

Cookie values and CSRF tokens were kept in memory only. Request spacing was at least 3.5 seconds.

## Live Reverb handshake

Deployed real-time chunk: `echo-CT95tPY7.js`, HTTP 200.
Inline Reverb configuration present: false. Public application key present: false (value omitted).
WebSocket host: not extracted. Port: not extracted.
Handshake: BLOCKED — no active Reverb client configuration is present in the deployed bundle.

A successful public handshake does not test authenticated private channel authorization or published events.

LIVE DEFECT: the deployed connectRealtime export compiles to function r(){return n} and never constructs an Echo client. The source guard returns early when VITE_REVERB_APP_KEY is absent. Check the Cloudflare build environment and rebuild with the public Reverb application key, canonical backend host, HTTPS scheme, and port 443. This is separate from the current backend HTTP 500 failure.
