# Ticket chat

Each ticket has a Vue chat panel backed by its existing comments and audit
history. Public replies are visible to the requester and authorized support
staff. Internal notes, including their typing indicators, remain staff-only.
Files use the existing ticket attachment panel and are shared with everyone
authorized to access the ticket; attachments are not private internal notes.

Laravel Reverb pushes private-channel change events to Laravel Echo in Vue.
The chat shows `Live · Connected` after its subscription succeeds. Events
trigger an authorized HTTP fetch of new messages and typing state. A 30-second
reconciliation catches missed events; disconnected clients fall back to
three-second polling while visible. Sending displays the reply after
the server confirms it. Disconnected drafts stay in the composer; failed sends
are not automatically retried, to avoid duplicate messages. Polling reconnects
automatically; an expired login or revoked access requires a refresh/sign-in.

Messages are loaded in ID order, 100 per polling response. Older conversations
continue loading in batches. New messages do not move the scroll position when
the user is reading earlier messages. The unread button jumps to the latest
message. Read state is saved per ticket/user only when the conversation is
visible and scrolled to the bottom. This count is separate from the general
notification inbox; opening chat does not mark unrelated alerts read.

Typing heartbeats expire after eight seconds. Closing a tab or losing a
connection cannot leave a permanent typing indicator. No draft text is sent
with a heartbeat.

## API

All routes require a verified session and ticket-view permission. Ownership
and staff access are also checked in MySQL on every call.

- `GET /api/tickets/{ticket}/chat?after=0`: returns `data.messages`,
  `data.typing`, `data.unread`, and `data.has_more`. Send the last received
  message ID as `after` on subsequent requests.
- `POST /api/tickets/{ticket}/chat/presence`: accepts `typing` and `internal`
  booleans, plus `seen` (last displayed message ID; 0 leaves read position
  unchanged). Read markers never move backwards. Typing requires comment
  permission; internal typing additionally requires internal-note permission.
- `POST /api/tickets/{ticket}/comments`: sends `body` and `internal`, using
  the existing validation, permission, notification and audit workflow.

Mutations use the normal CSRF token. Chat read/presence routes share a separate
120-request/minute bucket per user; comment sends retain the ordinary API
limit. See [Reverb setup](reverb.md) for local startup and deployment settings.
