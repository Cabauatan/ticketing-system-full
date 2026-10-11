import assert from 'node:assert/strict'
import { spawnSync } from 'node:child_process'
import fs from 'node:fs'
import os from 'node:os'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import { test } from 'node:test'

const root = fileURLToPath(new URL('../../', import.meta.url))
const bash = process.env.BASH_TEST_BINARY || (process.platform === 'win32'
  ? 'C:/Program Files/Git/bin/bash.exe' : 'bash')
const shellPath = (value) => value.replaceAll('\\', '/')
const quote = (value) => "'" + value.replaceAll("'", "'\\''") + "'"
const docker = fs.readFileSync(path.join(root, 'Dockerfile'), 'utf8')
const defaultArguments = JSON.parse(/^CMD (\[.*\])$/m.exec(docker)[1])

// Windows has no Linux setuid/su-exec. Model its effective UID transition,
// execute the real shell entrypoint, then run real Node child processes with
// the modeled identity. This catches the Supervisor/child identity mismatch
// without claiming to exercise kernel signal permissions on Windows.
const helper = `
import fs from 'node:fs'
import { spawnSync } from 'node:child_process'
const [kind, ...args] = process.argv.slice(2)
const record = (item) => fs.appendFileSync(process.env.TEST_OPERATIONS, JSON.stringify(item) + '\\n')
const identity = { uid: Number(process.env.TEST_UID), user: process.env.TEST_USER }
const common = { kind, ...identity, args }
if (kind === 'worker') {
  record({ ...common, program: args[0] })
} else if (kind === 'su-exec') {
  record(common)
} else if (kind === 'chown' || kind === 'chmod') {
  record(common)
  if (kind === 'chown') for (const target of args.slice(1)) {
    if (target === process.env.TEST_STDOUT || target === process.env.TEST_STDERR) {
      if (process.env.TEST_LOG_REPAIR_FAIL === 'true') process.exit(73)
      fs.writeFileSync(target + '.worker-owned', '')
    }
  }
} else if (kind === 'nginx') {
  record(common)
  if (!fs.readFileSync(process.env.TEST_NGINX_CONFIG, 'utf8').includes('listen 0.0.0.0:' + process.env.PORT + ';')) process.exit(74)
} else if (kind === 'php-fpm') {
  record(common)
} else if (kind === 'php') {
  record(common)
  if (args[0] !== 'artisan') process.exit(75)
  if (process.env.TEST_ARTISAN_FAILURE === args[1]) process.exit(27)
} else if (kind === 'custom') {
  record({ ...common, environment: { runReverb: process.env.RUN_REVERB, runScheduler: process.env.RUN_SCHEDULER,
    runQueue: process.env.RUN_QUEUE_WORKER, marker: process.env.TEST_INHERITED_MARKER } })
} else if (kind === 'supervisord') {
  record({ ...common, environment: { runReverb: process.env.RUN_REVERB, runScheduler: process.env.RUN_SCHEDULER,
    runQueue: process.env.RUN_QUEUE_WORKER } })
  const configPath = args[args.indexOf('-c') + 1]
  const sections = {}
  let section
  for (const raw of fs.readFileSync(configPath, 'utf8').split(/\\r?\\n/)) {
    const line = raw.trim()
    const title = /^\\[([^\\]]+)\\]$/.exec(line)
    if (title) sections[section = title[1]] = {}
    else if (section && line.includes('=')) {
      const split = line.indexOf('=')
      sections[section][line.slice(0, split).trim()] = line.slice(split + 1).trim()
    }
  }
  const supervisorUser = sections.supervisord.user || identity.user
  if (supervisorUser !== identity.user) process.exit(76)
  for (const [name, settings] of Object.entries(sections)) {
    if (!name.startsWith('program:')) continue
    const feature = /^%\\(ENV_(.+)\\)s$/.exec(settings.autostart || '')
    const enabled = feature ? process.env[feature[1]] === 'true' : settings.autostart === 'true'
    if (!enabled) continue
    const childUser = settings.user || supervisorUser
    if (childUser !== supervisorUser) process.exit(77)
    const child = spawnSync(process.execPath, [process.argv[1], 'worker', name.slice(8)], {
      env: { ...process.env, TEST_USER: childUser, TEST_UID: '33' }, encoding: 'utf8',
    })
    if (child.error || child.status !== 0) process.exit(78)
  }
} else process.exit(79)
`

