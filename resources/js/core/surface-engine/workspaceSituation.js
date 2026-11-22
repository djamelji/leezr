/**
 * ADR-857 (Leezr 5.0 L3) — the Company workspace « situation » : what the hero says, built ONLY from data the viewer
 * is already authorised to read (the permissioned widget catalogue resolved by the engine, and the server priorities).
 * Pure functions (no Vue, no API) : the component translates the returned i18n keys. Never a claim without proof :
 * « nothing to do » requires the priorities API to have answered with an empty, complete list.
 */

/** keys the hero may read — each one is resolved only when it is in the viewer's catalogue */
export const SUMMARY_KEYS = ['shipments.overview', 'logistics.fleet', 'workforce.clock_live', 'workforce.headcount', 'planning.shifts']

const num = v => (Number.isFinite(Number(v)) ? Number(v) : 0)
const ok = d => d && typeof d === 'object' && !d.error

/**
 * The workspace focus, from the active modules (never from a hard-coded JobDomain key) :
 * 'logistics' when shipment widgets are in the catalogue, 'workforce' when workforce widgets are, else 'general'.
 */
export function workspaceFocus(catalogKeys) {
  const k = new Set(catalogKeys || [])
  if (k.has('shipments.overview') || k.has('logistics.fleet') || k.has('shipments.today')) return 'logistics'
  if (k.has('workforce.headcount') || k.has('workforce.clock_live') || k.has('planning.shifts')) return 'workforce'

  return 'general'
}

/**
 * A company with no operational activity yet : every KNOWN indicator is empty. Unknown (nothing resolved) is NOT quiet.
 * headcount ≤ 1 : the founder alone is not a team.
 */
export function isQuiet(data) {
  const d = data || {}
  const signals = []
  if (ok(d['shipments.overview'])) signals.push(num(d['shipments.overview'].total) === 0)
  if (ok(d['logistics.fleet'])) signals.push(num(d['logistics.fleet'].scheduled_today) === 0 && num(d['logistics.fleet'].completed_today) === 0)
  if (ok(d['planning.shifts'])) signals.push(num(d['planning.shifts'].total) === 0)
  if (ok(d['workforce.headcount'])) signals.push(num(d['workforce.headcount'].total) <= 1)
  if (ok(d['workforce.clock_live'])) signals.push(num(d['workforce.clock_live'].working) + num(d['workforce.clock_live'].completed) === 0)

  return signals.length > 0 && signals.every(Boolean)
}

/**
 * @returns {{ parts: {key: string, params?: object}[], progress: {value: number, key: string}|null, live: number|null }}
 */
export function buildSituation({ data = {}, priorities = [], prioritiesState = 'loading', prioritiesPartial = false, setup = null } = {}) {
  const parts = []
  let progress = null
  let live = null

  if (setup && setup.total > 0) {
    parts.push({ key: 'l3.sit.setup', params: { done: setup.done, total: setup.total, left: setup.total - setup.done } })
    progress = { value: Math.round((setup.done / setup.total) * 100), key: 'l3.progress.setup' }
  }

  const fleet = ok(data['logistics.fleet']) ? data['logistics.fleet'] : null
  const ship = ok(data['shipments.overview']) ? data['shipments.overview'] : null
  if (fleet && num(fleet.scheduled_today) > 0) {
    parts.push({ key: 'l3.sit.deliveries', params: { done: num(fleet.completed_today), total: num(fleet.scheduled_today) } })
    if (!progress) progress = { value: Math.min(100, Math.round((num(fleet.completed_today) / num(fleet.scheduled_today)) * 100)), key: 'l3.progress.deliveries' }
  }
  else if (ship && num(ship.today) > 0) {
    parts.push({ key: 'l3.sit.shipToday', params: { n: num(ship.today) } })
  }
  if (ship && num(ship.late) > 0) parts.push({ key: 'l3.sit.late', params: { n: num(ship.late) } })
  if (ship && num(ship.unassigned) > 0) parts.push({ key: 'l3.sit.unassigned', params: { n: num(ship.unassigned) } })

  const clock = ok(data['workforce.clock_live']) ? data['workforce.clock_live'] : null
  const head = ok(data['workforce.headcount']) ? data['workforce.headcount'] : null
  if (clock) {
    live = num(clock.working) + num(clock.on_break)
    if (live > 0) parts.push({ key: 'l3.sit.working', params: { n: live } })
    if (!progress && head && num(head.active) > 0 && live > 0)
      progress = { value: Math.min(100, Math.round((live / num(head.active)) * 100)), key: 'l3.progress.presence' }
  }

  if (prioritiesState === 'ready') {
    const n = priorities.reduce((s, p) => s + num(p.count), 0)
    if (n > 0) parts.push({ key: 'l3.sit.todo', params: { n, topics: priorities.length } })
    else if (!prioritiesPartial) parts.push({ key: 'l3.sit.nothingTodo' })
  }

  if (!parts.length) parts.push({ key: setup ? 'l3.sit.setupStart' : 'l3.sit.noActivity' })

  return { parts: parts.slice(0, 4), progress, live }
}

/** most urgent priority first (error > warning > info), then by count */
export function topPriorities(priorities, limit = 1) {
  const rank = { error: 0, warning: 1, info: 2 }

  return [...(priorities || [])]
    .filter(p => num(p.count) > 0 && p.to)
    .sort((a, b) => (rank[a.severity] ?? 3) - (rank[b.severity] ?? 3) || num(b.count) - num(a.count))
    .slice(0, limit)
}
