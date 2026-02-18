import { ofetch } from 'ofetch'
import { getXsrfToken, refreshCsrf } from '@/utils/csrf'

export const $platformApi = ofetch.create({
  baseURL: '/api/platform',
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

  },
  onResponse({ response }) {
    // Session TTL resync — dispatch to governance composable (zero coupling)
    const ttl = response.headers.get('x-session-ttl')
    if (ttl && typeof window !== 'undefined') {
      window.dispatchEvent(new CustomEvent('lzr:session-ttl', {
        detail: { ttl: parseInt(ttl, 10) },
      }))
    }

    // Build version mismatch detection
    const serverVersion = response.headers.get('x-build-version')
    if (!serverVersion || serverVersion === 'dev') return

    const clientVersion = import.meta.env.VITE_APP_VERSION
    if (!clientVersion || clientVersion === '__dev__') return

    if (serverVersion !== clientVersion) {
      sessionStorage.setItem('lzr:version-mismatch', serverVersion)
    }
  },
  async onResponseError({ request, response, options }) {
    const status = response.status

    // 401 Unauthenticated — purge + broadcast + hard redirect
    if (status === 401) {
      if (options._authCheck) return

      // Broadcast session-expired to all tabs (synchronous — fires before redirect)
      const { postBroadcast } = await import('@/core/runtime/broadcast')
      postBroadcast('session-expired')

      useCookie('platformUserData').value = null
      useCookie('platformRoles').value = null
      useCookie('platformPermissions').value = null

      try {
        const { useRuntimeStore } = await import('@/core/runtime/runtime')
        useRuntimeStore().teardown()
      } catch {}

      // Hard redirect (not router.push) for full JS cleanup
      window.location.href = '/platform/login'

      return
    }

    // 419 CSRF token mismatch — refresh token and retry once
    if (status === 419 && !options._retried) {
      await refreshCsrf()
      options._retried = true

      return $platformApi(request, options)
    }
  },
})
