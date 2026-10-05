import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { createRequire } from 'node:module'
import { resolve } from 'node:path'
import { pathToFileURL } from 'node:url'
import vm from 'node:vm'

const dependencyRoot = process.argv[2]
if (!dependencyRoot) throw new Error('Usage: node tests/read-recovery-test.mjs <frontend-node_modules>')
const require = createRequire(pathToFileURL(resolve(dependencyRoot, 'package.json')))
const ts = require('typescript')
let now = 0
let timerId = 0
const timers = new Map()
const clock = {
  Date: class extends Date { static now() { return now } },
  setTimeout(fn, delay) { const id = ++timerId; timers.set(id, { fn, at: now + delay }); return id },
  clearTimeout(id) { timers.delete(id) }
}
function load(relative, imports = {}) {
  return loadText(readFileSync(new URL(relative, import.meta.url), 'utf8'), imports)
}
function loadText(source, imports = {}) {
  const compiled = ts.transpileModule(source, { compilerOptions: { target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.CommonJS } })
  const exports = {}
  vm.runInNewContext(compiled.outputText, {
    exports, AbortController, console, ...clock,
    require(id) { if (id in imports) return imports[id]; throw new Error(`Unexpected import ${id}`) }
  })
  return exports
}
const status = load('../../sand-core/sandadmin-artd/src/utils/http/status.ts')
const { HttpError, isRecoverableInitializationError } = load('../../sand-core/sandadmin-artd/src/utils/http/error.ts', {
  axios: require('axios'), './status': status, '@/locales': { $t: (key) => key }
})
const { createRecoverableRead } = load('../sandadmin-artd/src/views/plugin/sandpackage/install/read-recovery.ts')
const failure = (code = 500, data = '', method = 'GET', transport = 'http') => new HttpError('failed', code, { data, method, transport })
async function settle() { for (let n = 0; n < 12; n++) await Promise.resolve() }
async function advance(ms) {
  now += ms
  for (const [id, timer] of [...timers]) if (timer.at <= now) { timers.delete(id); timer.fn() }
  await settle()
}
let passed = 0
async function test(name, run) {
  now = 0; timers.clear()
  await run(); assert.equal(timers.size, 0, 'no remaining retry timers')
  console.log(`PASS ${name}`); passed++
}
await test('empty 500 recovers, normal request remains unrestricted', async () => {
  let calls = 0
  const read = createRecoverableRead(isRecoverableInitializationError).begin()
  const result = read.run(async () => { if (++calls === 1) throw failure(); return 'installed' })
  await settle(); assert.equal(calls, 1)
  await advance(1000)
  assert.equal(await result, 'installed'); assert.equal(calls, 2)
})
await test('permanent transient failure stops after 4 retries', async () => {
  let calls = 0
  const error = failure(503)
  const result = createRecoverableRead(isRecoverableInitializationError).begin().run(async () => { calls++; throw error }).catch(e => e)
  await settle()
  for (const delay of [1000, 2000, 3000, 4000]) await advance(delay)
  assert.equal(await result, error); assert.equal(calls, 5)
})
await test('retry starts stop at the 15 second budget', async () => {
  let calls = 0
  const error = failure()
  const result = createRecoverableRead(isRecoverableInitializationError).begin().run(async () => { calls++; now += 14500; throw error }).catch(e => e)
  assert.equal(await result, error); assert.equal(calls, 1)
})
await test('slow successful catalog can take its existing 65 second timeout', async () => {
  const result = await createRecoverableRead(isRecoverableInitializationError).begin().run(async () => { now += 64000; return 'catalog' })
  assert.equal(result, 'catalog')
})
for (const [name, error] of [
  ['401', failure(401)], ['403', failure(403)], ['business payload', failure(500, { code: 500, msg: 'business failure' })],
  ['nonempty 500', failure(500, 'Internal server error')], ['POST', failure(500, '', 'POST')],
  ['timeout', failure(500, '', 'GET', 'timeout')], ['cancelled', failure(500, '', 'GET', 'cancelled')]
]) await test(`${name} is never replayed`, async () => {
  let calls = 0
  const result = await createRecoverableRead(isRecoverableInitializationError).begin().run(async () => { calls++; throw error }).catch(e => e)
  assert.equal(result, error); assert.equal(calls, 1)
})
await test('network GET and empty 502 are recoverable', async () => {
  assert.equal(isRecoverableInitializationError(failure(0, undefined, 'GET', 'network')), true)
  assert.equal(isRecoverableInitializationError(failure(502, null)), true)
  assert.equal(isRecoverableInitializationError(new Error('network')), false)
})
await test('cancellation clears waiting timer and prevents replay', async () => {
  const scope = createRecoverableRead(isRecoverableInitializationError)
  const read = scope.begin(); let calls = 0
  const result = read.run(async () => { calls++; throw failure() }).catch(e => e)
  await settle(); scope.cancel(); await result
  assert.equal(read.isCurrent(), false); assert.equal(calls, 1)
})
await test('superseded success, failure and finally cannot change current state', async () => {
  for (const rejectOld of [false, true]) {
    const scope = createRecoverableRead(isRecoverableInitializationError)
    let complete; let state = 'loading'; let oldSignal
    const old = scope.begin()
    const pending = old.run(signal => { oldSignal = signal; return new Promise((resolve, reject) => { complete = () => rejectOld ? reject(failure()) : resolve('stale') }) })
      .then(value => { if (old.isCurrent()) state = value })
      .catch(() => { if (old.isCurrent()) state = 'error' })
      .finally(() => { if (old.isCurrent()) state = 'finished' })
    const current = scope.begin()
    state = await current.run(async () => 'current')
    complete(); await pending
    assert.equal(state, 'current'); assert.equal(oldSignal.aborted, true)
  }
})
await test('API options preserve 65s catalog timeout and do not change writes', async () => {
  const calls = []
  const api = load('../sandadmin-artd/src/views/plugin/sandpackage/api/index.ts', {
    '@/utils/http': { default: { get(config) { calls.push(['GET', config]); return Promise.resolve({}) }, post(config) { calls.push(['POST', config]); return Promise.resolve({}) } } }
  }).default
  const signal = new AbortController().signal
  await api.getRepositoryCatalog({ signal, showErrorMessage: false })
  await api.getAppList({ signal, showErrorMessage: false })
  await api.installApp({ appName: 'example' })
  assert.equal(calls[0][1].timeout, 65000); assert.equal(calls[0][1].signal, signal)
  assert.equal(calls[1][1].showErrorMessage, false)
  assert.equal(calls[2][0], 'POST'); assert.equal(calls[2][1].signal, undefined)
})
const vue = require('vue')
const compiler = require('@vue/compiler-sfc')
function mountPage(api) {
  const hooks = { mounted: [], activated: [], deactivated: [], unmounted: [] }
  const user = vue.reactive({ isLogin: true, accessToken: 'first-session' })
  const source = readFileSync(process.env.PAGE_SOURCE || new URL('../sandadmin-artd/src/views/plugin/sandpackage/install/index.vue', import.meta.url), 'utf8')
  const parsed = compiler.parse(source)
  assert.equal(parsed.errors.length, 0)
  const script = compiler.compileScript(parsed.descriptor, { id: 'read-recovery-regression' })
  const recovery = load('../sandadmin-artd/src/views/plugin/sandpackage/install/failed-upgrade-recovery.ts', { '../api/index': { default: api } })
  const component = loadText(script.content, {
    vue: { ...vue, onMounted: fn => hooks.mounted.push(fn), onActivated: fn => hooks.activated.push(fn), onDeactivated: fn => hooks.deactivated.push(fn), onUnmounted: fn => hooks.unmounted.push(fn) },
    'element-plus': { ElMessage: {}, ElMessageBox: {} },
    '@/store/modules/user': { useUserStore: () => user },
    '@/utils/http/error': { isRecoverableInitializationError },
    './read-recovery': { createRecoverableRead },
    '../api/index': { default: api },
    './failed-upgrade-recovery': recovery,
    './version-compatibility': load('../sandadmin-artd/src/views/plugin/sandpackage/install/version-compatibility.ts'),
    './install-box.vue': { default: {} }, './terminal.vue': { default: {} }, './system-update.vue': { default: {} },
    '../store/terminal': { TaskStatus: {}, useTerminalStore: () => ({ tasks: [] }) }
  }).default
  const scope = vue.effectScope()
  const page = scope.run(() => component.setup({}, { expose() {} }))
  return { page, user, hooks, stop() { hooks.unmounted.forEach(fn => fn()); scope.stop() } }
}
await test('actual page recovers both reads after transient reload failures', async () => {
  let lists = 0; let catalogs = 0
  const instance = mountPage({
    async getAppList() { if (++lists === 1) throw failure(); return { data: [], version: {} } },
    async getRepositoryCatalog() { if (++catalogs === 1) throw failure(502); return { plugins: [] } }
  })
  instance.hooks.mounted.forEach(fn => fn()); await settle(); await advance(1000)
  assert.equal(lists, 2); assert.equal(catalogs, 2)
  assert.equal(instance.page.loading.value, false); assert.equal(instance.page.repositoryLoading.value, false)
  assert.equal(instance.page.listError.value, ''); assert.equal(instance.page.repositoryError.value, '')
  instance.stop()
})
await test('actual page tab/session/deactivation invalidates stale responses', async () => {
  const reads = []
  const instance = mountPage({
    getAppList(options) { return new Promise(resolve => reads.push({ options, resolve, list: true })) },
    getRepositoryCatalog(options) { return new Promise(resolve => reads.push({ options, resolve, list: false })) }
  })
  instance.hooks.mounted.forEach(fn => fn())
  instance.page.activeTab.value = 'local'; await vue.nextTick()
  assert.equal(reads.length, 3)
  instance.page.activeTab.value = 'repository'; await vue.nextTick()
  assert.equal(reads.length, 5)
  assert.equal(reads[2].options.signal.aborted, true)
  assert.equal(reads[0].options.signal.aborted, true)
  for (const read of reads.slice(3)) read.resolve(read.list ? { data: [], version: { fresh: true } } : { plugins: [], fresh: true })
  await settle()
  for (const read of reads.slice(0, 3)) read.resolve(read.list ? { data: [], version: { stale: true } } : { plugins: [], stale: true })
  await settle()
  assert.equal(instance.page.version.value.fresh, true)
  assert.equal(instance.page.repositoryCatalog.value.fresh, true)
  instance.page.getList(); instance.page.fetchRepositoryCatalog()
  instance.user.accessToken = 'second-session'; await settle()
  assert.equal(reads[5].options.signal.aborted, true)
  instance.hooks.deactivated.forEach(fn => fn())
  const count = reads.length
  instance.page.getList(); instance.page.fetchRepositoryCatalog()
  assert.equal(reads.length, count)
  assert.equal(instance.page.loading.value, false); assert.equal(instance.page.repositoryLoading.value, false)
  instance.hooks.activated.forEach(fn => fn())
  assert.equal(reads.length, count + 2)
  instance.stop()
  assert.equal(reads.at(-1).options.signal.aborted, true)
})
await test('local/system tabs, activation and session changes never start hidden catalog reads', async () => {
  let lists = 0; let catalogs = 0
  const instance = mountPage({
    async getAppList() { lists++; return { data: [], version: {} } },
    async getRepositoryCatalog() { catalogs++; return { plugins: [] } }
  })
  instance.hooks.mounted.forEach(fn => fn()); await settle()
  assert.equal(catalogs, 1)
  instance.page.activeTab.value = 'local'; await vue.nextTick(); await settle()
  assert.equal(lists, 2); assert.equal(catalogs, 1)
  instance.hooks.deactivated.forEach(fn => fn()); instance.hooks.activated.forEach(fn => fn()); await settle()
  assert.equal(lists, 3); assert.equal(catalogs, 1)
  instance.user.accessToken = 'local-session'; await settle()
  assert.equal(lists, 4); assert.equal(catalogs, 1)
  instance.page.activeTab.value = 'system'; await vue.nextTick(); await settle()
  instance.hooks.deactivated.forEach(fn => fn()); instance.hooks.activated.forEach(fn => fn())
  instance.user.accessToken = 'system-session'; await settle()
  assert.equal(lists, 4); assert.equal(catalogs, 1)
  instance.page.activeTab.value = 'repository'; await vue.nextTick(); await settle()
  assert.equal(lists, 5); assert.equal(catalogs, 2)
  instance.stop()
})
console.log(`${passed}/${passed} repository read recovery behaviors passed`)
