import { ref, computed, onMounted } from 'vue'
import { useGlobalLoading } from '@/composables/useGlobalLoading'

/**
 * useAsyncState — standardized async loading / error / data / retry orchestration.
 *
 * Zero business logic. Works with any async function (useApi, plain fetch, etc.).
 *
 * @param {Function} asyncFn  — async function that returns data
 * @param {Object}   options
 * @param {boolean}  options.immediate       — execute on mount (default: false)
 * @param {*}        options.initialData      — initial value for data (default: null)
 * @param {boolean}  options.resetOnExecute   — reset data before each execute (default: false)
 * @param {Function} options.onError          — callback on error
 * @param {boolean}  options.globalLoading    — show global loading indicator (default: false)
 *
 * @returns {{ data: Ref, error: Ref, isLoading: Ref, isError: ComputedRef, isEmpty: ComputedRef, execute: Function, retry: Function }}
 */
export function useAsyncState(asyncFn, options = {}) {
  const {
    immediate = false,
    initialData = null,
    resetOnExecute = false,
    onError = null,
    globalLoading: showGlobalLoading = false,
  } = options

  const globalLoadingInstance = showGlobalLoading ? useGlobalLoading() : null

  const data = ref(initialData)
  const error = ref(null)
  const isLoading = ref(false)
  let lastArgs = []

  const isError = computed(() => error.value !== null)

  const isEmpty = computed(() => {
    if (data.value === null || data.value === undefined) return true
    if (Array.isArray(data.value)) return data.value.length === 0
    if (typeof data.value === 'object') return Object.keys(data.value).length === 0
    return false
  })

  async function execute(...args) {
    lastArgs = args
    isLoading.value = true
    error.value = null

    if (resetOnExecute) data.value = initialData
    if (globalLoadingInstance) globalLoadingInstance.start()

    try {
      data.value = await asyncFn(...args)
    }
    catch (e) {
      error.value = e?.response?.data?.message || e?.data?.message || e?.message || String(e)
      if (onError) onError(e)
    }
    finally {
      if (globalLoadingInstance) globalLoadingInstance.stop()
      isLoading.value = false
    }
  }

  function retry() {
    return execute(...lastArgs)
  }

  if (immediate) {
    onMounted(() => execute())
  }

  return {
    data,
    error,
    isLoading,
    isError,
    isEmpty,
    execute,
    retry,
  }
}
