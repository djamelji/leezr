import { ofetch } from 'ofetch'
import { useAppToast } from '@/composables/useAppToast'
import { getXsrfToken, refreshCsrf } from '@/utils/csrf'
import { safeRedirect } from '@/utils/safeRedirect'
import { getActiveSignal } from '@/core/runtime/abortRegistry'

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

    // Attach runtime abort signal if active and none already set
    const runtimeSignal = getActiveSignal()
    if (runtimeSignal && !options.signal) {
      options.signal = runtimeSignal
    }
  },
  async onResponseError({ request, response, options }) {
    const status = response.status
    const { toast } = useAppToast()

    // 401 Unauthenticated — session expired
    if (status === 401) {
      // During auth bootstrap (fetchMe), let the guard handle redirect
      if (options._authCheck)
        return

      useCookie('userData').value = null
      useCookie('currentCompanyId').value = null

      const { router } = await import('@/plugins/1.router')
      const currentPath = router.currentRoute.value.fullPath

      if (currentPath !== '/login') {
        router.push({ path: '/login', query: { redirect: safeRedirect(currentPath) } })
      }

      return
    }

    // 419 CSRF token mismatch — refresh token and retry once
    if (status === 419 && !options._retried) {
      await refreshCsrf()
      options._retried = true

      return $api(request, options)
    }

    // 403 Forbidden
    if (status === 403) {
      toast(response._data?.message || 'Unauthorized action.', 'error')

      return
    }

    // 500+ Server errors
    if (status >= 500) {
      toast('An unexpected error occurred. Please try again.', 'error')
    }
  },
})
