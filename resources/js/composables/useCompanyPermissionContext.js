// ADR-418: Company RBAC permission context — explicit, deterministic
import { useAuthStore } from '@/core/stores/auth'
import { useModuleStore } from '@/core/stores/module'

export function useCompanyPermissionContext() {
  const auth = useAuthStore()
  const moduleStore = useModuleStore()

  const isOwner = computed(() => auth.isOwner)
  const can = permission => auth.hasPermission(permission)
  const moduleActive = key => moduleStore.isActive(key)

  function checkAccess(meta) {
    if (meta.module && !moduleActive(meta.module))
      return { allowed: false, reason: 'module_inactive' }
    if (meta.permission && !can(meta.permission))
      return { allowed: false, reason: 'permission_denied' }

    return { allowed: true }
  }

  return { can, isOwner, moduleActive, checkAccess }
}
