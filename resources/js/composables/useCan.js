// ADR-433: RBAC frontend composable — wraps auth store permissions
import { useAuthStore } from '@/core/stores/auth'
import { useModuleStore } from '@/core/stores/module'

/**
 * Composable for permission checking in company context.
 *
 * @example
 * const { can, canAny, canAll, isOwner, isAdmin } = useCan()
 * if (can('roles.manage')) { ... }
 * if (canAny(['roles.view', 'settings.view'])) { ... }
 */
export function useCan() {
  const auth = useAuthStore()
  const moduleStore = useModuleStore()

  /** Check a single permission */
  const can = permission => auth.hasPermission(permission)

  /** Check ALL permissions are granted */
  const canAll = permissions => permissions.every(p => auth.hasPermission(p))

  /** Check ANY permission is granted */
  const canAny = permissions => permissions.some(p => auth.hasPermission(p))

  /** Check if a module is active for the current company */
  const canModule = key => moduleStore.isActive(key)

  /** Current user is owner of current company */
  const isOwner = computed(() => auth.isOwner)

  /** Current user has administrative role (owner or admin archetype) */
  const isAdmin = computed(() => auth.isAdministrative)

  return { can, canAll, canAny, canModule, isOwner, isAdmin }
}
