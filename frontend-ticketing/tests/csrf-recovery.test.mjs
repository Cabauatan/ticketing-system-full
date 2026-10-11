import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'
import vm from 'node:vm'

const expired = () => Object.assign(new Error('CSRF token mismatch.'), { response: { status: 419 } })
const deferred = () => {
    let resolve
    const promise = new Promise((done) => { resolve = done })
    return { promise, resolve }
}

async function loadClients(dispatch) {
    const calls = []
    let onRequest
    let onError
    const client = {
        async request(input) {
            const config = await onRequest({ ...input, headers: { ...input.headers } })
            calls.push(config)
            try {
                return await dispatch(config)
            } catch (error) {
                error.config = config
                return onError(error)
            }
        },
        get(url, config = {}) { return this.request({ ...config, method: 'get', url }) },
        post(url, data, config = {}) { return this.request({ ...config, method: 'post', url, data }) },
        interceptors: {
            request: { use(handler) { onRequest = handler } },
            response: { use(_success, handler) { onError = handler } },
        },
    }
    const context = vm.createContext({})
    const axios = new vm.SyntheticModule(['default'], function () {
        this.setExport('default', { create: () => client })
    }, { context })
    const http = new vm.SourceTextModule(await readFile(new URL('../src/api/http.js', import.meta.url), 'utf8'), {
        context, initializeImportMeta(meta) { meta.env = {} },
    })
    await http.link(() => axios)
    await http.evaluate()
    const pinia = new vm.SyntheticModule(['defineStore'], function () {
        this.setExport('defineStore', (_id, definition) => () => {
            const store = definition.state()
            for (const [name, action] of Object.entries(definition.actions)) store[name] = action.bind(store)
            for (const [name, getter] of Object.entries(definition.getters)) {
                Object.defineProperty(store, name, { get: () => getter(store) })
            }
            return store
        })
    }, { context })
    const auth = new vm.SourceTextModule(await readFile(new URL('../src/stores/auth.js', import.meta.url), 'utf8'), { context })
    await auth.link((specifier) => ({ pinia, '@/api/http': http })[specifier])
    await auth.evaluate()
    return { client, calls, csrf: http.namespace, auth: auth.namespace.useAuthStore() }
}

test('logout discards the rotated session token before the next sign-in', async () => {
    let token = 'before-logout'
    const fixture = await loadClients(async (config) => {
        if (config.url === '/api/csrf-token') return { data: { token } }
        if (config.url === '/api/me') return { data: { data: { user: { id: 3, email: 'user@example.test', role: 'requester' }, email_verified: false } } }
        if (config.headers['X-CSRF-TOKEN'] !== token) throw expired()
        if (config.url === '/logout') token = 'after-logout'
        return { data: {} }
    })
    await fixture.csrf.ensureCsrf()
    await fixture.auth.signOut()
    await fixture.auth.signIn({ email: 'user@example.test', password: 'ExamplePassword_2026' })
    const logins = fixture.calls.filter((call) => call.url === '/login')
    assert.equal(logins.length, 1)
    assert.equal(logins[0].headers['X-CSRF-TOKEN'], 'after-logout')
    assert.equal(fixture.auth.authenticated, true)
})

test('an expired resend token is refreshed and retried once before sending email', async () => {
    let token = 'old-session'
    let deliveries = 0
    const fixture = await loadClients(async (config) => {
        if (config.url === '/api/csrf-token') return { data: { token } }
        if (config.headers['X-CSRF-TOKEN'] !== token) throw expired()
        deliveries += 1
        return { data: { verification: { status: 'sent' } } }
    })
    await fixture.csrf.ensureCsrf()
    token = 'new-session'
    await fixture.auth.resendVerification()
    const resends = fixture.calls.filter((call) => call.url === '/email/verification-notification')
    assert.deepEqual(resends.map((call) => call.headers['X-CSRF-TOKEN']), ['old-session', 'new-session'])
    assert.equal(deliveries, 1)
    assert.equal(fixture.auth.verificationDelivery.status, 'sent')
})

