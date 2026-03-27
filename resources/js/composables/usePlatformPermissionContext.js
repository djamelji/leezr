// ADR-418: Platform RBAC permission context — explicit, deterministic
import { usePlatformAuthStore } from '@/core/stores/platformAuth'

export function usePlatformPermissionContext() {
  const platformAuth = usePlatformAuthStore()

  const can = permission => platformAuth.hasPermission(permission)

  function checkAccess(meta) {
    if (meta.permission && !can(meta.permission))
      return { allowed: false, reason: 'permission_denied' }

    return { allowed: true }
  }

  return { can, checkAccess }
}
