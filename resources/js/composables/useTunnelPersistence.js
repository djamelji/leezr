import { watch } from 'vue'

/**
 * Tunnel state persistence composable — sessionStorage-based.
 *
 * Persists form state across page refreshes during registration.
 * Automatically debounces saves to avoid excessive writes.
 *
 * @param {string} key - Storage key (e.g. 'leezr_registration')
 */
export function useTunnelPersistence(key = 'leezr_registration_tunnel') {
  /**
   * Save state to sessionStorage.
   * @param {Record<string, any>} state
   */
  function save(state) {
    try {
      sessionStorage.setItem(key, JSON.stringify(state))
    }
    catch {
      // sessionStorage full or unavailable — fail silently
    }
  }

  /**
   * Load state from sessionStorage.
   * @returns {Record<string, any>|null}
   */
  function load() {
    try {
      const raw = sessionStorage.getItem(key)

      return raw ? JSON.parse(raw) : null
    }
    catch {
      return null
    }
  }

  /**
   * Clear persisted state.
   */
  function clear() {
    try {
      sessionStorage.removeItem(key)
    }
    catch {
      // fail silently
    }
  }

  /**
   * Auto-save a reactive state object with debounce.
   * @param {import('vue').Ref|import('vue').Reactive} reactiveState - Reactive object to watch
   * @param {number} debounceMs - Debounce delay in ms (default 500)
   * @returns {import('vue').WatchStopHandle} - Stop handle to cancel watching
   */
  function autoSave(reactiveState, debounceMs = 500) {
    let timeout = null

    return watch(
      reactiveState,
      newVal => {
        if (timeout)
          clearTimeout(timeout)
        timeout = setTimeout(() => {
          save(JSON.parse(JSON.stringify(newVal)))
        }, debounceMs)
      },
      { deep: true },
    )
  }

  return { save, load, clear, autoSave }
}
