# Render Docker deployment

Deploy the existing Laravel API to a Render Free Web Service with Aiven MySQL, and deploy the existing Vue application to Cloudflare Pages. The cloud templates are separate from the local .env and LAN startup scripts. Application routes, authentication, permissions and database data remain intact.

For a sequential Filipino walkthrough using the existing GitHub repository and verified Aiven connection, start with [DEPLOYMENT_START_HERE.md](DEPLOYMENT_START_HERE.md).

Creating accounts, supplying credentials, provisioning MySQL, uploading its CA certificate and publishing the services remain manual dashboard steps. Render Free is suitable for a demo/evaluation with the operational limitations below.

## Application findings

- composer.json allows PHP 8.3, but locked production packages require PHP 8.4.1 or newer. Docker uses PHP 8.5 FPM on Alpine, matching the local 8.5 runtime.
- Nginx serves public/index.php and listens on 0.0.0.0:$PORT, default 10000. Supervisor manages Nginx, PHP-FPM and explicitly enabled background processes.
- Extensions include PDO MySQL/SQLite, mbstring, bcmath, ZIP, DOM/XMLReader/XMLWriter, cURL, GD and PCNTL. Core fileinfo, OpenSSL and iconv are available; OPcache is configured.
- Authentication uses Fortify web sessions, cookies and CSRF. Sanctum and Bearer-token authentication are not installed. Existing JSON routes enforce permissions and email verification.
- MySQL is required for the ticket/inventory stored procedures. SQLite configuration remains available for framework development/tests; it does not implement the stored-procedure business layer.
- Reverb provides synchronous real-time broadcasts. The scheduler runs tickets:process-sla each minute, including overdue and 7-day urgent-priority behavior.
- Email verification/password reset needs a delivery provider. Purchase proof, attachments and avatars use explicit local storage disks.
- Image builds do not execute migrations or seeds. Database migrations and SQL procedure sources remain in the image; real environment files, database files and uploads do not.

## 1. Prepare GitHub

Review the existing working tree before creating a deployment commit:

~~~powershell
git status --short
git diff --check
git ls-files -- .env .env.render.local frontend-ticketing/.env ca.pem
~~~

The last command should print no files. Keep real environment files, credentials, local databases, uploaded documents, logs, vendor, node_modules and runtime bundles out of the commit. Example templates can be committed.

Create a GitHub repository, use its actual URL and your selected branch, and review everything staged:

~~~powershell
git add app bootstrap config routes database resources public composer.json composer.lock frontend-ticketing Dockerfile .dockerignore docker render.yaml .env.render.example RENDER_DEPLOYMENT.md .gitignore
git diff --cached --stat
git diff --cached
git commit -m "Prepare Docker deployment for Render"
git remote add origin https://github.com/REPLACE_OWNER/REPLACE_REPOSITORY.git
git push -u origin REPLACE_BRANCH
~~~

If origin exists, inspect git remote -v and use it instead of adding a duplicate. Avoid staging unrelated work or credential files. Render builds committed files rather than uncommitted code on your PC.

## 2. Create Aiven MySQL

