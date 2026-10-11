# Deployment functional audit — 11 October 2026

Frontend: https://ticketing-system-full.pages.dev. Backend: https://ticketing-system-full.onrender.com.

The live audit is **incomplete because the backend currently returns HTTP 500 before authentication**. Loading a Cloudflare page or receiving HTTP 200 from `/up` does not establish that business functions work. No production accounts, tickets, inventory, categories, permissions, notifications or attachments were changed during this audit.

## Observed on the deployed system

| Area | Result | Evidence |
| --- | --- | --- |
| Frontend files | PASS | 35 JS/CSS/font/icon resources returned HTTP 200 with the correct MIME types. |
| Vue deep links | PASS for SPA delivery | 18 paths served the entry HTML. Authenticated rendering and transactions remain untested. |
| API host | PASS | Live JavaScript uses the canonical Render host; obsolete host and CORS placeholders were absent. |
| CORS | PASS | Production and the known preview origin accepted; untrusted origin rejected. |
| Backend root | FAIL | HTTP 500 from the HTTP client and the Codex browser; browser showed Laravel's 500 Server Error page. |
| Session bootstrap | FAIL | `/api/csrf-token` returned HTTP 500, so authentication cannot be verified. |
| Protected business API | BLOCKED | All 23 checked protected GET routes returned HTTP 500 before authentication rejection. This is one common backend blocker, not evidence of 23 distinct function bugs. |
| Existing health check | FALSE POSITIVE | `/up` returned HTTP 200 while session-backed requests failed. |
| Reverb in the deployed frontend | FAIL | `echo-CT95tPY7.js` compiled `connectRealtime()` into a function that returns without constructing Echo. This matches an absent build-time `VITE_REVERB_APP_KEY`. No live socket or private subscription was tested. |
| Superadmin browser session | UNAVAILABLE | The controllable frontend tab continued to show the Sign in form. No password was requested or guessed. |

Detailed evidence: [public API audit](public-api.md), [HTTP observations](public-http-results-2026-10-11.json), [Reverb evidence](public-reverb-results-2026-10-11.json), [frontend audit](frontend-assets.md), and [frontend observations](frontend-assets.json).

The user confirmed `LOG_CHANNEL=stderr`, `LOG_LEVEL=warning`, `SESSION_DRIVER=database`, and `RUN_MIGRATIONS=false`. These settings alone do not establish the cause of the 500. Missing schema, database/TLS/authentication failure and invalid encryption configuration need the server exception to distinguish them. No cause has been asserted without that exception.

## Repairs prepared from source inspection

| Repair | Reason | Verification |
| --- | --- | --- |
| Absolute backend avatar URL | Relative image URLs resolved to Cloudflare instead of the authenticated Render image endpoint. | 3 isolated regression tests, 4 assertions, passed. Actual upload/display and storage durability still need authenticated live checks. |
| Active ticket categories in the Pinia store | New ticket had five hardcoded options and could not select categories created in Administration. | New authenticated `/api/ticket-categories` route; 3 isolated API tests, 7 assertions, passed. Users with create-only permission receive a creation confirmation instead of a forbidden detail redirect. Vue production build passed with 120 modules. |
| Storage-aware `/up` | Render reported healthy even when session-backed requests failed. | Checks encryption-key construction and zero-row reads from configured database session/cache/lock tables. 5 health cases passed, including absent tables and valid storage. No session or cache values are loaded. |
| JSON aggregation connection limit | MySQL defaults to 1 KB for GROUP_CONCAT, which procedures use to assemble comments, events, asset history and analytics JSON. | Reproduced truncation on local MySQL 8.4.11, then confirmed a fresh PDO connection uses 16 MiB and preserves exact long comments, chat and asset histories. No global database configuration change. |
| Persisted multi-role SQL access | Several procedures checked only the primary users.role despite PHP considering user_roles. Normal role edits canonicalize the primary role by privilege, but preexisting additional memberships still need consistent SQL enforcement. | A forward migration installs 24 replacements; 54 isolated local MySQL checks passed. |
| Multiple-agent assignment on MySQL | TicketUpdateAgents referenced one temporary table twice in an expression, causing MySQL error 1137 (Can't reopen table). | Split addition/removal comparisons into separate statements. Two-agent assignment and denied reassignment cases passed in MySQL. |
| Linked tickets in asset details | AssetShow checked only primary role permissions and lacked the Superadmin bypass. | Corrected persisted-role permission checks; secondary dispatcher/Superadmin links visible and pure-inventory ticket privacy retained in isolated tests. |

The existing authentication, CSRF-recovery and frontend-deployment regressions also passed: 23 Node tests. Formatting and diff whitespace checks passed. These tests are local evidence, not production passes.

See [MySQL regression evidence](mysql-regression.md) for the 54 assertions, test boundaries and reproduction command. The runner creates a uniquely named schema only on an explicitly configured loopback MySQL server, then removes only that schema. It does not use Aiven. The backend's new procedure migration is `2026_10_11_000001_fix_multi_role_ticket_access.php`.

## Required deployment configuration

Cloudflare Pages must rebuild with these **build environment variables**:

| Variable | Value |
| --- | --- |
| `VITE_API_URL` | `https://ticketing-system-full.onrender.com` |
| `VITE_REVERB_APP_KEY` | Copy the public `REVERB_APP_KEY` value from the Render service. |
| `VITE_REVERB_HOST` | `ticketing-system-full.onrender.com` |
| `VITE_REVERB_PORT` | `443` |
| `VITE_REVERB_SCHEME` | `https` |

The SMTP password, database password and `REVERB_APP_SECRET` do not belong in frontend variables. Updating settings without rebuilding leaves the already compiled Reverb initializer unchanged.

After deploying the backend repairs, Render's health check will exercise the failing dependencies. Read the exception in the new deploy's runtime Logs. Keep `APP_DEBUG=false`. If the exception identifies a missing migration/table, run pending migrations on Aiven via the established deployment process (`RUN_MIGRATIONS=true` for the migration deploy, then restore `false`); do not reset or refresh the production database. The new procedure migration also needs to run before its SQL repairs apply.

## Authenticated coverage still outstanding

The [complete feature matrix](feature-matrix.md) contains exact requests, valid fixture payloads and expected outcomes. Its source expectations are explicitly separate from live results. Resume it after backend recovery with test-only records and dedicated accounts.

- Login/logout, registration validation, actual verification-mail delivery and fresh signed links, password reset, and two-factor challenge.
- Ticket create/read/status/reopen, Low default and restricted priority changes, multiple agents, requester/agent/dispatcher/admin scoping, comments/internal privacy, files, version conflicts and audit persistence.
- Serialized equipment and consumables, stock issue/restock and histories, deploy/return/damage/replace, division, ticket links, purchase-proof upload/download and costs/quantities.
- Overview charts, SLA and agent reports, inventory reports and every Excel download's actual workbook contents.
- Notification popover/count/read persistence, two-session Reverb updates and private-channel authorization.
- Administration users/categories/permission display, profile avatar display, light/dark rendering, and audit date/search/source filters.

Account deletion, real-user role/permission changes, password changes and security enrollment are excluded from production smoke tests; their authorization/security behavior belongs in isolated fixture tests. Real records have been preserved.

Known source follow-ups outside the scoped fixes: chat initially reads at most 100 messages and does not consume `has_more`; the Vue conversation does not send typing presence; audit filtering applies to the latest 100 retrieved events; Vue has no 2FA/passkey enrollment page, and the backend WebAuthn relying-party domain does not match the unrelated frontend domain. These are not labeled as live-tested defects.
