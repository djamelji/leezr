import { ref } from 'vue'

// Global reactive flag — shared across all components
const isSessionExpired = ref(false)
const loginUrl = ref('/login')

export function useSessionExpired() {
  function trigger(url = '/login') {
    loginUrl.value = url
    isSessionExpired.value = true
  }

  function reset() {
    isSessionExpired.value = false
  }

  return {
    isSessionExpired,
    loginUrl,
    trigger,
    reset,
  }
}
