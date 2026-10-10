# Deploy mula sa simula

Updated: October 10, 2026. Ito ang sunod-sunod na guide para sa kasalukuyang project.

| Bahagi | Gagamitin |
| --- | --- |
| Source code | GitHub: Cabauatan/ticketing-system-full, branch main |
| Laravel API at Reverb | Render Web Service, Docker |
| Database | Aiven MySQL, defaultdb |
| Vue frontend | Cloudflare Pages, frontend-ticketing |

Nasa GitHub na ang project. Na-check mo na rin ang Aiven authentication at verified TLS connection. Hindi nito kinokopya ang local database: sa huling check, wala pang migrations table sa Aiven. May failed GitHub tests run pa; ang job ay huminto sa `composer setup`. Hiwalay ang GitHub test run at Render deployment. Hindi pa ito patunay na gumagana ang buong deployed system.

## 1. Ihanda ang production settings

May `.env.render.local` na sa project, hiwalay sa normal na `.env`. Nandoon na ang generated production APP_KEY, Reverb credentials at Aiven connection settings. Buksan ito sa sarili mong editor. Huwag i-commit o ibahagi ang file.

- Panatilihin ang generated APP_KEY at Reverb credentials. Huwag gumawa ng bagong APP_KEY sa bawat deploy.
- Ang DB_PASSWORD ay ilalagay mo sa Render Environment mula sa Aiven Console. Hindi ito ang password ng local MySQL o ng user account sa ticketing system.
- Papalitan ang APP_URL at frontend URL placeholders ng aktuwal na public URLs sa mga susunod na hakbang.
- Panatilihin ang normal na local `.env` at `run-dev.bat` para sa local development.

## 2. Gumawa ng Render backend

