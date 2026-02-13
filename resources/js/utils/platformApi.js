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
  async onResponseError({ request, response, options }) {
    const status = response.status

    // 401 Unauthenticated — redirect to platform login
    if (status === 401) {
      useCookie('platformUserData').value = null
      useCookie('platformRoles').value = null
      useCookie('platformPermissions').value = null

      const currentPath = window.location.pathname
      if (currentPath !== '/platform/login') {
        window.location.href = '/platform/login'
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
