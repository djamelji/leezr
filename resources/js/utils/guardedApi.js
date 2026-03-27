// ADR-418: $guardedApi — defense-in-depth for cross-permission API calls
// NOT the primary RBAC authority (that's the router guard).
// Use this ONLY for store actions called from pages with a different permission.
import { $api } from '@/utils/api'
import { useAuthStore } from '@/core/stores/auth'

export function $guardedApi(permission, url, options = {}) {
  const auth = useAuthStore()

  if (!auth.hasPermission(permission)) {
    if (import.meta.env.DEV) {
      console.warn(`[guardedApi] Blocked: ${url} — missing: ${permission}`)
    }

    return Promise.resolve(null)
  }

  return $api(url, options)
}
