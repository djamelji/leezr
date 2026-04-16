// ADR-433: Global directives registration
import { useAuthStore } from '@/core/stores/auth'

/**
 * v-can directive — hides element if user lacks the specified permission.
 *
 * Usage:
 *   <VBtn v-can="'roles.manage'">Edit</VBtn>
 *   <VBtn v-can="['roles.manage', 'settings.manage']">Config</VBtn>
 *
 * Single string: checks that single permission.
 * Array: checks ANY permission (OR logic).
 */
export default function registerDirectives(app) {
  app.directive('can', {
    mounted(el, binding) {
      updateVisibility(el, binding)
    },
    updated(el, binding) {
      updateVisibility(el, binding)
    },
  })
}

function updateVisibility(el, binding) {
  const auth = useAuthStore()
  const value = binding.value

  let hasAccess = false

  if (Array.isArray(value)) {
    hasAccess = value.some(p => auth.hasPermission(p))
  }
  else if (typeof value === 'string') {
    hasAccess = auth.hasPermission(value)
  }

  if (!hasAccess) {
    el.style.display = 'none'
  }
  else {
    el.style.display = ''
  }
}
