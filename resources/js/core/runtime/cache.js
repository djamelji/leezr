/**
 * Runtime cache — sessionStorage with TTL + stale-while-revalidate.
 *
 * All keys are prefixed with 'lzr:' to avoid collision.
 * Entries are version-keyed: a deploy with a new VITE_APP_VERSION busts the cache.
 */

const PREFIX = 'lzr:'

export const CACHE_VERSION = import.meta.env.VITE_APP_VERSION || '__dev__'

/**
 * Get a cached value if it exists.
 * @param {string} key - Cache key (without prefix)
 * @param {number} ttl - TTL in milliseconds
 * @returns {{ data: *, stale: boolean } | null}
 */
export function cacheGet(key, ttl) {
  try {
    const raw = sessionStorage.getItem(PREFIX + key)
    if (!raw) return null

    const entry = JSON.parse(raw)

    // Version mismatch → treat as miss
    if (entry.ver !== CACHE_VERSION) {
      sessionStorage.removeItem(PREFIX + key)

      return null
    }

    const age = Date.now() - entry.ts
    const stale = age > ttl

    return { data: entry.data, stale }
  }
  catch {
    return null
  }
}

/**
 * Store a value in the cache.
 * @param {string} key - Cache key (without prefix)
 * @param {*} data - Data to cache (must be JSON-serializable)
 */
export function cacheSet(key, data) {
  try {
    const entry = {
      data,
      ts: Date.now(),
      ver: CACHE_VERSION,
    }

    sessionStorage.setItem(PREFIX + key, JSON.stringify(entry))
  }
  catch {
    // sessionStorage full or unavailable — silently fail
  }
}

/**
 * Remove a specific cache entry.
 * @param {string} key - Cache key (without prefix)
 */
export function cacheRemove(key) {
  sessionStorage.removeItem(PREFIX + key)
}

/**
 * Clear all runtime cache entries (lzr:* keys only).
 */
export function cacheClear() {
  const keysToRemove = []
  for (let i = 0; i < sessionStorage.length; i++) {
    const k = sessionStorage.key(i)
    if (k?.startsWith(PREFIX)) {
      keysToRemove.push(k)
    }
  }
  keysToRemove.forEach(k => sessionStorage.removeItem(k))
}

/**
 * List all runtime cache keys (for debug panel).
 * @returns {Array<{ key: string, age: number, stale: boolean, ver: string }>}
 */
export function cacheEntries() {
  const entries = []
  for (let i = 0; i < sessionStorage.length; i++) {
    const fullKey = sessionStorage.key(i)
    if (!fullKey?.startsWith(PREFIX)) continue

    try {
      const entry = JSON.parse(sessionStorage.getItem(fullKey))
      entries.push({
        key: fullKey.slice(PREFIX.length),
        age: Date.now() - entry.ts,
        ver: entry.ver,
        versionMatch: entry.ver === CACHE_VERSION,
      })
    }
    catch {
      // skip corrupt entries
    }
  }

  return entries
}