Pumunta sa [Render Dashboard](https://dashboard.render.com/) at piliin ang **New > Web Service**. Ikonekta ang GitHub at piliin ang repository `Cabauatan/ticketing-system-full`.

| Setting | Ilalagay |
| --- | --- |
| Name | Pumili ng available na pangalan, halimbawa ticketing-api-marcel |
| Branch | main |
| Language / Runtime | Docker |
| Root Directory | Iwanang blank; repository root ang backend |
| Dockerfile Path | ./Dockerfile |
| Docker Build Context | . |
| Docker Command | Iwanang blank |
| Instance Type | Free para sa demo |
| Health Check Path | /up |
| Auto-Deploy | On Commit para sa direktang deploy ng pushed commits |

Hindi kailangang mag-install ng Docker sa PC para sa Render build. Render mismo ang magbu-build ng committed Dockerfile. Hindi rin kailangan ng `php artisan serve` o ng local `.bat` sa Render. Tingnan ang [Render Docker instructions](https://render.com/docs/docker).

## 3. Ilagay ang Render Environment at certificate

Sa service **Environment**, gamitin ang **Add from .env** para sa settings mula sa `.env.render.local`. Reviewhin ang imported values bago mag-save. Ilagay ang tunay na Aiven password sa DB_PASSWORD; palitan ang bawat `replace-*` placeholder.

Ito ang aktuwal na database settings mo:

```dotenv
DB_CONNECTION=mysql
DB_HOST=mysql-fe2cc11-marcelcabauatan-96d6.g.aivencloud.com
DB_PORT=14717
DB_DATABASE=defaultdb
DB_USERNAME=avnadmin
DB_PASSWORD=YOUR_AIVEN_PASSWORD
DB_CHARSET=utf8mb4
DB_COLLATION=utf8mb4_unicode_ci
DB_SSL_CA=/etc/secrets/ca.pem
```

Sa **Environment > Secret Files > Add Secret File**:

1. Filename: `ca.pem`.
2. Contents: buong laman ng `C:\Users\Marcel Jay\Downloads\ca.pem`, kasama ang BEGIN/END CERTIFICATE lines.
3. Save Changes.

Ang Windows path ay pang-PC lamang. `/etc/secrets/ca.pem` ang path sa Render. Tingnan ang [Render environment variables at secret files](https://render.com/docs/configure-environment-variables).

Kung tama na ang path at certificate pero may CA permissions error, i-push at i-deploy ang updated `docker/entrypoint.sh`. Inihahanda nito ang CA sa private runtime directory kapag hindi ito mabasa ng PHP user. Mananatiling `/etc/secrets/ca.pem` ang dashboard value; awtomatiko ang internal runtime path at nananatiling enabled ang TLS verification. Kung may `MYSQL_ATTR_SSL_CA`, iyon ang inuuna sa `DB_SSL_CA`; alisin ang lumang alias o itugma ang path. Missing o empty Secret Files ay may hiwalay na startup error.

Tiyaking tama rin ang settings na ito:

```dotenv
APP_ENV=production
APP_DEBUG=false
TRUSTED_PROXIES=*
LOG_CHANNEL=stderr
PORT=10000
SESSION_DRIVER=database
SESSION_DOMAIN=null
SESSION_SECURE_COOKIE=true
SESSION_SAME_SITE=none
SESSION_COOKIE=ticketing-session
CACHE_STORE=database
QUEUE_CONNECTION=sync
RUN_REVERB=true
RUN_SCHEDULER=true
RUN_QUEUE_WORKER=false
BROADCAST_CONNECTION=reverb
REVERB_HOST=127.0.0.1
REVERB_PORT=8081
REVERB_SCHEME=http
REVERB_SERVER_HOST=127.0.0.1
REVERB_SERVER_PORT=8081
```

APP_KEY, REVERB_APP_ID, REVERB_APP_KEY at REVERB_APP_SECRET: gamitin ang existing generated values sa private deployment file. Ang 127.0.0.1 sa Reverb settings ay koneksyon sa loob ng Render container; public hostname at port 443 ang gagamitin ng frontend.

Para sa unang backend startup, puwedeng `MAIL_MAILER=log` habang wala pang mail provider. Hindi ito nagpapadala ng verification/reset email. Bago subukan ang registration verification, mag-configure ng provider na may SMTP port 2525 at verified sender. Hindi gagana ang Gmail SMTP ports 465/587 sa Render Free. Huwag iwanang aktibo ang placeholder SMTP credentials.

## 4. Unang deployment at migrations

Ang unang migration ay gagawa ng tables at stored procedures sa Aiven, hindi sa local MySQL. Kung may ibang data na sa defaultdb, i-review at i-backup muna iyon.

1. Para sa initial schema setup, ilagay ang `RUN_MIGRATIONS=true` sa Render Environment.
2. I-deploy ang service; kung nauna ang deploy bago matapos ang settings, i-save ang kumpletong settings at i-deploy muli.
3. Sa Logs, hintayin ang successful pending migrations at service startup.
4. Kopyahin ang public URL na ibinigay ng Render, halimbawa `https://ticketing-api-marcel.onrender.com`. Gamitin ang aktuwal na URL, hindi ang halimbawang ito.
5. Ilagay ang actual URL sa APP_URL, walang trailing `/api`.
6. Kapag successful na ang migrations, ibalik agad ang `RUN_MIGRATIONS=false` at **Save and deploy**.
7. Buksan ang `https://YOUR-BACKEND.onrender.com/up`. Dapat HTTP 200. Simple process check ito; kailangan pa ring subukan ang login at database-backed pages.

Huwag gamitin ang `migrate:fresh`, `db:wipe` o `--seed` sa automatic deployment. Hindi awtomatikong kinokopya ang local accounts sa Aiven.

## 5. Gumawa ng Cloudflare Pages frontend

Sa [Cloudflare Dashboard](https://dash.cloudflare.com/), pumunta sa **Workers & Pages**, gumawa ng **Pages** project at ikonekta ang parehong GitHub repository. Piliin ang Pages Git integration.

| Setting | Ilalagay |
| --- | --- |
| Production branch | main |
| Root directory | frontend-ticketing |
| Build command | npm ci && npm run build |
| Build output directory | dist |

Ilagay ang production build variables:

```dotenv
NODE_VERSION=22.18.0
SKIP_DEPENDENCY_INSTALL=true
VITE_API_URL=https://YOUR-BACKEND.onrender.com
VITE_REVERB_APP_KEY=YOUR_EXISTING_PUBLIC_REVERB_APP_KEY
VITE_REVERB_HOST=YOUR-BACKEND.onrender.com
VITE_REVERB_PORT=443
VITE_REVERB_SCHEME=https
```

VITE_REVERB_HOST ay hostname lang, walang `https://`. VITE_API_URL ay buong backend origin, walang `/api`. REVERB_APP_KEY ang public frontend key; hindi REVERB_APP_SECRET.

May npm at pnpm lockfiles ang project. Ang explicit `npm ci` at SKIP_DEPENDENCY_INSTALL ay gumagamit ng committed npm lockfile at nilalaktawan ang automatic package-manager selection. Tingnan ang [Cloudflare Vue build settings](https://developers.cloudflare.com/pages/framework-guides/deploy-a-vue-site/) at [custom dependency installation](https://developers.cloudflare.com/pages/configuration/build-image/).

Deploy, pagkatapos kopyahin ang actual production `https://YOUR-FRONTEND.pages.dev` URL.

## 6. Ikonekta ang actual frontend URL sa Render

Balik sa Render Environment. Gamitin ang actual Cloudflare production URL:

```dotenv
FRONTEND_URL=https://YOUR-FRONTEND.pages.dev
CORS_ALLOWED_ORIGINS=https://YOUR-FRONTEND.pages.dev
REVERB_ALLOWED_ORIGINS=YOUR-FRONTEND.pages.dev
RUN_MIGRATIONS=false
```

Save and deploy. Sa CORS, buong origin ang ilalagay. Sa Reverb allowed origins, hostname lang. Mag-rebuild ng frontend kapag binago ang VITE_* settings.

Magkaibang sites ang pages.dev at onrender.com. Ang SameSite=None at Secure cookies ay kailangan, pero maaaring i-block pa rin ng browser ang third-party cookies. Para sa maaasahang session login, gamitin ang sariling parent domain, halimbawa `app.example.com` at `api.example.com`; gamitin ang actual custom domains sa URLs/CORS/Reverb at `SESSION_SAME_SITE=lax`.

## 7. Gumawa ng unang Superadmin sa bagong database

Wala pang local user accounts sa bagong Aiven schema. Hindi nagse-seed ang startup. Para sa explicit one-time seeder, may Docker CLI procedure sa [full deployment guide](RENDER_DEPLOYMENT.md#first-superadmin-account). Kailangan ang Docker Desktop sa PC para sa paraang iyon; walang Shell ang Render Free.

Sa private `.env.render.local`, ilagay ang actual cloud URLs at Aiven password, pagkatapos idagdag ang:

```dotenv
SUPERADMIN_EMAIL=marcelcabauatan@gmail.com
SUPERADMIN_PASSWORD=CHOOSE_A_NEW_PRODUCTION_PASSWORD
SUPERADMIN_NAME=Superadmin
RUN_MIGRATIONS=false
```

Kapag installed at running na ang Docker Desktop, sa project root:

```powershell
docker build -t ticketing-render .
docker run --rm --env-file .env.render.local --mount "type=bind,source=C:\Users\Marcel Jay\Downloads\ca.pem,target=/etc/secrets/ca.pem,readonly" --entrypoint php ticketing-render artisan db:seed --class=SuperadminSeeder --force
```

Gumagamit ng literal values ang Docker --env-file: huwag balutan ng dotenv quote characters ang values. Ang seeder ay gumagawa o nag-u-update ng account, nagse-set ng password at nagma-mark ng verified. Patakbuhin ito isang beses para sa intended account, pagkatapos alisin ang SUPERADMIN_PASSWORD sa file. Huwag awtomatikong patakbuhin ang seeder sa bawat redeploy.

## 8. Subukan ang deployed system

Sa actual frontend URL, subukan ang login, permissions, ticket/inventory pages, Excel export, avatars at attachments. Sa dalawang signed-in sessions, subukan ang live notifications at Reverb updates. Subukan din ang real email verification at password reset kapag configured na ang mail provider.

May tatlong limitasyon ang Free setup bago gamitin sa tunay na operations:

- Nawawala ang existing local-disk uploads kapag nag-restart, redeploy o sleep ang Render. Kailangan ng persistent-storage integration para mapanatili ang purchase proofs, ticket attachments at avatars.
- Nag-sleep ang Render Free pagkatapos ng 15 idle minutes; humihinto ang in-container scheduler habang tulog. Hindi nito magagarantiyahan ang eksaktong oras ng SLA/7-day escalation.
- Blocked ang outbound SMTP ports 25/465/587. Gumamit ng supported port/provider para sa email.

Nananatili sa Aiven ang database records. Tingnan ang [Render Free limitations](https://render.com/docs/free) para sa operational details. Ang cloud build, migrations at browser behavior ay kailangan pang patunayan sa actual deployment; hindi pa green ang buong existing test suite.
