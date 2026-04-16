import { computed, onUnmounted, ref, watch } from 'vue'
import { onBeforeRouteLeave } from 'vue-router'
import { useI18n } from 'vue-i18n'

function deepEqual(a, b) {
  if (a === b) return true
  if (a == null || b == null) return a === b
  if (typeof a !== typeof b) return false
  if (a instanceof Date && b instanceof Date) return a.getTime() === b.getTime()
  if (typeof a !== 'object') return false
  if (Array.isArray(a) !== Array.isArray(b)) return false
  const keysA = Object.keys(a)
  const keysB = Object.keys(b)
  if (keysA.length !== keysB.length) return false

  return keysA.every(key => deepEqual(a[key], b[key]))
}

/**
 * Warn user before navigating away with unsaved changes.
 *
 * @param {Ref|ComputedRef} formData  - reactive form state
 * @param {Ref}             original  - snapshot of the clean state
 * @param {Object}          [options]
 * @param {Function}        [options.compareFn]  - custom (current, original) => boolean
 * @param {Ref<boolean>}    [options.enabled]    - toggle guard (default: always on)
 * @param {string}          [options.message]    - custom confirm message
 */
export function useUnsavedChanges(formData, original, options = {}) {
  const { t } = useI18n()

  const enabled = options.enabled ?? ref(true)
  const message = options.message ?? (() => t('common.unsavedChanges'))

  const isDirty = computed(() => {
    if (!enabled.value) return false
    if (options.compareFn) return options.compareFn(formData.value, original.value)

    return !deepEqual(formData.value, original.value)
  })

  // vue-router guard
  onBeforeRouteLeave(() => {
    if (isDirty.value) {
      const msg = typeof message === 'function' ? message() : message

      return window.confirm(msg)
    }
  })

  // browser close / refresh guard
  const beforeUnload = e => {
    if (isDirty.value) {
      e.preventDefault()
      e.returnValue = ''
    }
  }

  window.addEventListener('beforeunload', beforeUnload)
  onUnmounted(() => window.removeEventListener('beforeunload', beforeUnload))

  function reset() {
    original.value = JSON.parse(JSON.stringify(formData.value))
  }

  function markClean() {
    reset()
  }

  return { isDirty, reset, markClean }
}
