# Render runtime repair — 11 October 2026

The user-provided new-instance logs show `/up` returning 500 at 14:54:02 Singapore, followed by SIGTERM and Supervisor `os.kill` raising `PermissionError: [Errno 1] Operation not permitted` for the scheduler. Reverb also entered the same failing stop path. The deployment timed out. `CheckApplicationStorage.php:19` identifies the database session-readiness query; the first Laravel/SQLSTATE exception message is still missing, so its exact database cause is not established.

## Prepared repair

- Startup retains the permissions needed to prepare CA files, runtime directories and inherited log streams, then executes Supervisor as www-data. Web and background services share that UID.
- Nginx temporary/PID/lock paths and the PHP-FPM PID use writable `/tmp` locations. Configuration tests run as the application user.
- After explicitly enabled pending migrations, `app:check-storage` checks the same storage requirements as `/up`. Failure exits before launching services and prints a concise, redacted exception. It uses zero-row queries and reads no stored session/cache values.
- Image builds still do not migrate or seed; normal startup still requires `RUN_MIGRATIONS=true` to run pending migrations. Production TLS verification is retained.

## Local validation

- 9 PHP command/health tests passed, 16 assertions. These use isolated SQLite fixtures and mocked exception output, not Aiven.
- 13 Node startup tests passed, including complete entrypoint execution with fake external commands, exact custom arguments, feature flags, migration/preflight ordering, blocked launches and modeled supervisor/worker identities.
- PHP lint, Pint, shell syntax and Git whitespace checks passed.
- The local `.env` SHA-256 remained unchanged. No production accounts or data were modified by these tests.

The Windows harness models UID transitions and does not prove Linux signal permissions. Docker is not installed locally. The new `Container runtime` GitHub workflow performs the actual Linux image test with CAP_KILL removed, a 512 MiB limit, synthetic credentials, session bootstrap, the configured synthetic Reverb application and graceful shutdown. It also checks missing-session-storage failure before service launch. Its result must be recorded after the workflow runs.

## Remaining live work

Deploy the repaired commit to the actual `ticketing-system-full` Render service. If storage fails, copy the line immediately after `Application storage readiness failed.` from that deployment's logs. Correct the identified database/TLS/schema setting, then verify final Live status and authenticated functions. Public responses from an old instance do not identify the new revision. Private Reverb subscriptions, real broadcasts, ticket/inventory/report transactions and actual email delivery are still awaiting an authenticated live session.
