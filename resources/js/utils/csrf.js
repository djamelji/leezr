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

  // ADR-331: Micro-délai pour laisser le browser traiter le Set-Cookie header.
  // Sans cela, getXsrfToken() peut retourner l'ancien token ou null,
  // causant un 419 rattrapé par le retry mais ajoutant de la latence.
  await new Promise(resolve => setTimeout(resolve, 50))
}
