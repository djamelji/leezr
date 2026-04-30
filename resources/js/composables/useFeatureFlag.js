import { $api } from '@/utils/api'

const _flags = ref(null)
const _loading = ref(false)

export function useFeatureFlag() {
  const fetchFlags = async () => {
    if (_flags.value !== null || _loading.value) return
    _loading.value = true
    try {
      _flags.value = await $api('/feature-flags')
    } catch {
      _flags.value = {}
    } finally {
      _loading.value = false
    }
  }

  const isEnabled = key => {
    return _flags.value?.[key] ?? false
  }

  // Auto-fetch on first use
  if (_flags.value === null && !_loading.value) {
    fetchFlags()
  }

  return {
    flags: readonly(_flags),
    isEnabled,
    fetchFlags,
  }
}