1. In Aiven Console create a MySQL service on Free and wait until it is running. Free currently provides 1 GB storage and 1 GB RAM with restricted cloud/region selection. Keep independent backups. See [Aiven setup](https://aiven.io/docs/products/mysql/get-started) and [plan limits](https://aiven.io/pricing/mysql).
2. Copy the public hostname, actual service port, username and password from connection information. Do not assume port 3306.
3. Create a dedicated database such as ticketing from the Databases page, or deliberately use defaultdb. Set DB_DATABASE to the exact chosen name.
4. Download the project CA certificate from the service Overview page and keep it outside Git. Use the certificate hostname rather than its resolved IP. See [Aiven certificates](https://aiven.io/docs/platform/concepts/tls-ssl-certificates).
5. Migration privileges must include CREATE ROUTINE, ALTER ROUTINE and EXECUTE plus the table/schema privileges. Runtime needs EXECUTE and application table privileges. The repository SQL does not hardcode a foreign DEFINER; procedures are installed under the migration account. The default avnadmin can initialize the service; use an appropriately scoped application account where practical. See [Aiven user privileges](https://aiven.io/docs/products/mysql/howto/manage-service-users).

This non-destructive MySQL client check prompts for the password:

~~~powershell
mysql --host=REPLACE_AIVEN_HOST --port=REPLACE_AIVEN_PORT --user=REPLACE_USER --password --ssl-mode=VERIFY_IDENTITY --ssl-ca="C:/path/to/ca.pem" --database=ticketing
~~~

Run:

~~~sql
SELECT VERSION(), DATABASE();
SHOW SESSION STATUS LIKE 'Ssl_cipher';
SHOW GRANTS;
~~~

Ssl_cipher must be non-empty. Do not remove procedures to bypass permissions errors.

Laravel accepts DB_SSL_CA or MYSQL_ATTR_SSL_CA; MYSQL_ATTR_SSL_CA takes precedence. A configured CA enables PDO TLS certificate verification. Local database settings remain unchanged when neither variable is set.

## 3. Generate production secrets

Generate APP_KEY once, store it in Render Environment and keep a secure backup. Do not regenerate it on deploy or run key:generate without --show against the local .env:

~~~powershell
& ".\.runtime\php-8.5.10\php.exe" artisan key:generate --show
~~~

Alternatively, after building the Docker image:

~~~powershell
docker run --rm --entrypoint php ticketing-render artisan key:generate --show
~~~

Generate distinct Reverb key/secret values:

~~~powershell
& ".\.runtime\php-8.5.10\php.exe" -r 'echo bin2hex(random_bytes(24)), PHP_EOL;'
~~~

Run separately for the public key and private secret, and choose a unique REVERB_APP_ID. Only REVERB_APP_KEY goes into Vue; REVERB_APP_SECRET remains on the backend. Avoid commits/screenshots containing secrets.

## 4. Create the Render Web Service

Import render.yaml through Render's Blueprint flow, or create the service manually. The Blueprint creates only the web service. Aiven and Cloudflare are separate steps; sync:false variables are supplied through Render. See [Blueprint settings](https://render.com/docs/blueprint-spec).

For manual setup:

1. Connect GitHub and select the deployment branch.
2. Select Web Service, Docker runtime and Free instance. Use repository root, Dockerfile ./Dockerfile and repository-root build context.
3. Leave the Docker command override empty. The image startup script runs Nginx/PHP-FPM; do not use php artisan serve.
4. Set health-check path /up and automatic deploys on commits.
5. Supply the environment checklist below.
6. Under Environment > Secret Files add filename ca.pem and paste the complete downloaded PEM certificate. Set DB_SSL_CA=/etc/secrets/ca.pem. See [Render secret files](https://render.com/docs/configure-environment-variables).
7. Save and deploy. Read errors in Render Logs; never put credentials in Dockerfile, build arguments or render.yaml.

Render handles public HTTPS. Internal PHP-FPM/Reverb ports are not separate public ports. See [Docker on Render](https://render.com/docs/docker).


### Render environment checklist

Use .env.render.example as the copy template. Resolve these values before deployment:

| Variable | Production value / purpose |
| --- | --- |
| APP_NAME | Application name |
| APP_ENV | production |
| APP_KEY | Generated stable base64 key; secret |
| APP_DEBUG | false |
| APP_URL | Canonical public HTTPS backend origin; no /api suffix |
| FRONTEND_URL | Exact public HTTPS Vue origin |
| CORS_ALLOWED_ORIGINS | Comma-separated exact origins with scheme/port; no paths or wildcard |
| TRUSTED_PROXIES | * only behind Render's inaccessible container edge; actual proxy IPs/ranges elsewhere |
| LOG_CHANNEL / LOG_LEVEL | stderr / warning |
| PORT | 10000 or Render's supplied port |
| DB_CONNECTION | mysql |
| DB_HOST / DB_PORT | Aiven hostname / actual service port |
| DB_DATABASE / DB_USERNAME / DB_PASSWORD | Database/account; password is secret |
| DB_CHARSET / DB_COLLATION | utf8mb4 / utf8mb4_unicode_ci |
| DB_SSL_CA | /etc/secrets/ca.pem |
| MYSQL_ATTR_SSL_CA | Optional CA alias; leave unset when using DB_SSL_CA |
| SESSION_DRIVER | database, preserving sessions outside the container |
| SESSION_DOMAIN | null for host-only backend cookies |
| SESSION_SECURE_COOKIE | true |
| SESSION_SAME_SITE | none for pages.dev + onrender.com; lax for same-site custom domains |
| SESSION_COOKIE | Unique stable cookie name, e.g. ticketing-session |
| SESSION_LIFETIME / SESSION_ENCRYPT | Optional 120 / false |
| CACHE_STORE | database, preserving throttling/locks outside the container |
| QUEUE_CONNECTION | sync for the simplest Free deployment |
| RUN_MIGRATIONS | false except a deliberately reviewed migration deployment |
| RUN_REVERB / RUN_SCHEDULER | true for existing real-time/SLA features while awake |
| RUN_QUEUE_WORKER | false with sync queues; true only for a configured queued driver |
| BROADCAST_CONNECTION | reverb |
| REVERB_APP_ID / REVERB_APP_KEY / REVERB_APP_SECRET | Unique app ID / public key / private secret |
| REVERB_HOST / REVERB_PORT / REVERB_SCHEME | 127.0.0.1 / 8081 / http for internal publishing |
| REVERB_SERVER_HOST / REVERB_SERVER_PORT | 127.0.0.1 / 8081 |
| REVERB_ALLOWED_ORIGINS | Vue hostnames only, e.g. app.example.com,my-frontend.pages.dev |
| MAIL_MAILER / MAIL_SCHEME | smtp / smtp for STARTTLS on a supported unblocked port |
| MAIL_HOST / MAIL_PORT | Provider hostname / 2525 if supported |
| MAIL_USERNAME / MAIL_PASSWORD | Provider credentials; password is secret |
| MAIL_FROM_ADDRESS / MAIL_FROM_NAME | Provider-verified sender / application name |
| FILESYSTEM_DISK | local currently; storage limitation below applies |
| SUPERADMIN_EMAIL / SUPERADMIN_PASSWORD / SUPERADMIN_NAME | Optional explicit CLI seed only; remove password afterward |

Existing migrations create database session/cache tables. Avoid file sessions and SQLite for persistent production state on Free. Do not add DB_URL or DATABASE_URL unintentionally: a connection URL may override individual DB_* settings.

### HTTPS, cookies and CORS

HTTPS URL generation applies only in production. Trusted Render proxy headers supply protocol/client IP; a forwarded Host is not trusted. APP_URL must be the canonical HTTPS backend origin. Local HTTP development remains HTTP.

Fortify sessions and CSRF are preserved. Vue sends credentialed Axios requests, obtains /api/csrf-token and supplies the token on mutations and Reverb channel authorization. Do not remove CSRF or enable wildcard credentialed CORS to bypass configuration.

The separate pages.dev and onrender.com domains are cross-site. They require Secure cookies with SameSite=None; browsers blocking third-party cookies can still prevent login. The dependable arrangement is app.example.com on Cloudflare Pages and api.example.com on Render, both HTTPS under your own parent domain. This is same-site but cross-origin: retain explicit CORS origins and set SESSION_SAME_SITE=lax, SESSION_DOMAIN=null. A shared cookie domain is unnecessary with the existing CSRF endpoint. CORS cannot bypass browser third-party-cookie policy.

For local Vite testing against the deployed API, explicitly allow http://localhost:5173. The existing local Vue port is 5001, so allow http://localhost:5001 or your exact LAN origin when testing that setup. Keep preview origins explicit instead of permitting every pages.dev project.

## 5. Initialize MySQL deliberately

No migration runs during Docker build or normal startup. RUN_MIGRATIONS defaults false, and startup never seeds. Review migrations and back up any existing cloud database before enabling changes. Never use migrate:fresh, migrate:refresh, migrate:reset or db:wipe on production.

Free offers no dashboard shell or one-off jobs. Use the provided startup opt-in:

1. Configure the Aiven connection and CA file.
2. Deliberately set RUN_MIGRATIONS=true for the initial deployment.
3. Deploy and confirm all pending migrations succeed in startup logs.
4. Immediately change RUN_MIGRATIONS=false, save and redeploy.
5. Leave it false for normal pushes. Future schema updates need a reviewed migration window or an explicit CLI command.

The entrypoint runs php artisan migrate --force only when RUN_MIGRATIONS is explicitly true. A failed migration stops startup.

Alternatively, run an explicit local Docker CLI against Aiven. Copy the template to an ignored .env.render.local and fill cloud values there. Keep RUN_MIGRATIONS=false. Docker --env-file takes literal values, so omit dotenv quote characters.

~~~powershell
Copy-Item .env.render.example .env.render.local
docker build -t ticketing-render .
docker run --rm --env-file .env.render.local --mount "type=bind,source=C:\path\to\ca.pem,target=/etc/secrets/ca.pem,readonly" --entrypoint php ticketing-render artisan migrate --force
~~~

This targets the new file's Aiven connection, not the existing local .env. Never mount the root .env into this container.


### First Superadmin account

Register and verify an account through Vue, then promote it with the existing command:

~~~powershell
docker run --rm --env-file .env.render.local --mount "type=bind,source=C:\path\to\ca.pem,target=/etc/secrets/ca.pem,readonly" --entrypoint php ticketing-render artisan tickets:bootstrap-superadmin admin@example.com
~~~

The command preserves password and verification state and follows the application's existing first-Superadmin rules.

Alternatively, add SUPERADMIN_EMAIL, a new strong SUPERADMIN_PASSWORD and optional SUPERADMIN_NAME to the ignored CLI environment file, then explicitly invoke the existing seeder:

~~~powershell
docker run --rm --env-file .env.render.local --mount "type=bind,source=C:\path\to\ca.pem,target=/etc/secrets/ca.pem,readonly" --entrypoint php ticketing-render artisan db:seed --class=SuperadminSeeder --force
~~~

The seeder creates or updates that account, marks it verified and may change its password. Run it once only when that outcome is intended. Remove SUPERADMIN_PASSWORD from the CLI file and any Render environment afterward. Startup never seeds.

## 6. Deploy Vue to Cloudflare Pages

In Cloudflare **Compute > Workers & Pages > Create application**, the initial **Make something new** screen includes **Need to use the legacy Pages workflow? Continue to Pages** at the bottom. Select **Continue to Pages** first, then import the same GitHub repository.

The Pages form has **Build output directory**. A **Configure your Worker project** form with **Deploy command** is the Workers flow; return to **Continue to Pages** to use the configuration below.

| Setting | Value |
| --- | --- |
| Root directory | frontend-ticketing |
| Build command | npm ci && npm run build |
| Output directory | dist |
| Production branch | Your deployment branch |
| Node version | Satisfies package.json: ^22.18.0 or >=24.12.0; set NODE_VERSION=22.18.0 in Pages build variables |

Set SKIP_DEPENDENCY_INSTALL=true in Pages build variables. The explicit npm ci command installs the committed npm lockfile; this avoids automatic package-manager selection because both npm and pnpm lockfiles are present. See [Vue deployment](https://developers.cloudflare.com/pages/framework-guides/deploy-a-vue-site/), [build settings](https://developers.cloudflare.com/pages/configuration/build-configuration/) and [custom dependency installation](https://developers.cloudflare.com/pages/configuration/build-image/).

Set these public build variables:

~~~dotenv
VITE_API_URL=https://ticketing-api.onrender.com
VITE_REVERB_APP_KEY=REPLACE_PUBLIC_REVERB_KEY
VITE_REVERB_HOST=ticketing-api.onrender.com
VITE_REVERB_PORT=443
VITE_REVERB_SCHEME=https
~~~

VITE_API_URL is the backend origin without /api, because endpoints such as /login live at the root. VITE_API_BASE_URL remains supported for local configuration. The Vite development proxy does not run on Pages. Public variables are embedded at build time and need a rebuild when changed.

Never put APP_KEY, DB_*, SMTP passwords or REVERB_APP_SECRET in VITE_* variables. Only the public Reverb key belongs in browser code.

The frontend public/_redirects provides the SPA fallback for direct Vue route visits and must appear in dist. Keep any deliberate future API proxy rules ahead of the fallback. See [Pages routing](https://developers.cloudflare.com/pages/configuration/serving-pages/).

Once Pages gives its URL, update Render FRONTEND_URL and CORS_ALLOWED_ORIGINS to the exact origin, and REVERB_ALLOWED_ORIGINS to the hostname. Redeploy backend variables. Rebuild Pages if its backend/WebSocket hostname changes.

### npm EUSAGE and lockfile repair

If npm ci reports missing lockfile dependencies, repair the committed frontend-ticketing/package-lock.json before retrying Pages. Use a new temporary directory containing only copies of frontend-ticketing/package.json and frontend-ticketing/package-lock.json, then run:

~~~powershell
npm install --package-lock-only --ignore-scripts
npm ci
~~~

After the clean install succeeds, copy the frontend src and public directories plus index.html and vite.config.js into that same temporary directory and run npm run build there. Once both checks pass, copy the repaired package-lock.json back to frontend-ticketing/package-lock.json, review it, commit that file and push to the deployment branch. Retry Cloudflare only after the repaired lockfile is present in GitHub. Keep existing project dependencies intact; the repair and clean-install checks use the new temporary directory.

## 7. Verify deployment

Execute these after provisioning; they are not claims that cloud deployment has already passed:

~~~powershell
curl.exe -i https://ticketing-api.onrender.com/up
curl.exe -i -H "Accept: application/json" https://ticketing-api.onrender.com/
curl.exe -i -H "Accept: application/json" https://ticketing-api.onrender.com/api/me
curl.exe -i -X OPTIONS https://ticketing-api.onrender.com/login -H "Origin: https://my-frontend.pages.dev" -H "Access-Control-Request-Method: POST" -H "Access-Control-Request-Headers: content-type,x-csrf-token"
~~~

Expected: /up returns 200, / returns the API-ready JSON, unauthenticated /api/me returns 401 JSON, and preflight allows the configured origin with credentials. /up is a simple application/process check, not a database/mail diagnostic.

In the browser test registration, email verification, login, permissions, ticket/inventory creation, Excel exports, uploads and avatars. Verification/reset URLs must use canonical HTTPS hosts. For Reverb expect wss://API_HOST/app/PUBLIC_KEY, successful private-channel authorization, and a live update in a second signed-in session.

Backend Reverb publishing stays on internal port 8081. External clients use WSS/HTTPS port 443 through Nginx; do not expose 8081 as a second public Render port. See [Render WebSockets](https://render.com/docs/websocket).

### Local Docker smoke test

This does not connect to a database or change data. Generate a temporary key and disable background processes:

~~~powershell
docker build -t ticketing-render .
docker run --rm --name ticketing-render-smoke -p 10000:10000 -e APP_ENV=local -e APP_DEBUG=false -e APP_KEY=REPLACE_TEMPORARY_BASE64_KEY -e APP_URL=http://localhost:10000 -e FRONTEND_URL=http://localhost:5173 -e SESSION_DRIVER=array -e CACHE_STORE=array -e RUN_MIGRATIONS=false -e RUN_REVERB=false -e RUN_SCHEDULER=false -e RUN_QUEUE_WORKER=false ticketing-render
~~~

In another terminal:

~~~powershell
curl.exe -i http://localhost:10000/up
curl.exe -i -H "Accept: application/json" http://localhost:10000/
curl.exe -i -H "Accept: application/json" http://localhost:10000/api/me
docker stop ticketing-render-smoke
~~~

Supply a generated temporary base64 key, not the literal placeholder. To verify proxy HTTPS locally, set APP_ENV=production, APP_URL=https://api.example.com and TRUSTED_PROXIES to the test proxy address; send X-Forwarded-Proto:https through that proxy. Do not trust arbitrary proxies on a directly exposed server.

### Verification status

Executed during preparation:

- Composer validation and installed-platform requirement checks passed.
- Configuration and route caching passed with 84 registered routes.
- DeploymentCompatibilityTest passed 8 tests with 66 assertions.
- Frontend deployment regression tests passed 3 tests.
- The production Vue build completed with 120 modules and included the SPA redirects file.
- Dotenv/YAML template parsing passed; real credential/runtime/database/upload paths were checked against Git ignore rules.
- A read-only Aiven connection confirmed TLS_AES_256_GCM_SHA384 encryption. The target defaultdb has no migrations table yet; no migrations or data changes were run.

Re-run the focused regression checks with:

~~~powershell
& ".\.runtime\php-8.5.10\php.exe" artisan test --filter=DeploymentCompatibilityTest
node --experimental-vm-modules --test frontend-ticketing/tests/deployment.test.mjs
~~~

Docker is not installed in the current environment, so image build, container startup and in-container port checks were not executed. The GitHub repository now exists at https://github.com/Cabauatan/ticketing-system-full, using main; a live Render deployment remains unverified. Render HTTPS, Cloudflare browser cookies and email delivery require the deployed services and provider setup. The final task response records any broader suite failures separately; these focused passes are not a claim that the complete legacy suite passes. The local .env was preserved.


## Feature continuity and Free-plan limitations

[Render Free limitations](https://render.com/docs/free) include sleep after 15 idle minutes, cold starts, ephemeral files, no persistent disk, no dashboard shell/one-off jobs, and blocked outbound ports 25/465/587. Separate workers/cron require another plan. Check current quotas before relying on the service.

- **Database/uploads:** Aiven stores database records independently of Render. Existing proof/attachment/avatar files disappear when the container is replaced, restarted or sleeps. SQLite cannot retain production data on Free. Persistent uploads need object-storage integration or a paid persistent disk. Setting FILESYSTEM_DISK=s3 alone does not fix this: controllers explicitly use local disks and avatar responses require a local path. Adapter/controller integration is required before retaining real uploads on Free.
- **Scheduler:** RUN_SCHEDULER=true runs SLA and 7-day escalation while awake. Sleep pauses it; eligible records are processed on later runs. Exact wall-clock escalation needs an always-on scheduler/paid worker using the same database.
- **Queue:** QUEUE_CONNECTION=sync executes jobs in requests. For database queues, set QUEUE_CONNECTION=database and RUN_QUEUE_WORKER=true; the in-container worker still pauses during sleep. Reliable asynchronous jobs need an always-on worker with shared database/Redis.
- **Reverb:** RUN_REVERB=true runs the existing live notifications/chat/updates. Socket connections end during restarts/sleep and reconnect after the service returns. Larger/always-on real-time workloads need an appropriate paid service or a compatible managed broadcaster, with explicit backend/frontend configuration.
- **Email:** Choose SMTP on an unblocked port such as 2525, supported by your provider. Gmail 465/587 cannot work on Free. The log mailer never delivers email. An HTTP email provider is an alternative, but its transport SDK must first be installed/configured; current Composer dependencies omit optional provider SDKs. Verify delivery before relying on registration/password reset. See [Laravel mail transports](https://laravel.com/docs/13.x/mail).

## Troubleshooting

| Symptom | Check |
| --- | --- |
| PHP platform mismatch | Use provided PHP 8.5 image; do not ignore Composer platform requirements |
| Composer download failure | Inspect build logs/registry/network; do not copy local vendor into image |
| No open port / deployment timeout | Valid PORT, Nginx binds 0.0.0.0, Docker command override empty |
| Startup stops | Fix the reported config/cache/CA/migration error; keep APP_DEBUG=false |
| Missing APP_KEY | Supply stable production key in Render runtime Environment |
| MySQL refused/timed out | Exact running Aiven hostname/port/account and IP allowlist; Render egress ranges appear in service settings |
| TLS certificate failure | Correct complete project ca.pem, /etc/secrets/ca.pem and certificate hostname; never disable verification |
| CA startup error with correct Secret File | Update and redeploy the Docker entrypoint. It stages a CA that www-data cannot read into a root-owned runtime directory (0750), with file group www-data and mode 0640. Both CA variables are exported before Artisan/PHP-FPM/background startup. The mount remains unchanged; TLS verification remains enabled. See [Render runtime secret permissions](https://render.com/docs/docker-secrets#accessing-secret-files-at-runtime). |
| CA file does not exist / empty | Check the named variable in the error, exact Secret File filename and full PEM contents. MYSQL_ATTR_SSL_CA overrides DB_SSL_CA. Missing files still stop startup. |
| Unknown database/access denied | Existing DB_DATABASE, exact account credentials, required privileges |
| Missing procedure | Review pending migrations and CREATE/ALTER ROUTINE/EXECUTE grants; run an explicit approved migration |
| Illegal mix of collations | Preserve utf8mb4_unicode_ci and existing collation-fix migrations |
| 500 on session-backed route | Reachable MySQL and initialized sessions/cache tables; debug remains false |
| Login 419 / immediate 401 | CSRF endpoint, credentialed requests, Secure/SameSite cookies and third-party-cookie policy |
| CORS error | Exact allowed origin scheme/hostname/port; rebuild Vue after changing VITE_API_URL |
| Failed WebSocket | RUN_REVERB=true, matching public key, allowed frontend hostname, browser port 443/HTTPS, working /broadcasting/auth |
| Missing verification email | Provider credentials, verified sender, supported outbound port; avoid blocked Gmail/log mailer |
| Missing uploaded files after deploy | Local-filesystem limitation; implement persistent storage before production uploads |
| Late escalations/pending jobs | Free sleep pauses processes; use always-on scheduling/workers when deadlines matter |
| Excel export fails | OpenSpout extensions and writable temp/storage directories; image includes ZIP/XML |
| HTTP/localhost URLs | Canonical HTTPS APP_URL/FRONTEND_URL, refreshed cached config and proxy settings |

## Update through GitHub

Push a reviewed commit to the configured branch. Render rebuilds Docker and Cloudflare Pages rebuilds Vue. Save/deploy Render environment changes; public Vite values need a frontend rebuild. Keep RUN_MIGRATIONS=false by default and review schema updates separately. Never regenerate APP_KEY or seed accounts on normal deploys.

Local development continues using its existing .env and run-dev.bat, including API port 8001, Vue port 5001 and Reverb port 8081.

### CA startup regression checks

Run `node --test tests/deployment/entrypoint.test.mjs` (Node.js and Bash required; Windows defaults to Git Bash). These checks execute only the entrypoint guards, use synthetic certificate fixtures, simulate application-user readability, and stop before any application startup or database command. They cover file diagnostics, alias precedence, paths containing spaces, selective copying and child-process environment propagation. Linux ownership and the actual Render mount still require a container or deployed-service check.
## Files and executed local checks (2026-10-10)

Created:

- Dockerfile, .dockerignore
- docker/entrypoint.sh, docker/nginx.conf, docker/nginx-site.conf.template
- docker/php-fpm.conf, docker/php.ini, docker/supervisord.conf
- render.yaml, .env.render.example, RENDER_DEPLOYMENT.md
- config/cors.php, config/trustedproxy.php
- frontend-ticketing/.env.production.example, frontend-ticketing/public/_redirects
- tests/Feature/DeploymentCompatibilityTest.php, frontend-ticketing/tests/deployment.test.mjs

Updated for deployment:

- bootstrap/app.php, app/Providers/AppServiceProvider.php, config/database.php
- frontend-ticketing/src/api/http.js, frontend-ticketing/src/realtime/echo.js
- frontend-ticketing/.env.example, frontend-ticketing/.gitignore
- .gitignore, README.md

Executed successfully:

- Composer validation and non-development platform requirements on local PHP 8.5.10.
- Backend deployment tests: 8 tests, 66 assertions (CORS, authentication preservation, HTTPS/proxies, cookies and TLS configuration).
- Frontend deployment tests: 3 tests (API origin compatibility and CSRF-backed private Reverb authorization).
- Docker entrypoint shell syntax and 18 isolated startup guard cases. These stop before filesystem initialization, Artisan and database operations.
- Render YAML and environment-template parsing, required variables and credential-file ignore checks.
- Config, route and view caching. All 84 cached routes register, including /up, Fortify login and broadcasting authorization. Temporary config/route probe files were removed.
- Vue production build using public placeholder cloud settings; Cloudflare SPA fallback copied to dist. A subsequent build restored the local frontend artifact.
- LAN backend health, Vue page, session/CSRF proxy, guest broadcasting authorization and Reverb WebSocket handshake. Temporary processes were stopped.
- Local .env SHA-256 unchanged before/after work. No existing local/cloud database migrations were run.

The complete backend suite ran 143 tests: 120 passed, 7 failures and 16 errors (573 assertions). Remaining issues concern legacy Laravel-rendered page/route expectations, inventory-report permission fixtures, and GD missing from the local PHP runtime. Docker installs GD, but its image has not been built here. All new deployment tests passed in the final suite. The full suite is not green.

Reproduce the focused checks:

~~~powershell
& ".\.runtime\php-8.5.10\php.exe" "C:\ProgramData\ComposerSetup\bin\composer.phar" validate --no-check-publish
& ".\.runtime\php-8.5.10\php.exe" artisan test --filter=DeploymentCompatibilityTest
node --experimental-vm-modules --test frontend-ticketing/tests/deployment.test.mjs
~~~

Docker is not installed on this machine. Image build, actual Nginx/PHP-FPM/container startup, and container HTTP requests remain unverified. Aiven authentication and CA-verified TLS connectivity were confirmed by the user-run read-only check on 2026-10-10 (TLS_AES_256_GCM_SHA384). The migrations table was absent. Initial cloud migrations, deployment and live browser checks remain pending.
## Read-only connection check before deployment

After filling the Aiven host, port, database and username in the ignored .env.render.local file, run this from the project root. Keep the normal .env unchanged:

~~~powershell
.\scripts\check-aiven.ps1 -CaPath "C:\path\to\ca.pem"
~~~

The wrapper asks for the database password without displaying it. It passes it only to the PHP check through a temporary process environment variable, then restores/removes that variable. It does not save the password in a file or require Laravel configuration changes. Put the production password directly in Render DB_PASSWORD afterward.

The PHP check explicitly parses the deployment file, verifies the MySQL TLS CA and hostname, runs SELECT 1, checks the TLS cipher and looks for the migrations table. It never bootstraps Laravel, loads the regular .env, calls stored procedures, or performs migrations/database writes. Successful connectivity does not mean pending migrations have been applied.

For a direct PHP check where a protected credential is already supplied separately:

~~~powershell
& ".\.runtime\php-8.5.10\php.exe" scripts/check-aiven.php --env=.env.render.local --ca="C:/path/to/ca.pem"
~~~

In .env.render.local retain DB_SSL_CA=/etc/secrets/ca.pem for Docker/Render. The local --ca argument selects the Windows certificate only for the connection probe. Do not commit .env.render.local or CA/private-key files.
