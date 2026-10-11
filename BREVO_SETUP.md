# Brevo email setup for Render

The app uses Brevo SMTP on port 2525. Gmail SMTP ports 465 and 587 are blocked on Render Free. The local deployment settings file has been prepared with the Brevo host; the running Render service still needs its own credentials and deployment.

## 1. Prepare the sender in Brevo

Create your Brevo account, then open **Settings → Senders, Domains, IPs → Senders → Add a sender**.

- From name: `SupportDesk`
- From email: `marcelcabauatan@gmail.com`, if Brevo permits this sender for your account

Complete the verification code Brevo sends to the sender inbox and any sender/domain requirements it shows. If Brevo requires a sender on an authenticated domain, use that approved address in `MAIL_FROM_ADDRESS` instead. [Brevo sender instructions](https://help.brevo.com/hc/en-us/articles/208836149-Create-a-new-sender-From-name-and-From-email).

## 2. Get the SMTP credentials

Open **Settings → SMTP & API → SMTP**. Copy the **Login** value and generate a **Standard SMTP key** named `SupportDesk Render`.

`MAIL_USERNAME` is the SMTP Login displayed by Brevo. It may be a generated `@smtp-brevo.com` address. `MAIL_PASSWORD` is the generated SMTP key. Store the key directly in Render; do not use your Gmail password or an API key. [Brevo SMTP key instructions](https://help.brevo.com/hc/en-us/articles/7959631848850-Create-and-manage-your-SMTP-keys).

## 3. Update the Render service

Open the **ticketing-system-full service → Environment**. Edit only these mail values:

| Key | Value |
| --- | --- |
| `MAIL_MAILER` | `smtp` |
| `MAIL_SCHEME` | `smtp` |
| `MAIL_HOST` | `smtp-relay.brevo.com` |
| `MAIL_PORT` | `2525` |
| `MAIL_TIMEOUT` | `10` |
| `MAIL_USERNAME` | Your Brevo SMTP Login |
| `MAIL_PASSWORD` | Your Brevo SMTP key |
| `MAIL_FROM_ADDRESS` | Your verified and approved Brevo sender |
| `MAIL_FROM_NAME` | `SupportDesk` |

Remove an obsolete `MAIL_URL` if present, since it overrides SMTP settings. Use **Save and deploy**. The container rebuilds Laravel's configuration cache at startup. Do not import the entire local deployment file over your existing Render environment.

Keep the canonical URLs set to `APP_URL=https://ticketing-system-full.onrender.com` and `FRONTEND_URL=https://ticketing-system-full.pages.dev`. Verification links use the signed backend URL and return to the Vue verification page.

Brevo supports port 2525; this SMTP connection uses automatic STARTTLS when the server offers it. See [Brevo SMTP setup](https://help.brevo.com/hc/en-us/articles/7924908994450-Send-transactional-emails-using-Brevo-SMTP) and [Render's port restrictions](https://render.com/docs/free#other-limitations).

### Authorize Render when SMTP IP blocking is enabled

If Brevo shows **Unauthorized IP addresses are blocked for your SMTP keys**, authorize the Render service's outbound ranges before resending:

1. In Render, open **ticketing-system-full → Connect → Outbound** and copy **all** listed CIDR ranges.
2. In Brevo, open **Settings → Security → Authorized IPs → Authorize IP addresses**. Paste the complete ranges, one per line, and authorize them.
3. Retry verification and check Brevo's transactional logs. An SMTP `525 5.7.1 Unauthorized IP address` identifies an IP restriction failure.

Brevo accepts CIDR ranges. Render can use any address within its listed outbound ranges; use those ranges rather than a single observed address. The `10.x`/`127.0.0.1` addresses in application access logs do not identify the service's public SMTP source. See [Render outbound IPs](https://render.com/docs/outbound-ip-addresses) and [Brevo authorized IPs](https://help.brevo.com/hc/en-us/articles/5740111683858-Authorize-and-block-IP-addresses-for-API-and-SMTP-security).

If an SMTP key has been shared in a screenshot, replace it in Brevo and update `MAIL_PASSWORD` in Render. Keep the replacement key private.

## 4. Publish the registration fixes

Run these commands in the project root to include the prepared backend, frontend, tests, deployment guides and Superadmin seed helper. The private `.env` and `.env.render.local` files are excluded:

```powershell
git add app/Models/User.php app/Providers/FortifyServiceProvider.php app/Http/Responses app/Services/VerificationEmail.php config/mail.php frontend-ticketing/src/api/http.js frontend-ticketing/src/stores/auth.js frontend-ticketing/src/views/auth/RegisterView.vue frontend-ticketing/src/views/auth/VerifyEmailView.vue frontend-ticketing/tests tests/Feature/Auth/RegistrationDeliveryTest.php scripts/seed-aiven.php scripts/seed-aiven.ps1 tests/Feature/AivenSuperadminSeedTest.php .env.example .env.render.example render.yaml DEPLOYMENT_START_HERE.md RENDER_DEPLOYMENT.md BREVO_SETUP.md
git diff --cached --stat
git commit -m "Fix registration and verification email delivery"
git push origin main
```

Wait for Render and Cloudflare Pages to deploy the new commit. If Render auto-deploy is disabled, select **Manual Deploy → Deploy latest commit** after the push.

The frontend also clears its cached CSRF token on logout and refreshes it once when a state-changing request receives HTTP 419. Test logout followed by sign-in without reloading, as well as verification resend. A 419 response rejects the request before it reaches the mail sender; it does not identify an SMTP problem. CSRF protection remains enabled.

## 5. Verify actual delivery

Sign in to the existing unverified account and select **Resend verification email**. Check the recipient inbox/spam folder and **Brevo → Transactional → Logs**. A successful provider response means Brevo accepted the message; confirm delivery in its logs and open the signed verification link.

If an earlier registration saved the account before reporting a mail error, use that account's existing password to sign in instead of registering the email again. After these fixes, mail failure shows a clear message while keeping the account unverified. Production passwords require at least 12 characters, uppercase/lowercase letters, numbers and symbols.

The local automated checks use a fake mail transport and SQLite memory. Real Brevo delivery must be confirmed after the SMTP credentials and approved sender are configured in Render. [Brevo delivery troubleshooting](https://help.brevo.com/hc/en-us/articles/115000188150-Troubleshooting-Issues-with-Brevo-SMTP).

## 6. Troubleshoot delivery and verification links

If Brevo logs show a sender such as `support@replace-verified-domain.example`, the active Render `MAIL_FROM_ADDRESS` is still a placeholder. Replace it with your verified Brevo sender, **Save and deploy**, and resend. The recipient is the email of the signed-in account shown on the Vue verification page.

If the email opens an old or nonexistent backend hostname, set `APP_URL=https://ticketing-system-full.onrender.com` and `FRONTEND_URL=https://ticketing-system-full.pages.dev` in Render, **Save and deploy**, then resend. The container refreshes Laravel's cached configuration at startup. Use the newest signed email link; changing an old link's hostname invalidates its signature. Do not publish the signed URL in logs or screenshots. [Laravel signed URLs](https://laravel.com/docs/13.x/urls#signed-urls).
