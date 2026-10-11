import { readFile, writeFile, unlink } from 'node:fs/promises'
import { request as httpsRequest } from 'node:https'
import { randomBytes, createHash } from 'node:crypto'
import { setTimeout as delay } from 'node:timers/promises'

const backend = 'https://ticketing-system-full.onrender.com'
const frontend = 'https://ticketing-system-full.pages.dev'
const chunkUrl = `${frontend}/assets/echo-CT95tPY7.js`
const directory = new URL('./', import.meta.url)
const response = await fetch(chunkUrl, { signal: AbortSignal.timeout(30000) })
const source = await response.text()
const config = source.match(/broadcaster\s*:\s*(["'`])reverb\1.{0,1200}/)?.[0] || ''
const importedChunks = [...source.matchAll(/(?:from|import)\s*\(?["'`]([^"'`]+\.js)["'`]/g)].map(match => match[1])
const literal = name => {
    const match = config.match(new RegExp(`${name}\\s*:\\s*(["'\x60])([^"'\x60]+)\\1`))
    return match?.[2]
}
const key = literal('key')
const host = literal('wsHost')
const portMatch = config.match(/(?:wsPort|wssPort)\s*:\s*(?:Number\()?([0-9]+)/)
const report = {
    auditedAtUtc: new Date().toISOString(), chunkUrl,
    chunkStatus: response.status,
    chunkSha256: createHash('sha256').update(source).digest('hex'),
    reverbConfigurationFound: Boolean(config), publicKeyPresent: Boolean(key),
    sourceMarkers: {
        broadcaster: source.includes('broadcaster'), reverb: source.includes('reverb'),
        pusher: source.toLowerCase().includes('pusher'), wsHost: source.includes('wsHost'),
        supportdeskRefresh: source.includes('supportdesk:refresh'),
    },
    importedChunks,
    host: host || null, port: portMatch ? Number(portMatch[1]) : null,
    secureTransport: config ? /forceTLS\s*:\s*(?:true|!0)/.test(config) : null,
    initializationCompiledToNoop: /function r\(\)\{return n\}/.test(source) && /r as t/.test(source),
    websocketHandshake: null,
    limitations: ['A successful public handshake does not test authenticated private channel authorization or published events.'],
}
if (key) {
    await delay(3500)
    const websocketUrl = new URL(`/app/${encodeURIComponent(key)}?protocol=7&client=js&version=8.4.0&flash=false`, backend)
    report.websocketHandshake = await new Promise(resolve => {
        let done = false
        let timer
        const finish = value => { if (done) return; done = true; clearTimeout(timer); resolve(value) }
        const request = httpsRequest(websocketUrl, { headers: {
            Origin: frontend,
            Connection: 'Upgrade', Upgrade: 'websocket',
            'Sec-WebSocket-Version': '13', 'Sec-WebSocket-Key': randomBytes(16).toString('base64'),
        } })
        timer = setTimeout(() => { request.destroy(); finish({ error: 'Timed out' }) }, 15000)
        request.on('upgrade', (response, socket, head) => {
            const handshake = { status: response.statusCode, upgrade: response.headers.upgrade, connectionEstablishedEvent: false }
            const process = bytes => {
                if (bytes.length < 2) return
                const initialLength = bytes[1] & 127
                const offset = initialLength === 126 ? 4 : initialLength === 127 ? 10 : 2
                const payload = bytes.subarray(offset).toString('utf8')
                handshake.connectionEstablishedEvent = payload.includes('pusher:connection_established')
                socket.end(Buffer.from([0x88, 0x80, 0, 0, 0, 0]))
                socket.destroy()
                finish(handshake)
            }
            if (head.length) process(head)
            else socket.once('data', process)
            socket.on('error', () => finish(handshake))
            socket.on('close', () => finish(handshake))
        })
        request.on('response', response => {
            response.resume()
            finish({ status: response.statusCode, upgrade: response.headers.upgrade || null })
        })
        request.on('error', error => finish({ error: error.code || error.name }))
        request.end()
    })
}
await writeFile(new URL('public-reverb-results-2026-10-11.json', directory), `${JSON.stringify(report, null, 2)}\n`)
const existingReport = await readFile(new URL('public-api.md', directory), 'utf8')
await writeFile(new URL('public-api.md', directory), existingReport.split('\n## Live Reverb handshake')[0] + [
    '\n## Live Reverb handshake', '',
    `Deployed real-time chunk: \`${chunkUrl.split('/').pop()}\`, HTTP ${report.chunkStatus}.`,
    `Inline Reverb configuration present: ${report.reverbConfigurationFound}. Public application key present: ${report.publicKeyPresent} (value omitted).`,
    `WebSocket host: ${report.host || 'not extracted'}. Port: ${report.port || 'not extracted'}.`,
    `Handshake: ${report.websocketHandshake ? JSON.stringify(report.websocketHandshake) : 'BLOCKED — no active Reverb client configuration is present in the deployed bundle.'}`, '',
    report.initializationCompiledToNoop
        ? 'LIVE DEFECT: the deployed connectRealtime export compiles to `function r(){return n}` and never constructs an Echo client. The source guard returns early when VITE_REVERB_APP_KEY is absent, so the Cloudflare build environment must be checked and rebuilt with the public Reverb application key. Use the canonical backend host, HTTPS scheme, and port 443. This is separate from the current backend HTTP 500 failure.'
        : 'No definite initialization defect was determined from this chunk.', '',
    report.limitations[0], '',
].join('\n'))
console.log(JSON.stringify(report))
// Remove incidental downloaded public code, leaving only sanitized evidence.
await unlink(new URL('deployed-entry-bundle.js', directory)).catch(() => {})
await unlink(new URL('reverb-public-key.tmp', directory)).catch(() => {})
