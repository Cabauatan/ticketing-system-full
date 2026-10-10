# Backend permission management

Superadmin manages role permissions through the authenticated JSON API.
Requester, Agent and Admin have configurable permissions within their existing
role boundaries. Superadmin always retains access and cannot be restricted by
this matrix. Role assignment remains a separate operation.

## Read the matrix

`GET /api/admin/permissions` (Superadmin only) returns:

```json
{
    "data": {
        "catalog": { "tickets.update": ["agent", "admin"] },
        "roles": [
            {
                "role": "agent",
                "permission": "tickets.update",
                "allowed": 1,
                "version": 1
            }
        ]
    }
}
```

The example is abbreviated. Each role has a version shared by all its rows.
`GET /api/permissions` returns the current user's effective permission names.
The permissions API exposes these values for frontend controls. The backend
continues to enforce permissions independently of frontend visibility.

## Replace a role's allowed permissions

`PUT /api/admin/permissions/{role}` accepts `requester`, `agent`, or `admin`.
Use the current version from the read response. The list is a complete
replacement: omitted permissions are disabled. An empty list disables all
configurable ticketing permissions for that role; login and profile access
remain available.

To disable Agent Excel export, read the existing allowed Agent permissions,
remove `reports.export`, and submit the remaining list:

```js
const matrixResponse = await fetch('/api/admin/permissions', {
    headers: { Accept: 'application/json' },
});
const matrix = await matrixResponse.json();
if (!matrixResponse.ok) throw new Error(matrix.message);
const rows = matrix.data.roles.filter((row) => row.role === 'agent');
const xsrf = document.cookie
    .split('; ')
    .find((cookie) => cookie.startsWith('XSRF-TOKEN='));
const response = await fetch('/api/admin/permissions/agent', {
    method: 'PUT',
    headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-XSRF-TOKEN': decodeURIComponent(xsrf?.slice(11) ?? ''),
    },
    body: JSON.stringify({
        version: rows[0].version,
        permissions: rows
            .filter((row) => row.allowed && row.permission !== 'reports.export')
            .map((row) => row.permission),
    }),
});
const result = await response.json();
if (!response.ok) throw new Error(result.message);
console.log(result.data.version);
```

Successful saves return the new role version. A stale version returns **409**;
reload the matrix and review the changes before retrying. No partial update
is applied. Invalid names, duplicates and permissions beyond the role boundary
return **422**. Non-Superadmin callers receive **403**.

## Permission catalog

All three configurable roles: `tickets.view`, `tickets.create`,
`tickets.comment`, `tickets.reopen`, `attachments.upload`,
`attachments.download`, `reports.view`, `reports.export`,
`notifications.view`, `notifications.read`.

Agent and Admin additionally: `tickets.update`, `tickets.internal`.

Admin additionally: `users.view`, `users.roles`, `categories.manage`,
`audit.view`.

`tickets.internal` controls writing internal notes; staff visibility of existing
notes remains governed by the ticket read permission and staff role.
Excel export requires both `reports.view` and `reports.export`. Upload through
the application requires ticket read access as well as `attachments.upload`.
Existing screens may link to an action a user cannot perform; the backend
returns 403. A visual permission editor is not part of these backend endpoints.

## Enforcement and auditing

Web and API routes use the same permission middleware. MySQL procedures also
check permissions through `PermissionRequire`; directly calling the underlying
ticket procedures does not bypass action restrictions. Ownership and staff-role
checks remain in place. Removing a permission never expands record visibility.

Permission writes are performed by `PermissionSet` inside a transaction. The
shared administrative lock serializes them with role changes. Every successful
save writes `permissions_changed` to the admin audit log. No permission cache
or logout is required: subsequent requests use the persisted role and matrix.
Requests already in progress are not cancelled by a permission change.

The SLA scheduler and first-admin bootstrap commands are trusted operator
operations and do not use the end-user matrix. Keep database credentials and
server command access restricted to operators.
