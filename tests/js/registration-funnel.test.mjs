// ADR-849 (Leezr 4.2 M10) — public registration funnel helpers : forward steps only, origin host only, one journey id.
import { test } from 'node:test'
import assert from 'node:assert/strict'
import { journeyId, passedSteps, trafficSource } from '../../resources/js/utils/registrationFunnel.js'

test('only forward moves record the steps passed (never the summary, never a step back)', () => {
  assert.deepEqual(passedSteps(0, 1), ['industry'])
  assert.deepEqual(passedSteps(1, 4), ['plan', 'addons', 'company'])
  assert.deepEqual(passedSteps(4, 5), ['account'])
  assert.deepEqual(passedSteps(3, 1), [])
  assert.deepEqual(passedSteps(null, 0), [])
})

test('traffic origin is reduced to a host — never a path or a query string', () => {
  assert.equal(trafficSource('https://www.search.example/q?email=a%40b.c'), 'www.search.example')
  assert.equal(trafficSource(''), 'direct')
  assert.equal(trafficSource('not a url'), 'direct')
})

test('one random journey id per tab, reused', () => {
  const store = new Map()
  const storage = { getItem: k => store.get(k) ?? null, setItem: (k, v) => store.set(k, v) }
  const a = journeyId(storage)

  assert.match(a, /^[0-9a-f-]{36}$/)
  assert.equal(journeyId(storage), a)
})
