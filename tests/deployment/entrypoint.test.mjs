import assert from 'node:assert/strict'
import { spawnSync } from 'node:child_process'
import fs from 'node:fs'
import os from 'node:os'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import { test } from 'node:test'

const projectRoot = fileURLToPath(new URL('../../', import.meta.url))
const entrypoint = fs.readFileSync(path.join(projectRoot, 'docker/entrypoint.sh'), 'utf8')
const bash = process.env.BASH_TEST_BINARY || (process.platform === 'win32'
  ? 'C:/Program Files/Git/bin/bash.exe'
  : 'bash')
const guardMarker = '# These paths contain only runtime data.'
assert.ok(entrypoint.includes(guardMarker), 'The guard must stop before application startup')
const shellQuote = (value) => "'" + value.replaceAll("'", "'\\''") + "'"
const shellPath = (value) => value.replaceAll('\\', '/')

function probe(values = {}) {
  const fixtureRoot = fs.mkdtempSync(path.join(os.tmpdir(), 'ticketing-ca-test-'))
  const certificate = path.join(fixtureRoot, 'source ca.pem')
  const runtimeDirectory = path.join(fixtureRoot, 'private runtime')
  const logPath = path.join(fixtureRoot, 'operations.log')
  const source = '-----BEGIN CERTIFICATE-----\nsynthetic-test-certificate\n-----END CERTIFICATE-----\n'
  fs.writeFileSync(certificate, source)
  fs.writeFileSync(path.join(fixtureRoot, 'empty.pem'), '')
  fs.writeFileSync(logPath, '')

  try {
    // Simulate worker access only; Linux ownership requires a real container.
    // Execute the production guard verbatim, changing only its fixed directories.
    const guard = entrypoint.slice(0, entrypoint.indexOf(guardMarker))
      .replace('cd /var/www/html', `cd ${shellQuote(shellPath(fixtureRoot))}`)
      .replaceAll('/run/ticketing-mysql-ca', shellQuote(shellPath(runtimeDirectory)))
    const mocks = `
su-exec() {
    [ "$1" = www-data ] || return 91
    shift
    if [ "$1" = test ] && [ "$2" = -r ]; then
        if [ "$3" = "$TEST_SOURCE_CA" ] && [ "$TEST_WORKER_READABLE" = false ]; then return 1; fi
        if [ "$3" != "$TEST_SOURCE_CA" ] && [ "$TEST_STAGE_READABLE" = false ]; then return 1; fi
    fi
    "$@"
}
chown() { printf 'chown %s %s\\n' "$1" "$2" >> "$TEST_OPERATIONS"; }
chmod() { printf 'chmod %s %s\\n' "$1" "$2" >> "$TEST_OPERATIONS"; command chmod "$@"; }
cp() {
    if [ "$TEST_COPY_FAILURE" = true ]; then return 1; fi
    command cp "$@"
}
`
    const outputProbe = `
printf 'RESULT_DB=%s\\n' "\${DB_SSL_CA-}"
printf 'RESULT_ALIAS=%s\\n' "\${MYSQL_ATTR_SSL_CA-}"
bash --noprofile --norc -c 'printf "RESULT_CHILD=%s|%s\\n" "\${DB_SSL_CA-}" "\${MYSQL_ATTR_SSL_CA-}"'
`
    const script = path.join(fixtureRoot, 'guard.sh')
    fs.writeFileSync(script, guard.replace('set -eu', `set -eu\n${mocks}`) + outputProbe)
    const environment = { ...process.env, APP_KEY: 'temporary-test-key', APP_ENV: 'production',
      APP_DEBUG: 'false', PORT: '10000', DB_CONNECTION: 'mysql',
      TEST_SOURCE_CA: shellPath(certificate), TEST_WORKER_READABLE: 'true',
      TEST_STAGE_READABLE: 'true', TEST_COPY_FAILURE: 'false', TEST_OPERATIONS: shellPath(logPath) }
    delete environment.DB_SSL_CA
    delete environment.MYSQL_ATTR_SSL_CA
    for (const [key, value] of Object.entries(values)) {
      environment[key] = String(value)
        .replaceAll('{CA}', shellPath(certificate))
        .replaceAll('{ROOT}', shellPath(fixtureRoot))
    }
    const result = spawnSync(bash, ['--noprofile', '--norc', shellPath(script)], {
      env: environment, encoding: 'utf8', timeout: 10000,
    })
    assert.ifError(result.error)
    const output = result.stdout + result.stderr
    const dbPath = /^RESULT_DB=(.*)$/m.exec(result.stdout)?.[1]
    const aliasPath = /^RESULT_ALIAS=(.*)$/m.exec(result.stdout)?.[1]
    const child = /^RESULT_CHILD=(.*)$/m.exec(result.stdout)?.[1]
    const staged = dbPath && dbPath !== shellPath(certificate)
      && fs.existsSync(dbPath) && fs.statSync(dbPath).isFile()
      ? fs.readFileSync(dbPath, 'utf8') : null
    return { status: result.status, output, dbPath, aliasPath, child, staged,
      source, sourceUnchanged: fs.readFileSync(certificate, 'utf8') === source,
      operations: fs.readFileSync(logPath, 'utf8'), certificate: shellPath(certificate),
      runtimeDirectory: shellPath(runtimeDirectory) }
  } finally {
    // Verify the resolved target remains within the dedicated test temp directory.
    const tempRoot = fs.realpathSync(os.tmpdir())
    const actual = fs.realpathSync(fixtureRoot)
    const relative = path.relative(tempRoot, actual)
    assert.ok(relative && !relative.startsWith('..') && !path.isAbsolute(relative))
    assert.ok(path.basename(actual).startsWith('ticketing-ca-test-'))
    fs.rmSync(actual, { recursive: true, force: true })
  }
}

