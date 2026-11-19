// ADR-831 — before ANY spec runs, the campaign proves that BOTH writers target the same marked isolated clone :
//  1. the served application (it writes through the HTTP API) : GET /api/_recipe/identity must answer 200 and
//     name E2E_DB_DATABASE — the proof is read by the server on its live connection, a host name proves nothing ;
//  2. the local recipe commands (e2e:*, e2e:php) : `php artisan recipe:status` with the campaign's DB_DATABASE.
// No flag bypasses this check (E2E_ALLOW_DEV was withdrawn) : the DEV, a production-like target or a server
// started on another database stop the campaign before the first request that could write.
import { execFileSync } from 'node:child_process'
import { request } from '@playwright/test'

const REPO = new URL('../..', import.meta.url).pathname

export default async function globalSetup(config) {
  const baseURL = config.projects[0]?.use?.baseURL
  const expected = process.env.E2E_DB_DATABASE
  const refuse = reason => {
    throw new Error(`E2E refused (ADR-831) : ${reason}. Serve a marked clone (scripts/recipe/make-clone.sh) and set E2E_BASE_URL + E2E_DB_DATABASE.`)
  }
  if (!expected)
    refuse('E2E_DB_DATABASE is not set')

  const ctx = await request.newContext({ baseURL, ignoreHTTPSErrors: true })
  let served
  try {
    const res = await ctx.get('/api/_recipe/identity', { headers: { Accept: 'application/json' }, timeout: 15_000 })
    served = { status: res.status(), body: await res.json().catch(() => null) }
  }
  catch (e) {
    refuse(`${baseURL} did not answer the identity probe (${e.message.split('\n')[0]})`)
  }
  finally {
    await ctx.dispose()
  }
  if (served.status !== 200 || served.body?.clone !== true)
    refuse(`${baseURL} does not run on a marked isolated clone (identity probe ${served.status})`)
  if (served.body.database !== expected)
    refuse(`${baseURL} runs on ${served.body.database}, not on E2E_DB_DATABASE=${expected}`)

  try {
    const status = JSON.parse(execFileSync('php', ['artisan', 'recipe:status'], { cwd: REPO, encoding: 'utf8', env: { ...process.env } }))
    if (!status.allowed || status.identity?.actual !== expected)
      refuse(`recipe commands would run on ${status.identity?.actual}`)
  }
  catch (e) {
    if (e.message?.startsWith('E2E refused'))
      throw e
    refuse(`recipe commands are not on a marked clone (${(e.stdout || e.message || '').toString().split('\n').slice(0, 4).join(' ')})`)
  }
}
