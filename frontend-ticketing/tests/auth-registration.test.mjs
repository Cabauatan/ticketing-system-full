import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'
import vm from 'node:vm'

async function loadAuth({ registration, resend, session, refreshFailure } = {}) {
    const calls = []
    const client = {
        async post(url) {
            calls.push(url)
            const result = url === '/register' ? registration : resend
            if (result instanceof Error) throw result
            return { data: result }
        },
        async get(url) {
            calls.push(url)
            if (session instanceof Error) throw session
            return { data: session || { data: { user: { id: 23, email: 'new@example.test', role: 'requester' }, email_verified: false } } }
        },
    }
    const context = vm.createContext({})
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
    const http = new vm.SyntheticModule(['default', 'ensureCsrf', 'refreshCsrf', 'resetCsrf'], function () {
        this.setExport('default', client)
        this.setExport('ensureCsrf', async () => 'csrf-token')
        this.setExport('refreshCsrf', async () => {
            calls.push('refreshCsrf')
            if (refreshFailure) throw refreshFailure
        })
        this.setExport('resetCsrf', () => { calls.push('resetCsrf') })
    }, { context })
    const module = new vm.SourceTextModule(await readFile(new URL('../src/stores/auth.js', import.meta.url), 'utf8'), { context })
    await module.link((specifier) => ({ pinia, '@/api/http': http })[specifier])
    await module.evaluate()
    return { auth: module.namespace.useAuthStore(), calls, setSession: (next) => { session = next } }
}

test('registration retains successful delivery and restores the unverified account session', async () => {
    const { auth, calls } = await loadAuth({ registration: { verification: { status: 'sent', message: 'Verification email sent.' } } })
    assert.equal(await auth.register({ email: 'new@example.test' }), true)
    assert.equal(auth.authenticated, true)
    assert.equal(auth.verificationDelivery.status, 'sent')
    assert.equal(auth.verificationDelivery.message, 'Verification email sent.')
    assert.deepEqual(calls, ['/register', 'refreshCsrf', '/api/me'])
})

test('mail delivery failure does not turn a successful account registration into an error', async () => {
    const { auth } = await loadAuth({ registration: { verification: { status: 'unavailable', message: 'Mail delivery is unavailable.' } } })
    assert.equal(await auth.register({ email: 'new@example.test' }), true)
    assert.equal(auth.authenticated, true)
    assert.equal(auth.verificationDelivery.status, 'unavailable')
    assert.equal(auth.verificationDelivery.message, 'Mail delivery is unavailable.')
})

test('validation errors remain available to the registration form without a created-account flag', async () => {
    const failure = Object.assign(new Error('Validation failed.'), { response: { status: 422, data: { errors: { email: ['The email has already been taken.'] } } } })
    const { auth, calls } = await loadAuth({ registration: failure })
    await assert.rejects(auth.register({}), (error) => error === failure && !error.accountCreated)
    assert.deepEqual(calls, ['/register'])
    assert.equal(auth.verificationDelivery, null)
})

test('a failed CSRF refresh after account creation explicitly directs the user to sign in', async () => {
    const { auth } = await loadAuth({ registration: { verification: { status: 'sent' } }, refreshFailure: new Error('Network unavailable.') })
    await assert.rejects(auth.register({}), (error) => error.accountCreated === true && /account was created/.test(error.message) && /Sign in/.test(error.message))
    assert.equal(auth.verificationDelivery.status, 'sent')
})

test('a missing session after account creation explicitly directs the user to sign in', async () => {
    const unauthorized = Object.assign(new Error('Unauthenticated.'), { response: { status: 401 } })
    const { auth } = await loadAuth({ registration: { verification: { status: 'unavailable' } }, session: unauthorized })
    await assert.rejects(auth.register({}), (error) => error.accountCreated === true && /Sign in/.test(error.message))
    assert.equal(auth.authenticated, false)
    assert.equal(auth.verificationDelivery, null)
})

