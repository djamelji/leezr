/**
 * Centralized CSRF helpers — single source of truth.
 * Consumed by api.js and platformApi.js.
 */

export function getXsrfToken() {
  const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/)
  if (!match)
    return null

  return decodeURIComponent(match[1])
}

export async function refreshCsrf() {
  await fetch('/sanctum/csrf-cookie', {
    credentials: 'include',
    headers: { Accept: 'application/json' },
  })
}
