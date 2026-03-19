import { ofetch } from 'ofetch'
import { useAppToast } from '@/composables/useAppToast'
import { getXsrfToken, refreshCsrf } from '@/utils/csrf'
import { safeRedirect } from '@/utils/safeRedirect'

function getCurrentCompanyId() {
  return useCookie('currentCompanyId').value
}

export const $api = ofetch.create({
  baseURL: import.meta.env.VITE_API_BASE_URL || '/api',
  credentials: 'include',
  headers: {
    Accept: 'application/json',
  },
  async onRequest({ options }) {
    const xsrfToken = getXsrfToken()
    if (xsrfToken) {
      options.headers = options.headers || {}
      if (options.headers instanceof Headers) {
        options.headers.set('X-XSRF-TOKEN', xsrfToken)
      }
      else {
        options.headers['X-XSRF-TOKEN'] = xsrfToken
      }
    }

    const companyId = getCurrentCompanyId()
    if (companyId) {
      options.headers = options.headers || {}
      if (options.headers instanceof Headers) {
        options.headers.set('X-Company-Id', String(companyId))
      }
      else {
        options.headers['X-Company-Id'] = String(companyId)
      }
    }

    const locale = useCookie('language').value
    if (locale) {
      options.headers = options.headers || {}
      if (options.headers instanceof Headers) {
        options.headers.set('X-Locale', locale)
      }
      else {
        options.headers['X-Locale'] = locale
      }
    }
  },
  onResponse({ response }) {
    // Session TTL resync — dispatch to governance composable (zero coupling)
    const ttl = response.headers.get('x-session-ttl')
    if (ttl && typeof window !== 'undefined') {
      window.dispatchEvent(new CustomEvent('lzr:session-ttl', {
        detail: { ttl: parseInt(ttl, 10) },
      }))
    }

    // Build version mismatch detection (ADR-341: deduped, respects overlay state)
    const serverVersion = response.headers.get('x-build-version')
    if (!serverVersion || serverVersion === 'dev') return

    const clientVersion = import.meta.env.VITE_APP_VERSION
    if (!clientVersion || clientVersion === '__dev__') return

    if (serverVersion !== clientVersion && !sessionStorage.getItem('lzr:update-shown')) {
      sessionStorage.setItem('lzr:version-mismatch', serverVersion)
    }
  },
  async onResponseError({ request, response, options }) {
    const status = response.status
    const { toast } = useAppToast()

    // 401 Unauthenticated — purge + broadcast + show expired dialog
    if (status === 401) {
      if (options._authCheck) return

      // Broadcast session-expired to all tabs
      const { postBroadcast } = await import('@/core/runtime/broadcast')
      postBroadcast('session-expired')

      useCookie('userData').value = null
      useCookie('currentCompanyId').value = null

      try {
        const { useRuntimeStore } = await import('@/core/runtime/runtime')
        useRuntimeStore().teardown()
      } catch {}

      // Show expired dialog instead of hard redirect (ADR-358b)
      const { useSessionExpired } = await import('@/composables/useSessionExpired')
      useSessionExpired().trigger('/login')

      return
    }

    // 419 CSRF token mismatch — refresh token and retry once
    if (status === 419 && !options._retried) {
      await refreshCsrf()
      options._retried = true

      // Clear stale XSRF token — onRequest will inject the fresh one
      if (options.headers instanceof Headers) {
        options.headers.delete('X-XSRF-TOKEN')
      }
      else if (options.headers) {
        delete options.headers['X-XSRF-TOKEN']
      }

      return $api(request, options)
    }

    // 403 Forbidden
    if (status === 403) {
      if (!options._silent403)
        toast(response._data?.message || 'Unauthorized action.', 'error')

      return
    }

    // 500+ Server errors — prefer backend message when available
    if (status >= 500) {
      toast(response._data?.message || 'An unexpected error occurred. Please try again.', 'error')
    }
  },
})