function probe({ environment = {}, arguments: override, supervisorMutation } = {}) {
  const fixture = fs.mkdtempSync(path.join(os.tmpdir(), 'ticketing-runtime-test-'))
  const bin = path.join(fixture, 'bin')
  const app = path.join(fixture, 'application')
  const config = path.join(fixture, 'supervisord.conf')
  const operations = path.join(fixture, 'operations.jsonl')
  const helperPath = path.join(fixture, 'command-helper.mjs')
  const outputConfig = path.join(fixture, 'nginx', 'default.conf')
  const stdout = path.join(fixture, 'stdout')
  const stderr = path.join(fixture, 'stderr')
  fs.mkdirSync(bin)
  fs.mkdirSync(path.join(app, 'docker'), { recursive: true })
  fs.mkdirSync(path.dirname(outputConfig))
  fs.writeFileSync(operations, '')
  fs.writeFileSync(helperPath, helper)
  fs.writeFileSync(stdout, '')
  fs.writeFileSync(stderr, '')
  fs.copyFileSync(path.join(root, 'docker/nginx-site.conf.template'), path.join(app, 'docker/nginx-site.conf.template'))
  let supervisor = fs.readFileSync(path.join(root, 'docker/supervisord.conf'), 'utf8')
  if (supervisorMutation) supervisor = supervisorMutation(supervisor)
  fs.writeFileSync(config, supervisor)
  const dispatch = 'exec "$TEST_NODE" "$TEST_HELPER" '
  for (const command of ['php', 'php-fpm', 'nginx', 'chown', 'chmod', 'supervisord', 'custom-command']) {
    fs.writeFileSync(path.join(bin, command), '#!/bin/sh\n' + dispatch + (command === 'custom-command' ? 'custom' : command) + ' "$@"\n', { mode: 0o755 })
  }
  fs.writeFileSync(path.join(bin, 'su-exec'), `#!/bin/sh
"$TEST_NODE" "$TEST_HELPER" su-exec "$@" || exit $?
[ "$1" = www-data ] || exit 91
shift
if [ "\${1-}" = test ] && [ "\${2-}" = -w ]; then
    if [ "$TEST_LOG_WRITABLE" = true ] || [ -f "$3.worker-owned" ]; then exit 0; fi
    exit 1
fi
if [ "$TEST_DENY_LAUNCH" = true ]; then
    case "\${1-}" in supervisord|custom-command) exit 92 ;; esac
fi
export TEST_UID=33 TEST_USER=www-data
exec "$@"
`, { mode: 0o755 })

  try {
    const production = fs.readFileSync(path.join(root, 'docker/entrypoint.sh'), 'utf8')
    // Change only fixed container filesystem locations. Execute every startup
    // decision, argument expansion and command from the production script.
    const source = production
      .replace('cd /var/www/html', `cd ${quote(shellPath(app))}`)
      .replaceAll('/var/www/html/docker/nginx-site.conf.template', quote(shellPath(path.join(app, 'docker/nginx-site.conf.template'))))
      .replaceAll('/etc/nginx/http.d/default.conf', quote(shellPath(outputConfig)))
      .replaceAll('/tmp/nginx-client-body', quote(shellPath(path.join(fixture, 'nginx-client-body'))))
      .replaceAll('/tmp/nginx-fastcgi', quote(shellPath(path.join(fixture, 'nginx-fastcgi'))))
      .replaceAll('/tmp/nginx-proxy', quote(shellPath(path.join(fixture, 'nginx-proxy'))))
      .replaceAll('/tmp/nginx-uwsgi', quote(shellPath(path.join(fixture, 'nginx-uwsgi'))))
      .replaceAll('/tmp/nginx-scgi', quote(shellPath(path.join(fixture, 'nginx-scgi'))))
      .replaceAll('/run/ticketing-mysql-ca', quote(shellPath(path.join(fixture, 'ca'))))
      .replaceAll('/proc/self/fd/1', quote(shellPath(stdout)))
      .replaceAll('/proc/self/fd/2', quote(shellPath(stderr)))
    const script = path.join(fixture, 'entrypoint.sh')
    fs.writeFileSync(script, source)
    const env = { ...process.env, APP_KEY: 'synthetic-key', APP_ENV: 'production', APP_DEBUG: 'false', PORT: '10000',
      DB_CONNECTION: 'mysql', QUEUE_CONNECTION: 'sync', RUN_MIGRATIONS: 'false', RUN_REVERB: 'false', RUN_SCHEDULER: 'false', RUN_QUEUE_WORKER: 'false',
      TEST_UID: '0', TEST_USER: 'root', TEST_NODE: shellPath(process.execPath), TEST_HELPER: shellPath(helperPath),
      TEST_OPERATIONS: shellPath(operations), TEST_NGINX_CONFIG: shellPath(outputConfig), TEST_STDOUT: shellPath(stdout), TEST_STDERR: shellPath(stderr),
      TEST_LOG_WRITABLE: 'true', TEST_LOG_REPAIR_FAIL: 'false', TEST_DENY_LAUNCH: 'false', TEST_ARTISAN_FAILURE: '', TEST_INHERITED_MARKER: 'inherited exactly', ...environment }
    delete env.DB_SSL_CA
    delete env.MYSQL_ATTR_SSL_CA
    const argv = (override || defaultArguments).map((value) => value === '/etc/supervisord.conf' ? shellPath(config) : value)
    // Bash PATH entries must use /c/... on Windows: C:/... contains its list delimiter.
    const mockPath = process.platform === 'win32'
      ? shellPath(bin).replace(/^([A-Za-z]):\//, (_, drive) => `/${drive.toLowerCase()}/`)
      : shellPath(bin)
    const wrapper = `export PATH=${quote(mockPath)}:"$PATH"\nexec sh ${quote(shellPath(script))} "$@"\n`
    const result = spawnSync(bash, ['--noprofile', '--norc', '-c', wrapper, 'runtime-probe', ...argv], {
      env, encoding: 'utf8', timeout: 30000,
    })
    assert.ifError(result.error)
    const rows = fs.readFileSync(operations, 'utf8').trim().split('\n').filter(Boolean).map((line) => JSON.parse(line))
    const runtimeDirectories = ['nginx-client-body', 'nginx-fastcgi', 'nginx-proxy', 'nginx-uwsgi', 'nginx-scgi']
      .filter((name) => fs.existsSync(path.join(fixture, name)))
    return { status: result.status, output: result.stdout + result.stderr, rows, argv, runtimeDirectories }
  } finally {
    const temp = fs.realpathSync(os.tmpdir())
    const resolved = fs.realpathSync(fixture)
    const relative = path.relative(temp, resolved)
    assert.ok(relative && !relative.startsWith('..') && !path.isAbsolute(relative))
    assert.ok(path.basename(resolved).startsWith('ticketing-runtime-test-'))
    fs.rmSync(resolved, { recursive: true, force: true })
  }
}

const artisan = (result) => result.rows.filter((row) => row.kind === 'php')
const launched = (result) => result.rows.filter((row) => row.kind === 'supervisord' || row.kind === 'custom')

test('default launch drops Supervisor and every enabled child to one identity after startup preparation', () => {
  const result = probe({ environment: { RUN_REVERB: 'YES', RUN_SCHEDULER: '1', RUN_QUEUE_WORKER: 'ON' } })
  assert.equal(result.status, 0, result.output)
  const supervisor = launched(result)[0]
  assert.equal(supervisor.kind, 'supervisord')
  assert.deepEqual(supervisor.args, result.argv.slice(1))
  assert.equal(supervisor.uid, 33)
  assert.equal(supervisor.user, 'www-data')
  assert.deepEqual(supervisor.environment, { runReverb: 'true', runScheduler: 'true', runQueue: 'true' })
  const children = result.rows.filter((row) => row.kind === 'worker')
  assert.deepEqual(children.map((row) => row.program).sort(), ['nginx', 'php-fpm', 'queue-worker', 'reverb', 'scheduler'])
  assert.ok(children.every((row) => row.uid === supervisor.uid && row.user === supervisor.user))
  assert.ok(result.rows.findIndex((row) => row.kind === 'chown' && row.uid === 0) < result.rows.indexOf(supervisor))
  assert.deepEqual(artisan(result).map((row) => row.args[1]), ['package:discover', 'config:cache', 'app:check-storage', 'route:cache', 'view:cache'])
  assert.ok(artisan(result).every((row) => row.uid === 33 && row.user === 'www-data'))
  assert.deepEqual(result.runtimeDirectories.sort(), ['nginx-client-body', 'nginx-fastcgi', 'nginx-proxy', 'nginx-scgi', 'nginx-uwsgi'])
  const ownership = result.rows.find((row) => row.kind === 'chown' && row.args[0] === '-R')
  for (const directory of result.runtimeDirectories) assert.ok(ownership.args.some((value) => value.endsWith('/' + directory)))
  const nginx = fs.readFileSync(path.join(root, 'docker/nginx.conf'), 'utf8')
  for (const directive of ['client_body_temp_path', 'fastcgi_temp_path', 'proxy_temp_path', 'uwsgi_temp_path', 'scgi_temp_path']) {
    const target = new RegExp('\\b' + directive + '\\s+([^;]+);').exec(nginx)?.[1].trim()
    assert.ok(target?.startsWith('/tmp/'), `${directive} must be writable without root`)
    assert.ok(result.runtimeDirectories.includes(path.posix.basename(target)), `${directive} was not prepared`)
  }
  for (const directive of ['pid', 'lock_file']) {
    assert.match(nginx, new RegExp('\\b' + directive + '\\s+/tmp/[^;]+;'))
  }
})

test('custom command receives exact argv and inherited environment through the privilege drop', () => {
  const args = ['custom-command', 'a value with spaces', 'semi;colon', '$(not-executed)', 'quote"value', '']
  const result = probe({ arguments: args })
  assert.equal(result.status, 0, result.output)
  const custom = launched(result)[0]
  assert.equal(custom.uid, 33)
  assert.deepEqual(custom.args, args.slice(1))
  assert.equal(custom.environment.marker, 'inherited exactly')
  assert.equal(result.rows.some((row) => row.kind === 'worker'), false)
})

test('migrations run only with explicit opt-in and precede readonly storage preflight', () => {
  for (const [value, expected] of [['false', false], ['0', false], ['invalid', false], ['true', true]]) {
    const result = probe({ environment: { RUN_MIGRATIONS: value } })
    assert.equal(result.status, 0, result.output)
    const steps = artisan(result).map((row) => row.args[1])
    assert.equal(steps.includes('migrate'), expected)
    if (expected) assert.ok(steps.indexOf('migrate') < steps.indexOf('app:check-storage'))
    assert.ok(steps.indexOf('app:check-storage') < steps.indexOf('route:cache'))
  }
})

test('failed storage preflight prevents cache completion and any runtime launch', () => {
  const result = probe({ environment: { TEST_ARTISAN_FAILURE: 'app:check-storage', RUN_MIGRATIONS: 'true' } })
  assert.equal(result.status, 27, result.output)
  assert.deepEqual(artisan(result).map((row) => row.args[1]), ['package:discover', 'config:cache', 'migrate', 'app:check-storage'])
  assert.equal(launched(result).length, 0)
})

test('failed migration or config cache cannot fall through to Supervisor', () => {
  for (const command of ['config:cache', 'migrate']) {
    const result = probe({ environment: { TEST_ARTISAN_FAILURE: command, RUN_MIGRATIONS: 'true' } })
    assert.equal(result.status, 27, result.output)
    assert.equal(launched(result).length, 0)
    assert.equal(artisan(result).some((row) => row.args[1] === 'app:check-storage'), false)
  }
})

test('root-owned runtime log streams are prepared before Supervisor starts', () => {
  const result = probe({ environment: { TEST_LOG_WRITABLE: 'false' } })
  assert.equal(result.status, 0, result.output)
  const streamOwnership = result.rows.filter((row) => row.kind === 'chown' && row.args[0] === 'www-data:www-data' && row.args.length === 2)
  assert.equal(streamOwnership.length, 2)
  assert.ok(streamOwnership.every((row) => row.uid === 0))
  const launchIndex = result.rows.findIndex((row) => row.kind === 'supervisord')
  assert.ok(streamOwnership.every((row) => result.rows.indexOf(row) < launchIndex))
})

test('unrepairable log streams and rejected privilege drop prevent runtime launch', () => {
  for (const [environment, status] of [
    [{ TEST_LOG_WRITABLE: 'false', TEST_LOG_REPAIR_FAIL: 'true' }, 1],
    [{ TEST_DENY_LAUNCH: 'true' }, 92],
  ]) {
    const result = probe({ environment })
    assert.equal(result.status, status, result.output)
    assert.equal(launched(result).length, 0)
    if (status === 92) assert.equal(artisan(result).at(-1).args[1], 'view:cache')
  }
})

test('the runtime harness rejects the previous root Supervisor/worker identity split', () => {
  const result = probe({ supervisorMutation: (source) => source.replace(/(\[supervisord\][\s\S]*?\nuser=)www-data/, '$1root') })
  assert.equal(result.status, 76, result.output)
  assert.equal(result.rows.some((row) => row.kind === 'worker'), false)
})
