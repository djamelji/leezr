import { ref, computed } from 'vue'

// Shared singleton state across all instances
const activeCount = ref(0)

/**
 * useGlobalLoading — lightweight global loading indicator driven by a shared counter.
 *
 * Any consumer can call start()/stop() or wrap(asyncFn).
 * AppLoadingIndicator.vue watches `isLoading` to show/hide the progress bar.
 *
 * NOT a Pinia store on purpose: a simple ref counter is all that's needed.
 */
export function useGlobalLoading() {
  const isLoading = computed(() => activeCount.value > 0)

  function start() {
    activeCount.value++
  }

  function stop() {
    if (activeCount.value > 0) activeCount.value--
  }

  /**
   * Convenience wrapper: starts loading, awaits the function, stops loading.
   * @param {Function} asyncFn — async function to execute
   * @returns {Promise<*>} — the resolved value
   */
  async function wrap(asyncFn) {
    start()
    try {
      return await asyncFn()
    }
    finally {
      stop()
    }
  }

  return { isLoading, start, stop, wrap, activeCount }
}
