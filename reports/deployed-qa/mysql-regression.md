# Local MySQL regression evidence

Date: 2026-10-11, Asia/Singapore. This is **local verification**, not a deployed-system pass.

`scripts/verify-multi-role-access.php` completed **54 checks** against MySQL **8.4.11** on loopback port3307. It created its own `ticketing_test_multirole_<process-id>` schema, applied the complete migration sequence including the new forward migration, and removed that schema in `finally`. No Aiven or production connection was made. Credentials stayed in process memory; no `.env` file was changed.

Verified behavior:

- Secondary administrator, Superadmin and dispatcher roles can list/open the appropriate global ticket scope; a secondary agent can be chosen and see assigned tickets. Requester outsiders and inventory-only users remain denied.
- Multiple-agent assignment succeeds on MySQL after splitting the previous double temporary-table reference into separate statements. Agent priority changes, assignment changes and access after removal remain denied.
- Internal notes and typing are visible to secondary staff and hidden from requesters. Attachment metadata authorization, own-notification mark-as-read and administration access follow multiple-role membership.
- Secondary-agent category default assignment and inactive-category management work. Asset links are visible to secondary ticket staff and Superadmin, while inventory-only accounts do not receive restricted ticket metadata.
- Regular SLA alerts reach both assignees and relevant global staff exactly once; requester reopen also notifies additional assignees. Report scopes remain correct.
- At MySQL's default `group_concat_max_len=1024`, a public comment longer than1500 characters reproduces invalid truncated JSON. After purging the connection, the application's PDO initialization sets the session to **16777216 bytes (16MiB)**. Exact comment text survives TicketShow and TicketChatRead; two long inventory movement notes and report JSON also survive.

Validation output:

```text
CONFIRMED: 1024-byte group_concat_max_len truncates a >1500-character public comment.
PASS 54 local MySQL multi-role, assignment, priority, private chat, inventory link, report, SLA and JSON checks.
Removed isolated local verification schema.
```

PHP syntax checks and Pint passed for the migration and verification script. Existing historical SQL sources and migrations were left unchanged; the forward migration replaces24 procedures from `database/procedures/multi_role_access/`.

The local MySQL process started for this verification, PID10608, received a graceful `SHUTDOWN` after cleanup. Its executable path was checked against the workspace runtime, and process termination was confirmed. No other server process was stopped.

Deployment still must execute `2026_10_11_000001_fix_multi_role_ticket_access` against its own configured database before the routine fixes take effect. The larger JSON session limit takes effect on newly established application database connections. This audit agent did not execute any production migrations.
