import { ofetch } from 'ofetch'

function getXsrfToken() {
  const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/)
  if (!match)
    return null

  return decodeURIComponent(match[1])
}

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
  },
})