test('a second 419 stops recovery instead of repeatedly replaying a mutation', async () => {
    const fixture = await loadClients(async (config) => {
        if (config.url === '/api/csrf-token') return { data: { token: 'still-rejected' } }
        throw expired()
    })
    await assert.rejects(fixture.client.post('/login', {}), (error) => error.response.status === 419)
    assert.equal(fixture.calls.filter((call) => call.url === '/login').length, 2)
    assert.equal(fixture.calls.filter((call) => call.url === '/api/csrf-token').length, 2)
})

test('GET failures and non-CSRF mutation failures are not retried', async () => {
    const fixture = await loadClients(async (config) => {
        if (config.url === '/api/csrf-token') return { data: { token: 'current-token' } }
        if (config.method === 'get') throw expired()
        throw Object.assign(new Error('Mail unavailable.'), { response: { status: 503 } })
    })
    await assert.rejects(fixture.client.get('/api/me'), (error) => error.response.status === 419)
    assert.equal(fixture.calls.length, 1)
    await assert.rejects(fixture.client.post('/email/verification-notification'), (error) => error.response.status === 503)
    assert.equal(fixture.calls.filter((call) => call.url === '/email/verification-notification').length, 1)
})

test('a failed token refresh leaves the rejected mutation unreplayed', async () => {
    let fetches = 0
    const unavailable = new Error('Token endpoint unavailable.')
    const fixture = await loadClients(async (config) => {
        if (config.url === '/api/csrf-token') {
            if (++fetches === 1) return { data: { token: 'expired-token' } }
            throw unavailable
        }
        throw expired()
    })
    await assert.rejects(fixture.client.post('/login'), (error) => error === unavailable)
    assert.equal(fixture.calls.filter((call) => call.url === '/login').length, 1)
})

test('concurrent rejected mutations share one token refresh', { timeout: 5000 }, async () => {
    const refresh = deferred()
    const bothRejected = deferred()
    let fetches = 0
    let rejected = 0
    const fixture = await loadClients(async (config) => {
        if (config.url === '/api/csrf-token') {
            if (++fetches === 1) return { data: { token: 'old-token' } }
            await refresh.promise
            return { data: { token: 'fresh-token' } }
        }
        if (config.headers['X-CSRF-TOKEN'] === 'old-token') {
            if (++rejected === 2) bothRejected.resolve()
            throw expired()
        }
        return { data: {} }
    })
    await fixture.csrf.ensureCsrf()
    const requests = Promise.all([fixture.client.post('/first'), fixture.client.post('/second')])
    await bothRejected.promise
    refresh.resolve()
    await requests
    assert.equal(fetches, 2)
    assert.equal(fixture.calls.filter((call) => call.method === 'post').length, 4)
})

test('a delayed rejection uses the token refreshed by another request', { timeout: 5000 }, async () => {
    const delayed = deferred()
    let fetches = 0
    const fixture = await loadClients(async (config) => {
        if (config.url === '/api/csrf-token') return { data: { token: ++fetches === 1 ? 'old-token' : 'fresh-token' } }
        if (config.headers['X-CSRF-TOKEN'] === 'old-token') {
            if (config.url === '/delayed') await delayed.promise
            throw expired()
        }
        return { data: {} }
    })
    await fixture.csrf.ensureCsrf()
    const first = fixture.client.post('/first')
    const second = fixture.client.post('/delayed')
    await first
    delayed.resolve()
    await second
    assert.equal(fetches, 2)
})

test('a token fetch started before logout cannot overwrite the next session token', { timeout: 5000 }, async () => {
    const delayed = deferred()
    const started = deferred()
    let fetches = 0
    const fixture = await loadClients(async () => {
        if (++fetches === 1) {
            started.resolve()
            await delayed.promise
            return { data: { token: 'old-token' } }
        }
        return { data: { token: 'new-token' } }
    })
    const pending = fixture.csrf.ensureCsrf()
    await started.promise
    fixture.csrf.resetCsrf()
    assert.equal(await fixture.csrf.ensureCsrf(), 'new-token')
    delayed.resolve()
    assert.equal(await pending, 'new-token')
    assert.equal(await fixture.csrf.ensureCsrf(), 'new-token')
    assert.equal(fetches, 2)
})
