# Deployed frontend audit

Checked on October 11, 2026, at 14:04 Singapore time. Frontend: https://ticketing-system-full.pages.dev. Evidence: `frontend-assets.json` in this directory.

## Live HTTP checks

All public checks were read-only and sequential, limited to fewer than 25 requests per minute. No login, account creation, emails, upload, export, ticket write, or inventory write was performed by this audit.

| Check | Result |
| --- | --- |
| Entry HTML | HTTP 200; deployed main bundle is `/assets/index-CVLvJGxz.js` |
| Referenced resources | 35 JavaScript, CSS, icon, and font resources returned HTTP 200 with appropriate MIME types |
| Lazy Vue pages | Login, registration, password reset, verification, layout, overview, tickets, ticket creation/details, inventory, asset creation/details, reports, notifications, administration, profile, forbidden, and not-found chunks are accessible |
| SPA route fallback | All 18 tested deep paths returned HTTP 200 and the same entry HTML, including `/tickets/new`, `/inventory/new`, `/tickets/1`, `/inventory/1`, `/reports`, `/admin`, and `/settings/profile` |
| Unknown path | Returns the SPA entry HTML, allowing Vue's not-found view to handle it |
| Brand asset | `/SystemIcon.png` returns image/png; matches the local built image |
| API origin in live JavaScript | Canonical `https://ticketing-system-full.onrender.com` is present |
| Obsolete configuration strings | No `ticketing-api-marcel.onrender.com`, `replace-frontend.pages.dev`, or LAN API URL was found in the downloaded JavaScript |
| Recent auth fixes | Live JavaScript contains verification delivery status handling and the one-retry CSRF marker |

The literal `http://localhost` detected by the broad scan belongs to Axios's non-browser URL parsing fallback (`window.location.href || "http://localhost"`). It is not the configured API origin.

These checks establish that Cloudflare serves the application and its required code. An HTTP 200 on an authenticated page path is a successful SPA fallback, not proof that that page's authenticated data or actions work.

## Source integration review

These findings are from the local source corresponding to the deployed application. They are separate from authenticated live functional checks.

| Feature | Source integration and remaining live verification |
| --- | --- |
| Theme | Top-bar toggle updates `html[data-theme]` and localStorage; bootstrap restores the preference. Global CSS covers ticket/asset details, notifications, profile controls, and scoped administration/report styles include dark-mode rules. Actual rendering and contrast require browser verification. |
| Avatar | Confirmed cross-domain defect: `User::avatarUrl()` previously returned `/api/settings/profile/avatar?...`, while both profile and sidebar images bound that path directly. On pages.dev this resolves to the frontend origin instead of Render. Fixed locally by generating the named backend route as an absolute URL; not marked live until deployment and authenticated upload/display verification. |
| Notification bell | Store loads unread count and preview, renders an empty popover, patches mark-as-read, updates both lists/count, and requests refresh. Layout reacts to Reverb refresh events and has a 60-second count fallback. Live read/count behavior still needs authenticated checks. |
| Reports | Ticket and inventory reads and Excel downloads use Pinia stores and the shared credentialed API client. Inventory export is gated by the backend's `canExportInventory` field. Correct workbook content, disposition header, permissions, and authenticated downloads still need live checks. |
| New asset | Separate `/inventory/new` page sends structured asset data, then sends purchase proof as multipart FormData. A proof failure retains the saved asset ID rather than silently claiming the attachment succeeded. Consumable quantity, lifecycle moves, purchase proof, stock issue/restock, and movement history are wired through the inventory store. Persistence after Render restarts is an infrastructure concern outside static coverage. |
| New ticket | Separate `/tickets/new` page submits via the ticket store and does not send priority, allowing the backend to enforce Low. Confirmed source gap: its category selector had five hardcoded options, so newly configured administration categories were not selectable. Root agent owns that fix. |
| Ticket permissions | Detail view gates assignment, priority changes, internal messages, and status updates using backend capability flags; the router and sidebar gate modules by permissions. Backend enforcement and role-specific live visibility still need authenticated tests. |
| Reverb | Layout connects private workspace/user/inventory channels; views register refresh listeners. Connection host/key/channel authorization and actual broadcasts require the separate live WebSocket/API audit. |

No direct Axios or fetch data calls were found in the audited view/layout files; their data reads and mutations use Pinia stores. Real-time authorization also uses the shared API client.

## Scoped avatar fix validation

- Changed `app/Models/User.php` to use `route('api.profile.avatar', ['v' => $this->avatar_path])`.
- Updated the existing upload assertion in `tests/Feature/Settings/ProfileUpdateTest.php` for an absolute route.
- Added `tests/Feature/Settings/AvatarUrlTest.php`: three tests and four assertions passed. The cases cover the production canonical Render origin even when the request host is the frontend, a local backend IP and port, URL-encoded cache key, and the initials fallback for users without an avatar.
- Tests did not run migrations, write to Aiven, send emails, or modify deployed accounts.

Authenticated live workflows, mailbox delivery, file persistence, charts populated from real data, role-specific access, multi-agent assignment, and Excel contents remain outside the static read-only result.
