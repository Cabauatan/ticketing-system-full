# Ticketing JSON API

Routes are defined in `routes/api.php` and registered in `bootstrap/app.php`.
Base URL locally: `http://127.0.0.1:8000/api`.

## Authentication

This is a first-party, same-origin session API. Sign in using the existing
Fortify `/login` flow (including two-factor authentication when enabled).
Requests use the Laravel session cookie. Verified email is required.
Bearer tokens and cross-origin authentication are not configured.

For POST/PATCH requests send `X-XSRF-TOKEN` with the URL-decoded value of
the `XSRF-TOKEN` cookie obtained by opening `/login`. Do not disable CSRF.
Send `Accept: application/json`; API errors also use JSON without that header.
Use the existing Fortify `/logout` endpoint with the same CSRF header to sign out.

```js
const response = await fetch('/api/tickets?page=1', {
    credentials: 'same-origin',
    headers: { Accept: 'application/json' },
});
const result = await response.json();
if (!response.ok) throw new Error(result.message);
console.log(result.data.tickets);
```

```js
const token = document.cookie
    .split('; ')
    .find((c) => c.startsWith('XSRF-TOKEN='));
const response = await fetch('/api/tickets', {
    method: 'POST',
    credentials: 'same-origin',
    headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-XSRF-TOKEN': decodeURIComponent(
            token?.slice('XSRF-TOKEN='.length) ?? '',
        ),
    },
    body: JSON.stringify({
        subject: 'Laptop will not start',
        description: 'Power light is off.',
        category: 'hardware',
        priority: 'high',
    }),
});
const result = await response.json();
if (!response.ok) throw new Error(result.message);
console.log(result.data.id);
```

## Endpoints

Permission management endpoints:

- `GET /api/permissions`: authenticated user's effective permissions.
- `GET /api/admin/permissions`: Superadmin-only catalog and role matrix.
- `PUT /api/admin/permissions/{role}`: Superadmin-only full replacement of the
  allowed permission list, with optimistic version checking.

See [permission management](permissions.md) for payloads and enforcement rules.

Superadmins can use every admin and staff endpoint. Role updates also accept
`superadmin`, but only a superadmin may grant that role or modify a superadmin.
Self-role changes are blocked for all users.

All paths below have the `/api` prefix. IDs must be positive numeric IDs.

| Method | Path                                         | Input / result                                                       |
| ------ | -------------------------------------------- | -------------------------------------------------------------------- |
| GET    | `/user`                                      | Current id, name, email, role                                        |
| GET    | `/tickets`                                   | Optional search, status, page; tickets, total, categories            |
| POST   | `/tickets`                                   | subject, description, category, priority; 201 with data.id           |
| GET    | `/tickets/{ticket}`                          | Ticket, ordered comments/events, attachments, agents                 |
| PATCH  | `/tickets/{ticket}`                          | Staff: version, status, priority, nullable assignee_id               |
| POST   | `/tickets/{ticket}/reopen`                   | Owner reopens a resolved/closed ticket                               |
| POST   | `/tickets/{ticket}/comments`                 | body, internal boolean; internal requires staff                      |
| POST   | `/tickets/{ticket}/attachments`              | Multipart file, max 2 MB; PDF/JPEG/PNG/TXT                           |
| GET    | `/tickets/{ticket}/attachments/{attachment}` | Authorized binary download                                           |
| GET    | `/dashboard`                                 | Optional days: 0, 7, 30, 90; scoped report                           |
| GET    | `/reports/tickets.xlsx`                      | Same days filter; Excel download                                     |
| GET    | `/notifications`                             | Optional page; notifications, total, unread                          |
| PATCH  | `/notifications/{notification}/read`         | Mark accessible notification read                                    |
| GET    | `/admin`                                     | Admin only; optional search/page; users, categories, agents, audit   |
| PATCH  | `/admin/users/{user}/role`                   | Admin only; role: requester, agent, admin                            |
| POST   | `/admin/categories`                          | Admin only; slug, name, active boolean, nullable default_assignee_id |

JSON reads return `{ "data": { ... } }`. Successful mutations return
`{ "message": "Saved." }` (creation returns the ID; upload returns 201).
Ticket lists have 20 rows per page; notifications have 25.
Priorities: low, medium, high, urgent. Statuses: new, open, in_progress,
pending, resolved, closed. Updates must include the current ticket version.

Errors: 401 unauthenticated, 403 forbidden/unverified, 404 inaccessible or
missing record, 419 missing/expired CSRF, 422 validation/stale version,
429 rate limit. Validation responses include `message` and `errors`.
API traffic is limited to 60 requests/minute per authenticated user; selected
mutation/download routes have additional limits. No ticket access is granted
by supplying an actor/requester ID in a request: the session determines it.

The JSON API owns validation, authorization, and MySQL stored procedure calls.
The separate Vue frontend consumes these same-origin session endpoints.
