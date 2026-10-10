# Reports

Exports are native `.xlsx` workbooks with Summary, Breakdowns, Daily trend, and (for staff) Agent performance sheets. Headers are frozen and filterable. Counts remain numeric, dates are Excel dates, and missing first-response averages remain blank. The Dashboard exports a separate Excel summary. OpenSpout generates these workbooks in Laravel; deployment requires PHP 8.4 or newer (the local runtime is PHP 8.5.10) and its Composer-required extensions.

Open **Reports** in the sidebar or `/reports`. Select inclusive UTC creation dates (up to 366 days), or use the 7/30/90-day presets. The Excel export uses the applied dates, even if you have edited the form without applying it.

- Summary: total, active, completed, and unassigned active tickets.
- SLA: active tickets past their current deadline, within deadline, or without a deadline. This is not historical SLA compliance; priority changes affect deadlines.
- Breakdowns: status, priority, category, and daily ticket creation volume.
- Staff performance: current assigned/active/completed/overdue counts, public replies, distinct tickets replied to, first responses, and average first-response minutes.

Reports describe the current state of tickets **created** in the selected period. Reply metrics include those tickets' entire reply history, including replies outside the creation range. They are not a count of actions performed during the range.

Shared tickets count once in ticket totals and once for each current assignee's workload. Completed assignments are not individual resolution credit. Assignment changes can change the displayed workload. First response belongs to the earliest public staff reply's author; requester replies and private notes are excluded. Time is elapsed calendar time, including nights and weekends. Users' current roles determine staff eligibility. A dash means there was no first response, not zero minutes.

Requesters see only their own tickets and no agent performance table. Staff see all tickets. Both web and API routes require authentication, email verification, and `reports.view`. Export additionally requires `reports.export`; the procedures enforce these permissions independently.

Endpoints:

- `GET /reports` — Vue page.
- `GET /api/reports?from=2026-09-01&to=2026-09-30` — JSON sections (`summary`, `breakdown`, `trend`, `agents`).
- `GET /reports/export.xlsx` and `GET /api/reports/export.xlsx` — same date parameters; Excel contains summary, breakdowns, daily volume, and staff metrics when authorized. Names and labels are stored as text cells to prevent formula execution.

Storage reads use `TicketAnalytics` and `TicketAnalyticsExport` MySQL procedures. Run migrations before deploying the frontend. The existing dashboard and its summary Excel remain available.

Validation: `php artisan test`, `php scripts/verify-reports.php`, and `php scripts/verify-mysql.php`. Integration scripts use only `ticketing_test` and clean up their disposable fixtures.
