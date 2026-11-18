// ADR-829 (D-20) — RealtimeClient state machine : fallback to polling, bounded recovery back to SSE,
// hidden tab, cleanup of every timer / listener / stream. Run : node --test tests/js/
import { test, mock } from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync, writeFileSync, mkdtempSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join, dirname } from 'node:path'
import { fileURLToPath } from 'node:url'

const src = readFileSync(join(dirname(fileURLToPath(import.meta.url)), '../../resources/js/core/realtime/RealtimeClient.js'), 'utf8')
const tmp = join(mkdtempSync(join(tmpdir(), 'rtc-')), 'client.mjs')
writeFileSync(tmp, src.replaceAll('import.meta.env', '({ DEV: false })'))
const { createRealtimeClient, recoveryDelay, RECOVERY_BASE_MS, RECOVERY_MAX_MS } = await import(tmp)

// Minimal document with visibility + listener bookkeeping
const doc = { visibilityState: 'visible', listeners: new Set(),
  addEventListener(t, f) { this.listeners.add(f) }, removeEventListener(t, f) { this.listeners.delete(f) } }
globalThis.document = doc

function harness(extra = {}) {
  const sources = []
  const calls = { fallback: 0, connected: 0, disconnected: 0 }
  const factory = url => {
    const es = { url, closed: false, handlers: {}, addEventListener(t, f) { this.handlers[t] = f }, close() { this.closed = true } }
    sources.push(es)

    return es
  }
  const client = createRealtimeClient({ companyId: 7, eventSourceFactory: factory,
    onFallback: () => calls.fallback++, onConnected: () => calls.connected++, onDisconnected: () => calls.disconnected++, ...extra })
  const open = () => sources.filter(s => !s.closed)
  const last = () => sources.at(-1)
  const fail = () => last().onerror()
  const succeed = () => last().handlers.connected()

  return { client, sources, calls, open, last, fail, succeed }
}

test('recovery delay is bounded and jittered', () => {
  assert.equal(recoveryDelay(0, () => 0.5), RECOVERY_BASE_MS)
  assert.equal(recoveryDelay(1, () => 0.5), 2 * RECOVERY_BASE_MS)
  assert.equal(recoveryDelay(20, () => 0.5), RECOVERY_MAX_MS)
  assert.ok(recoveryDelay(20, () => 1) <= RECOVERY_MAX_MS * 1.2)
  assert.ok(recoveryDelay(0, () => 0) >= RECOVERY_BASE_MS * 0.8)
})

test('3 failures → polling, then ONE recovery attempt at a time, back to SSE without reload', () => {
  mock.timers.enable({ apis: ['setTimeout'] })
  const h = harness()
  h.client.connect()
  h.fail(); mock.timers.tick(2000)
  h.fail(); mock.timers.tick(4000)
  h.fail()
  assert.equal(h.calls.fallback, 1)
  assert.equal(h.client.fallback, true)
  assert.equal(h.sources.length, 3)
  assert.equal(h.open().length, 0)

  // nothing before the recovery delay, then exactly one stream
  mock.timers.tick(RECOVERY_BASE_MS * 0.8 - 1)
  assert.equal(h.sources.length, 3)
  mock.timers.tick(RECOVERY_BASE_MS * 0.4 + 1)
  assert.equal(h.sources.length, 4)
  h.client.connect() // a second call must not open a concurrent stream
  assert.equal(h.open().length, 1)

  h.fail() // still 429 → backoff grows, no burst
  mock.timers.tick(RECOVERY_BASE_MS * 1.2)
  assert.equal(h.sources.length, 4, 'second recovery waits ~60 s')
  mock.timers.tick(RECOVERY_BASE_MS * 1.2)
  assert.equal(h.sources.length, 5)

  h.succeed()
  assert.equal(h.client.fallback, false)
  assert.equal(h.calls.connected, 1)
  assert.equal(h.client.pendingTimers, 0)

  h.client.disconnect()
  assert.equal(h.open().length, 0)
  mock.timers.reset()
})

test('a lost open stream notifies onDisconnected, reconnects quickly, no polling gap', () => {
  mock.timers.enable({ apis: ['setTimeout'] })
  const h = harness()
  h.client.connect(); h.succeed()
  h.fail()
  assert.equal(h.calls.disconnected, 1)
  mock.timers.tick(2000)
  assert.equal(h.sources.length, 2)
  h.succeed()
  assert.equal(h.calls.connected, 2)
  h.client.disconnect()
  mock.timers.reset()
})

test('hidden tab : no recovery stream until visible ; listener removed', () => {
  mock.timers.enable({ apis: ['setTimeout'] })
  const h = harness()
  h.client.connect(); h.fail(); mock.timers.tick(2000); h.fail(); mock.timers.tick(4000); h.fail()
  doc.visibilityState = 'hidden'
  mock.timers.tick(RECOVERY_MAX_MS * 2)
  assert.equal(h.sources.length, 3)
  assert.equal(doc.listeners.size, 1)
  doc.visibilityState = 'visible'
  ;[...doc.listeners][0]()
  assert.equal(h.sources.length, 4)
  assert.equal(doc.listeners.size, 0)
  h.client.disconnect()
  mock.timers.reset()
})

test('canRecover=false (session gone / company switched) skips the attempt ; disconnect clears everything', () => {
  mock.timers.enable({ apis: ['setTimeout'] })
  let allowed = false
  const h = harness({ canRecover: () => allowed })
  h.client.connect(); h.fail(); mock.timers.tick(2000); h.fail(); mock.timers.tick(4000); h.fail()
  mock.timers.tick(RECOVERY_MAX_MS * 3)
  assert.equal(h.sources.length, 3)
  assert.equal(h.client.pendingTimers, 1)

  doc.visibilityState = 'hidden'
  mock.timers.tick(RECOVERY_MAX_MS * 1.2)
  assert.equal(doc.listeners.size, 1)
  h.client.disconnect()
  assert.equal(h.client.pendingTimers, 0)
  assert.equal(doc.listeners.size, 0)
  doc.visibilityState = 'visible'
  allowed = true
  mock.timers.tick(RECOVERY_MAX_MS * 3)
  assert.equal(h.sources.length, 3, 'nothing after disconnect')
  mock.timers.reset()
})
