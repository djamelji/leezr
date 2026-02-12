import { ofetch } from 'ofetch'

function getXsrfToken() {
  const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/)
  if (!match)
    return null

  return decodeURIComponent(match[1])
}

async function refreshCsrf() {
  await fetch('/sanctum/csrf-cookie', {
    credentials: 'include',
    headers: { Accept: 'application/json' },
  })
}

export const $platformApi = ofetch.create({
  baseURL: '/api/platform',
  credentials: 'include',
  headers: {
    Accept: 'application/json',
  },
  async onRequest({ options }) {
    options.credentials = 'include'

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