test('startup guards reject unsafe settings before application startup', () => {
  for (const [label, values] of [
    ['missing APP_KEY', { APP_KEY: '' }],
    ['non-numeric port', { PORT: 'abc' }],
    ['zero port', { PORT: '0' }],
    ['out-of-range port', { PORT: '65536' }],
    ['production debug', { APP_DEBUG: 'true' }],
  ]) {
    const result = probe(values)
    assert.equal(result.status, 1, label)
    assert.match(result.output, /Startup error:/, label)
    assert.equal(result.operations, '', label)
  }
})

test('missing, directory and empty CA inputs produce specific configuration errors', () => {
  for (const [values, expected] of [
    [{ DB_SSL_CA: '{ROOT}/missing.pem' }, /DB_SSL_CA does not exist/],
    [{ DB_SSL_CA: '{ROOT}' }, /DB_SSL_CA does not exist/],
    [{ DB_SSL_CA: '{ROOT}/empty.pem' }, /DB_SSL_CA is empty/],
    [{ DB_SSL_CA: '{CA}', MYSQL_ATTR_SSL_CA: '{ROOT}/missing-alias.pem' }, /MYSQL_ATTR_SSL_CA does not exist/],
  ]) {
    const result = probe(values)
    assert.equal(result.status, 1)
    assert.match(result.output, expected)
    assert.equal(result.operations, '')
  }
})

test('readable CA paths with spaces are preserved and an unset CA needs no copy', () => {
  const empty = probe()
  assert.equal(empty.status, 0)
  assert.equal(empty.operations, '')
  const readable = probe({ DB_SSL_CA: '{CA}' })
  assert.equal(readable.status, 0)
  assert.equal(readable.dbPath, readable.certificate)
  assert.equal(readable.operations, '')
  assert.equal(readable.child, `${readable.certificate}|`)
})

test('worker-restricted CA is staged intact and exported to child processes', () => {
  for (const values of [
    { DB_SSL_CA: '{CA}' },
    { MYSQL_ATTR_SSL_CA: '{CA}', DB_SSL_CA: '{ROOT}/unused.pem' },
  ]) {
    const result = probe({ ...values, TEST_WORKER_READABLE: 'false' })
    assert.equal(result.status, 0, result.output)
    assert.equal(result.staged, result.source)
    assert.equal(result.sourceUnchanged, true)
    assert.ok(result.dbPath.startsWith(`${result.runtimeDirectory}/ca.`))
    assert.equal(result.aliasPath, result.dbPath)
    assert.equal(result.child, `${result.dbPath}|${result.dbPath}`)
    assert.match(result.operations, /chmod 0750 /)
    assert.match(result.operations, /chmod 0640 /)
    assert.equal((result.operations.match(/chown root:www-data /g) || []).length, 2)
    assert.match(result.output, /Prepared the database CA certificate/)
  }
})

test('failed CA copy or unreadable staged CA stops startup', () => {
  for (const [values, expected] of [
    [{ TEST_COPY_FAILURE: 'true' }, /Could not prepare the database CA/],
    [{ TEST_STAGE_READABLE: 'false' }, /runtime database CA certificate is unreadable/],
  ]) {
    const result = probe({ DB_SSL_CA: '{CA}', TEST_WORKER_READABLE: 'false', ...values })
    assert.equal(result.status, 1)
    assert.match(result.output, expected)
    assert.equal(result.dbPath, undefined)
  }
})
