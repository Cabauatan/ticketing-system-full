import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'
import vm from 'node:vm'

async function loadClients(env) {
    const calls = []
    let interceptor
    let echoOptions
    let failure
    const client = {
        get: async (url) => { calls.push({ method: 'get', url }); return { data: { token: 'session-csrf-token' } } },
        post: async (url, data) => {
            const request = await interceptor({ method: 'post', headers: {} })
            calls.push({ method: 'post', url, data, headers: request.headers })
            if (failure) throw failure
            return { data: { auth: 'signed-private-channel' } }
        },
        interceptors: { request: { use: (handler) => { interceptor = handler } }, response: { use() {} } },
    }
    const context = vm.createContext({ window: { location: { hostname: 'frontend.pages.dev' } } })
    const axios = new vm.SyntheticModule(['default'], function () {
        this.setExport('default', { create: (config) => { client.config = config; return client } })
    }, { context })
    const echo = new vm.SyntheticModule(['default'], function () {
        this.setExport('default', class {
            constructor(options) { echoOptions = options }
            private() { return { listen() { return this } } }
        })
    }, { context })
    const pusher = new vm.SyntheticModule(['default'], function () { this.setExport('default', {}) }, { context })
    const auth = new vm.SyntheticModule(['useAuthStore'], function () {
        this.setExport('useAuthStore', () => ({ user: { id: 42 }, isStaff: false, can: () => false }))
    }, { context })
    const http = new vm.SourceTextModule(await readFile(new URL('../src/api/http.js', import.meta.url), 'utf8'), {
        context, initializeImportMeta: (meta) => { meta.env = env },
    })
    await http.link(() => axios)
    await http.evaluate()
    const realtime = new vm.SourceTextModule(await readFile(new URL('../src/realtime/echo.js', import.meta.url), 'utf8'), {
        context, initializeImportMeta: (meta) => { meta.env = env },
    })
    await realtime.link((specifier) => ({ 'laravel-echo': echo, 'pusher-js': pusher, '@/stores/auth': auth, '@/api/http': http })[specifier])
    await realtime.evaluate()
    return { client, calls, realtime: realtime.namespace, options: () => echoOptions, fail: (error) => { failure = error } }
}

test('local API requests retain the Vite same-origin proxy', async () => {
    const { client } = await loadClients({})
    assert.equal(client.config.baseURL, '')
    assert.equal(client.config.withCredentials, true)
})

test('production API origin takes precedence, trims trailing slash, and preserves legacy setting', async () => {
    const primary = await loadClients({ VITE_API_URL: 'https://api.onrender.com/', VITE_API_BASE_URL: 'https://legacy.example' })
    assert.equal(primary.client.config.baseURL, 'https://api.onrender.com')
    const legacy = await loadClients({ VITE_API_BASE_URL: 'https://legacy.example/' })
    assert.equal(legacy.client.config.baseURL, 'https://legacy.example')
})

test('production private Reverb authorization uses backend session CSRF and HTTPS socket settings', async () => {
    const fixture = await loadClients({ VITE_API_URL: 'https://api.onrender.com', VITE_REVERB_APP_KEY: 'public-key', VITE_REVERB_HOST: 'api.onrender.com', VITE_REVERB_PORT: '443', VITE_REVERB_SCHEME: 'https' })
    fixture.realtime.connectRealtime()
    const options = fixture.options()
    assert.equal(options.wsHost, 'api.onrender.com')
    assert.equal(options.wssPort, 443)
    assert.equal(options.forceTLS, true)
    const result = await new Promise((resolve, reject) => {
        options.channelAuthorization.customHandler({ socketId: '123.456', channelName: 'private-workspace.user.42' }, (error, data) => error ? reject(error) : resolve(data))
    })
    assert.equal(result.auth, 'signed-private-channel')
    assert.equal(fixture.calls[0].url, '/api/csrf-token')
    assert.equal(fixture.calls[1].url, '/broadcasting/auth')
    assert.equal(fixture.calls[1].headers['X-CSRF-TOKEN'], 'session-csrf-token')
    assert.equal(fixture.calls[1].data.channel_name, 'private-workspace.user.42')
    const denied = new Error('Unauthenticated')
    fixture.fail(denied)
    const rejected = await new Promise((resolve) => options.channelAuthorization.customHandler({ socketId: '123.456', channelName: 'private-workspace.staff' }, (error, data) => resolve({ error, data })))
    assert.equal(rejected.error, denied)
    assert.equal(rejected.data, null)
})
