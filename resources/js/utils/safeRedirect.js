/**
 * Validates that a redirect path is same-origin (relative path only).
 * Prevents open redirect attacks via ?redirect= query parameter.
 *
 * @param {string} redirect — raw redirect value from query string
 * @param {string} fallback — safe default if redirect is invalid
 * @returns {string} — safe redirect path
 */
export function safeRedirect(redirect, fallback = '/dashboard') {
  if (!redirect || typeof redirect !== 'string')
    return fallback

  // Must start with / and must NOT start with // (protocol-relative URL)
  if (!redirect.startsWith('/') || redirect.startsWith('//'))
    return fallback

  // Block any URL with a protocol scheme (e.g. javascript:, data:)
  try {
    const url = new URL(redirect, window.location.origin)
    if (url.origin !== window.location.origin)
      return fallback
  }
  catch {
    return fallback
  }

  return redirect
}

/**
 * ADR-357: Resolve post-login redirect.
 * 1. Check sessionStorage for deep link (set by router guard)
 * 2. Otherwise, redirect to workspace landing page
 *
 * @param {string} workspace — 'dashboard' or 'home' (from backend)
 * @returns {string} — safe redirect path
 */
export function resolvePostLoginRedirect(workspace = 'dashboard') {
  const savedRedirect = sessionStorage.getItem('auth_redirect')
  sessionStorage.removeItem('auth_redirect')

  if (savedRedirect) {
    return safeRedirect(savedRedirect, workspace === 'home' ? '/home' : '/dashboard')
  }

  return workspace === 'home' ? '/home' : '/dashboard'
}
