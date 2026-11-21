// ADR-849 (Leezr 4.2 M10) — pure helpers of the public registration funnel (tested in tests/js).
export const FUNNEL_STORAGE_KEY = 'lzr:registration-funnel'

export const FUNNEL_STEPS = ['industry', 'plan', 'addons', 'company', 'account', 'summary']

export function journeyId(storage = globalThis.sessionStorage) {
  try {
    let id = storage?.getItem(FUNNEL_STORAGE_KEY)
    if (!id) {
      id = globalThis.crypto?.randomUUID?.() ?? null
      if (id) storage?.setItem(FUNNEL_STORAGE_KEY, id)
    }

    return id
  }
  catch { return null }
}

/** the step names to record when the screen moves from `from` to `to` (forward moves only, never « summary ») */
export function passedSteps(from, to) {
  if (from == null || to == null || to <= from) return []

  return FUNNEL_STEPS.slice(from, to).filter(s => s !== 'summary')
}

/** referrer reduced to its host — never a path or a query string */
export function trafficSource(referrer) {
  try { return referrer ? new URL(referrer).host.slice(0, 100) : 'direct' }
  catch { return 'direct' }
}

