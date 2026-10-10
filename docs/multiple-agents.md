# Multiple ticket agents

Open a ticket, then use **Manage ticket → Assign agents**. Check each staff member and save. Up to 50 agents, admins, or superadmins can share a ticket. Clear selection and save to leave it unassigned.

The migration preserves existing assignments in `ticket_assignees`. Category routing still supplies one initial agent; staff can add others afterward. All assigned staff receive reply, reopen, and SLA notifications, excluding the actor where applicable. Private notes stay restricted to staff. Newly added agents receive assignment notifications; saving the same selection does not notify them again.

Workload reports count a shared active ticket once for each assigned agent. Status, priority, and category totals still count each ticket once. Active assignments prevent demoting any assigned staff member to requester.

## API

`PATCH /api/tickets/{id}` accepts the complete replacement set:

```json
{
    "version": 1,
    "status": "open",
    "priority": "high",
    "assignee_ids": [2, 3]
}
```

Use `[]` to clear assignments. IDs must be distinct eligible staff IDs. Invalid selections or a stale version reject the entire update. Session authentication, CSRF, verification, and `tickets.update` permission apply.

Ticket detail returns `ticket.assignees` as objects with `id` and `name`. Ticket list `assignee_name` contains the assigned names. The legacy `assignee_id` field and `TicketUpdate` procedure remain compatible with older clients: those writes replace the assignment set with zero or one agent. Do not send both assignment fields. New clients should always send `assignee_ids`.

`TicketUpdateAgents` performs the multi-agent write in a MySQL transaction. The old `tickets.assignee_id` column mirrors the lowest assigned ID for compatibility. Rolling back the migration retains only that one assignment; additional memberships are lost on rollback.
