// ADR-850 (Leezr 4.2 M10) — every Leezr 4.2 recipe PREPARES ITSELF (test.beforeAll) : no mandatory order, no state
// inherited from another recipe. Fixtures are the clone-guarded scripts/audit842-*-fixture.php (they refuse anything
// but the marked recipe clone) ; the password is read from AUDIT_PASSWORD_FILE and never printed.
import { execFileSync } from 'node:child_process'

export const CLONE_ENV = { ...process.env, DB_DATABASE: process.env.E2E_DB_DATABASE || 'leezr_audit842', CACHE_PREFIX: 'leezr_audit842-', REDIS_PREFIX: 'leezr_audit842-' }

/** run one fixture ; returns its stdout (some print JSON) */
export function runFixture(name, ...args) {
  const script = name === 'profiles' ? 'scripts/audit842-profiles.php' : `scripts/audit842-${name}-fixture.php` // profiles : base accounts of the clone

  return execFileSync('php', [script, ...args], { env: CLONE_ENV }).toString()
}

/** expected MRR / ARR computed by raw SQL, independent of the application code (scripts/audit842-m4-expected.php) */
export const m4Expected = () => JSON.parse(execFileSync('php', ['scripts/audit842-m4-expected.php'], { env: CLONE_ENV }).toString())

/** prepare a recipe with the fixtures it needs, in order (each receives the password file) */
export function prepare(...names) {
  const out = {}

  for (const name of names) out[name] = runFixture(name, process.env.AUDIT_PASSWORD_FILE)

  return out
}

/** the JSON line printed by a fixture */
export const fixtureJson = text => JSON.parse(text.trim().split('\n').filter(l => l.startsWith('{')).pop() || '{}')
