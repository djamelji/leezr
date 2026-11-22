// ADR-433: Global directives registration
import { useAuthStore } from '@/core/stores/auth'
import { usePlatformAuthStore } from '@/core/stores/platformAuth'

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

  // ADR-863 — Platform twin of v-can (read-only catalog roles) : `v-platform-can="'manage_modules'"` hides a write
  // control ; `v-platform-can:disable="…"` keeps it visible (a switch shows the state) but inert. The server refuses
  // the write anyway (403) : this only keeps a reader from meeting buttons that cannot work.
  const platformCan = (el, binding) => {
    const auth = usePlatformAuthStore()
    const ok = [].concat(binding.value).some(p => auth.hasPermission(p))
    if (binding.arg === 'disable') {
      el.style.pointerEvents = ok ? '' : 'none'
      el.style.opacity = ok ? '' : '0.6'
      el.setAttribute('aria-disabled', ok ? 'false' : 'true')
      if (!ok) el.querySelectorAll('input,button').forEach(x => { x.disabled = true }) // never re-enables what Vue disabled
    }
    else if (!ok) {
      el.style.setProperty('display', 'none', 'important')
    }
    else {
      el.style.removeProperty('display')
    }
  }

  app.directive('platform-can', { mounted: platformCan, updated: platformCan })
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

  // ADR-786 : `!important`, sinon une classe utilitaire Vuetify (`d-flex`, `d-inline-flex`… déclarées
  // `display: … !important`) l'emporte et l'élément reste visible malgré l'absence de permission.
  if (!hasAccess) {
    el.style.setProperty('display', 'none', 'important')
  }
  else {
    el.style.removeProperty('display')
  }
}
