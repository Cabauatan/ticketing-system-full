# IT asset inventory

Open **Inventory** in the sidebar or `/inventory`. Agents, admins, and superadmins can manage equipment by default. Requesters cannot access employee custody records. Superadmins can revoke `inventory.view`, `inventory.manage`, and `inventory.export` through the existing permission API. Management and export also require inventory view access.

## Register equipment

Add one asset per physical item. Asset tags are unique, as are nonblank serial numbers. Supported types: desktop, laptop, monitor, printer, network, peripheral, consumable, other. Record brand/model, specifications, purchase date, warranty, storage location, and accessories. New assets start as available. Employee names/IDs identify equipment holders independently of application accounts and ticket agents.

Consumables such as paper, toner, cables, and adapters are tracked as one stock item with a quantity on hand and unit cost; they do not need serial numbers or deployment/return actions. Create the item with its initial stock (zero is allowed), then use **Issue / consume stock** to reduce the balance or **Receive / restock** to increase it. Issues require a recipient and note; both actions require a current version and create an actor-stamped history snapshot. Stock cannot go below zero, and an empty item is marked **Out of stock**. Quantity cannot be edited directly after creation so each adjustment has a traceable transaction.

## Deployment lifecycle

Division is an optional field on asset details and deploy/transfer forms. It is searchable and included in Excel exports and event snapshots. A return clears the current deployment division; previous values remain in history. Older records stay blank until updated.

- Available → deploy: employee name, department, location, and movement note required. Employee ID and accessories are optional.
- Deployed → transfer: records a new holder and preserves the former deployment in history.
- Deployed → return → returned: clears current employee custody and records storage location and returned accessories. Returned equipment can be deployed again.
- Available, returned, or deployed → damage → damaged: records broken equipment and its storage/service location.
- Available, returned, or damaged → repair → restore: marks equipment under repair, then available again.
- Deployed, damaged, or repair → replace → replaced: records that the old unit was replaced. Include the replacement asset tag and serial in the note; register and deploy the replacement as its own asset. This action does not automatically deploy another unit.
- Available, returned, damaged, repair, or replaced → retire: retains the asset record and history. Retired and replaced assets cannot be deployed.

Return deployed equipment or mark it damaged before repair or retirement. Dates are recorded automatically in UTC when the movement occurs. Each mutation requires the current asset version; stale writes are rejected. Every successful change records an immutable snapshot, action, actor, and note. Metadata edits cannot overwrite current deployment custody or accessories. There is no delete action. Existing historical returns retain their original snapshots; new returns use the distinct returned status.

## Tickets and reports

Inventory uses the private Reverb `inventory` channel. Successful asset saves, equipment movements, consumable stock movements, and ticket links broadcast an empty `inventory.changed` signal; clients fetch authorized data again. Lists and clean detail forms refresh automatically. Detail refresh pauses while editing so drafts are preserved; finish editing or explicitly discard movement input to refresh. A connection indicator shows live status. Fallback refresh runs every 15 seconds when disconnected and reconciles every 60 seconds when connected. Linked equipment on ticket pages also updates. A Reverb outage never rolls back a successful inventory write.

Use the asset detail page to search by ticket number or subject, select a ticket from the dropdown, and link it. Options show number, subject, and status; already-linked tickets are disabled. Results are paginated. This additionally requires `tickets.view` and `tickets.update`. Multiple assets may link to one ticket. Staff with inventory access see linked equipment on ticket detail. A repair movement does not automatically create or resolve a ticket.

Inventory search matches tag, serial, name, brand/model, employee, department, and location. Filter by status and type, then select **Export Excel**. Export follows the applied filters and includes current asset data. Filter to deployed or repair for those lists. Exports above 10,000 matches are rejected with a request to narrow filters; they are never silently truncated. Movement snapshots remain available on the asset detail page.

Web and JSON API endpoints (API prefix `/api`):

| Method      | Path                           | Operation                                              |
| ----------- | ------------------------------ | ------------------------------------------------------ |
| GET / POST  | `/inventory`                   | Search / register                                      |
| GET / PATCH | `/inventory/{asset}`           | Detail and history / edit metadata                     |
| POST        | `/inventory/{asset}/movements` | Version, action, employee, location, accessories, note |
| POST        | `/inventory/{asset}/stock`     | Version, issue/restock, quantity, recipient, note       |
| POST        | `/inventory/{asset}/tickets`   | Version, ticket_id, link boolean                       |
| GET         | `/tickets/{ticket}/assets`     | Staff equipment links                                  |
| GET         | `/inventory/export.xlsx`       | Filtered Excel inventory                               |

All routes require a verified session; writes use CSRF protection. Reads and writes use MySQL stored procedures. Apply migrations before building the frontend. Deployment history has no backdating, electronic signatures, or acceptance workflow in this version.

Validation: `php scripts/verify-inventory.php` uses only `ticketing_test` and cleans up its fixtures. Run `php artisan test` for HTTP, permission, validation, and Excel checks.
