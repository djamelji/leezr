import { createFetch } from '@vueuse/core'
import { destr } from 'destr'

function getXsrfToken() {
  const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/)
  if (!match)
    return null

  return decodeURIComponent(match[1])
}

function getCurrentCompanyId() {
  return useCookie('currentCompanyId').value
}

export const useApi = createFetch({
  baseUrl: import.meta.env.VITE_API_BASE_URL || '/api',
  fetchOptions: {
    headers: {
      Accept: 'application/json',
    },
    credentials: 'include',
  },
  options: {
    refetch: true,
    async beforeFetch({ options }) {
      const headers = { ...options.headers }

      const xsrfToken = getXsrfToken()
      if (xsrfToken)
        headers['X-XSRF-TOKEN'] = xsrfToken

      const companyId = getCurrentCompanyId()
      if (companyId)
        headers['X-Company-Id'] = String(companyId)

      options.headers = headers

      return { options }
    },
    afterFetch(ctx) {
      const { data, response } = ctx

      let parsedData = null
      try {
        parsedData = destr(data)
      }
      catch (error) {
        console.error(error)
      }

      return { data: parsedData, response }
    },
  },
})
