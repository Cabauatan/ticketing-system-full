import { mkdir, writeFile } from 'node:fs/promises'
import { setTimeout as delay } from 'node:timers/promises'

const backend = 'https://ticketing-system-full.onrender.com'
const frontend = 'https://ticketing-system-full.pages.dev'
const preview = 'https://2791923a.ticketing-system-full.pages.dev'
const output = new URL('./', import.meta.url)
const results = []
let cookies = ''
let csrfToken = ''
let nextRequestAt = 0

async function request(name, url, options = {}) {
    await delay(Math.max(0, nextRequestAt - Date.now()))
    nextRequestAt = Date.now() + 3500
    const started = Date.now()
    try {
        const response = await fetch(url, {
            redirect: 'manual',
            signal: AbortSignal.timeout(45000),
            ...options,
            headers: { Accept: 'application/json', ...options.headers },
        })
        const body = await response.text()
        const entry = {
            name, method: options.method || 'GET', url, status: response.status,
            durationMs: Date.now() - started,
            contentType: response.headers.get('content-type'),
            cors: {
                origin: response.headers.get('access-control-allow-origin'),
                credentials: response.headers.get('access-control-allow-credentials'),
                methods: response.headers.get('access-control-allow-methods'),
                headers: response.headers.get('access-control-allow-headers'),
                exposed: response.headers.get('access-control-expose-headers'),
            },
        }
        if (name === 'CSRF bootstrap') {
            const setCookies = response.headers.getSetCookie()
            cookies = setCookies.map(cookie => cookie.split(';')[0]).join('; ')
            entry.cookieAttributes = setCookies.map(cookie => {
                const [pair, ...attributes] = cookie.split(';')
                return { name: pair.split('=')[0], attributes: attributes.map(value => value.trim()) }
            })
            try { csrfToken = JSON.parse(body).token || '' } catch {}
            entry.tokenPresent = Boolean(csrfToken)
        }
        if (name === 'Frontend HTML') {
            const match = body.match(/<script[^>]*src="([^"]+index-[^"]+\.js)"/)
            entry.entryScript = match ? new URL(match[1], frontend).href : null
        }
        if (name === 'Backend root') {
            try { entry.message = JSON.parse(body).message } catch {}
            entry.server = response.headers.get('server')
            entry.hsts = response.headers.get('strict-transport-security')
        }
        if (name.startsWith('Protected:')) {
            try { entry.message = JSON.parse(body).message } catch {}
            entry.pass = [401, 403].includes(response.status)
        }
        if (name.startsWith('CORS:')) {
            const expectedOrigin = name.includes('production') ? frontend : name.includes('preview') ? preview : null
            entry.pass = expectedOrigin
                ? response.status === 204 && entry.cors.origin === expectedOrigin && entry.cors.credentials === 'true'
                : !entry.cors.origin
        }
        results.push(entry)
        console.log(JSON.stringify(entry))
        return { entry, body }
    } catch (error) {
        const entry = { name, method: options.method || 'GET', url, error: error.name, durationMs: Date.now() - started, pass: false }
        results.push(entry)
        console.log(JSON.stringify(entry))
        return { entry, body: '' }
    }
}

await request('Backend root', `${backend}/`)
await request('Backend health', `${backend}/up`)
const html = await request('Frontend HTML', `${frontend}/`, { headers: { Accept: 'text/html' } })
await request('CSRF bootstrap', `${backend}/api/csrf-token`, { headers: { Origin: frontend } })
for (const [name, origin] of [['production', frontend], ['preview', preview], ['untrusted origin', 'https://untrusted.example']]) {
    await request(`CORS: ${name}`, `${backend}/email/verification-notification`, {
        method: 'OPTIONS',
        headers: { Origin: origin, 'Access-Control-Request-Method': 'POST', 'Access-Control-Request-Headers': 'Content-Type,X-CSRF-TOKEN' },
    })
}

const protectedPaths = [
    '/api/me', '/api/user', '/api/dashboard', '/api/permissions',
    '/api/tickets', '/api/tickets/1', '/api/tickets/1/chat', '/api/tickets/1/assets',
    '/api/inventory', '/api/inventory/1', '/api/inventory/1/attachments/1',
    '/api/reports', '/api/reports/export.xlsx', '/api/reports/inventory',
    '/api/reports/inventory.xlsx', '/api/reports/tickets.xlsx', '/api/inventory/export.xlsx',
    '/api/admin', '/api/admin/permissions', '/api/settings/profile',
    '/api/settings/profile/avatar', '/api/settings/security', '/api/notifications',
]
for (const path of protectedPaths) {
    await request(`Protected: ${path}`, `${backend}${path}`, { headers: { Origin: frontend, Cookie: cookies } })
}
if (html.entry.entryScript) {
    const bundle = await request('Frontend entry bundle', html.entry.entryScript, { headers: { Accept: 'text/javascript' } })
    // Keep deployed public code locally for investigating real-time configuration;
    // no browser credentials, CSRF tokens, or session cookie values are saved.
    await writeFile(new URL('deployed-entry-bundle.js', output), bundle.body)
    const match = bundle.body.match(/broadcaster:["']reverb["'],key:["']([^"']+)["']/)
    if (match) await writeFile(new URL('reverb-public-key.tmp', output), match[1])
}
const report = {
    auditedAtUtc: new Date().toISOString(),
    timezone: 'Asia/Singapore', backend, frontend,
    scope: 'Live read-only public HTTP and unauthenticated access controls. No email sent, authenticated writes, database changes, or load testing.',
    maxRequestRatePerMinute: 18,
    results,
    limitations: ['Authenticated CRUD, role-specific access, real-time private subscriptions, email inbox delivery, and Excel content require separate authenticated checks.'],
}
await mkdir(output, { recursive: true })
await writeFile(new URL('public-http-results-2026-10-11.json', output), `${JSON.stringify(report, null, 2)}\n`)
const lines = [
    '# Live public deployment audit — 11 October 2026', '',
    `Recorded UTC: ${report.auditedAtUtc}. User timezone: Asia/Singapore.`, '',
    report.scope, '',
    '| Check | Result | Evidence |', '| --- | --- | --- |',
    ...results.map(entry => `| ${entry.name} | ${entry.error || `HTTP ${entry.status}`} | ${entry.pass === false ? 'Requires investigation' : entry.pass === true ? 'Passed' : entry.entryScript ? entry.entryScript.split('/').pop() : entry.tokenPresent ? 'CSRF token present; values omitted' : 'Observed live'} |`),
    '', '## Scope limits', '', ...report.limitations.map(value => `- ${value}`), '',
    'Cookie values and CSRF tokens were kept in memory only. Request spacing was at least 3.5 seconds.', '',
]
await writeFile(new URL('public-api.md', output), `${lines.join('\n').trimEnd()}\n`)
