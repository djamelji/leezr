/**
 * Resource declarations — what to fetch, when, and how.
 *
 * Each resource maps to a store action. The runtime resolves them
 * in dependency order, with parallel execution where possible.
 *
 * @typedef {Object} ResourceDef
 * @property {string}   key         - Unique identifier (also cache key)
 * @property {string}   phase       - 'auth' | 'tenant' | 'features'
 * @property {string}   store       - Pinia store id
 * @property {string}   action      - Store action name to call
 * @property {string}   abortGroup  - AbortController group
 * @property {number}   ttl         - Cache TTL in ms (0 = no cache)
 * @property {string[]} dependsOn   - Resource keys that must resolve first
 * @property {boolean}  critical    - If true, failure → error phase
 * @property {boolean}  [cacheable] - If false, never cache (default true)
 */

/** @type {ResourceDef[]} */
export const companyResources = [
  {
    key: 'auth:me',
    phase: 'auth',
    store: 'auth',
    action: 'fetchMe',
    abortGroup: 'auth',
    ttl: 0,
    dependsOn: [],
    critical: true,
    cacheable: false, // Session must always be validated against server
  },
  {
    key: 'auth:companies',
    phase: 'tenant',
    store: 'auth',
    action: 'fetchMyCompanies',
    abortGroup: 'tenant',
    ttl: 5 * 60 * 1000, // 5 minutes
    dependsOn: ['auth:me'],
    critical: true,
  },
  {
    key: 'tenant:jobdomain',
    phase: 'tenant',
    store: 'jobdomain',
    action: 'fetchJobdomain',
    abortGroup: 'tenant',
    ttl: 10 * 60 * 1000, // 10 minutes
    dependsOn: ['auth:me'],
    critical: false, // Non-blocking — app works with default jobdomain
  },
  {
    key: 'features:modules',
    phase: 'features',
    store: 'module',
    action: 'fetchModules',
    abortGroup: 'features',
    ttl: 5 * 60 * 1000, // 5 minutes
    dependsOn: ['auth:companies'], // Needs X-Company-Id header set
    critical: false, // Nav falls back to static items
  },
]

/** @type {ResourceDef[]} */
export const platformResources = [
  {
    key: 'platform:me',
    phase: 'auth',
    store: 'platformAuth',
    action: 'fetchMe',
    abortGroup: 'auth',
    ttl: 0,
    dependsOn: [],
    critical: true,
    cacheable: false,
  },
]
