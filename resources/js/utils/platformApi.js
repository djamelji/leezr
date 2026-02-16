import { ofetch } from 'ofetch'
import { getXsrfToken, refreshCsrf } from '@/utils/csrf'
import { getActiveSignal } from '@/core/runtime/abortRegistry'

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

    // Attach runtime abort signal if active and none already set
    const runtimeSignal = getActiveSignal()
    if (runtimeSignal && !options.signal) {
      options.signal = runtimeSignal
    }
  },
  onResponse({ response }) {
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

    // 401 Unauthenticated — redirect to platform login
    if (status === 401) {
      // During auth bootstrap (fetchMe), let the guard handle redirect
      if (options._authCheck)
        return

      useCookie('platformUserData').value = null
      useCookie('platformRoles').value = null
      useCookie('platformPermissions').value = null

      const { router } = await import('@/plugins/1.router')
      const currentPath = router.currentRoute.value.path

      if (currentPath !== '/platform/login') {
        router.push('/platform/login')
      }

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
