/**
 * Composable for Help Center header — detects authenticated spaces
 * and provides return destinations.
 *
 * Uses useCookie (same library that serializes cookies) to ensure
 * consistent encoding/decoding. Also reactive — re-evaluates if
 * cookies change during the session.
 *
 * To add a new space (e.g. client portal):
 *   1. Add an entry to SPACES with cookie name, URL, label key, icon
 *   2. The header will automatically show a return button for it
 */

const SPACES = [
  {
    key: 'platform',
    cookie: 'platformUserData',
    url: '/platform/',
    labelKey: 'documentation.returnToPlatform',
    icon: 'tabler-shield-chevron',
  },
  {
    key: 'company',
    cookie: 'userData',
    url: '/dashboard',
    labelKey: 'documentation.returnToSpace',
    icon: 'tabler-building',
  },
  // Future: { key: 'client', cookie: 'clientUserData', url: '/portal/', labelKey: 'helpCenter.returnToPortal', icon: 'tabler-user' },
]

export function useReturnNavigation() {
  // Read cookies via useCookie — same encode/decode as auth stores
  const cookieRefs = Object.fromEntries(
    SPACES.map(space => [space.key, useCookie(space.cookie)]),
  )

  const activeSpaces = computed(() =>
    SPACES.filter(space => !!cookieRefs[space.key].value),
  )

  const isAuthenticated = computed(() => activeSpaces.value.length > 0)

  return { activeSpaces, isAuthenticated }
}