test('an expired session clears delivery state before a different account signs in', async () => {
    const unauthorized = Object.assign(new Error('Unauthenticated.'), { response: { status: 401 } })
    const fixture = await loadAuth({ session: unauthorized })
    fixture.auth.user = { id: 23, email: 'first@example.test', role: 'requester' }
    fixture.auth.setVerificationDelivery({ status: 'unavailable', message: 'Delivery failed for the first account.' })
    await fixture.auth.restoreSession()
    assert.equal(fixture.auth.verificationDelivery, null)
    fixture.setSession({ data: { user: { id: 24, email: 'second@example.test', role: 'requester' }, email_verified: false } })
    await fixture.auth.signIn({ email: 'second@example.test', password: 'ExamplePassword_2026' })
    assert.equal(fixture.auth.user.id, 24)
    assert.equal(fixture.auth.verificationDelivery, null)
})

test('restoring another account clears a delivery warning associated with a registration email', async () => {
    const { auth } = await loadAuth({ session: { data: { user: { id: 24, email: 'second@example.test', role: 'requester' }, email_verified: false } } })
    auth.setVerificationDelivery({ status: 'unavailable', message: 'Delivery failed for the first account.' }, 'first@example.test')
    await auth.restoreSession()
    assert.equal(auth.user.email, 'second@example.test')
    assert.equal(auth.verificationDelivery, null)
})

test('restoring the same registration email preserves its verification warning', async () => {
    const { auth } = await loadAuth()
    auth.setVerificationDelivery({ status: 'unavailable', message: 'Delivery failed for this account.' }, ' NEW@example.test ')
    await auth.restoreSession()
    assert.equal(auth.verificationDelivery.email, 'new@example.test')
    assert.equal(auth.verificationDelivery.status, 'unavailable')
    assert.equal(auth.verificationDelivery.message, 'Delivery failed for this account.')
})

test('resend preserves unavailable delivery status and propagates the server error', async () => {
    const failure = Object.assign(new Error('Service unavailable.'), { response: { status: 503, data: { verification: { status: 'unavailable', message: 'Try again later.' } } } })
    const { auth } = await loadAuth({ resend: failure })
    auth.setVerificationDelivery({ status: 'sent', message: 'Previously sent.' })
    await assert.rejects(auth.resendVerification(), (error) => error === failure)
    assert.equal(auth.verificationDelivery.status, 'unavailable')
    assert.equal(auth.verificationDelivery.message, 'Try again later.')
})

test('resend replaces an unavailable delivery status after success', async () => {
    const response = { verification: { status: 'sent', message: 'A new link was sent.' } }
    const { auth } = await loadAuth({ resend: response })
    auth.setVerificationDelivery({ status: 'unavailable' })
    assert.equal(await auth.resendVerification(), response)
    assert.equal(auth.verificationDelivery.status, 'sent')
})

test('an old backend response leaves delivery unknown instead of claiming email was sent', async () => {
    const { auth } = await loadAuth({ registration: '' })
    await auth.register({})
    assert.equal(auth.verificationDelivery, null)
    auth.setVerificationDelivery({ status: 'delivered', message: 'Unsupported state.' })
    assert.equal(auth.verificationDelivery, null)
    auth.setVerificationDelivery({ status: 'sent' })
    await auth.signOut()
    assert.equal(auth.verificationDelivery, null)
})

test('logout clears the cached token and account state even when the server request fails', async () => {
    const failure = new Error('Network unavailable.')
    const { auth, calls } = await loadAuth({ resend: failure })
    auth.user = { id: 23, email: 'new@example.test' }
    auth.setVerificationDelivery({ status: 'sent' })
    await assert.rejects(auth.signOut(), (error) => error === failure)
    assert.deepEqual(calls, ['/logout', 'resetCsrf'])
    assert.equal(auth.authenticated, false)
    assert.equal(auth.verificationDelivery, null)
})
