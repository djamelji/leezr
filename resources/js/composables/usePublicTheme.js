import { applyTheme } from '@/composables/useApplyTheme'
import { applyTypography } from '@/composables/useApplyTypography'
import { setAppName } from '@/composables/useAppName'

let fetched = false

export function usePublicTheme() {
  if (fetched) return

  onMounted(async () => {
    if (fetched) return
    fetched = true

    try {
      const res = await fetch('/api/public/theme')

      if (!res.ok) return

      const data = await res.json()

      if (data.app_name) {
        setAppName(data.app_name)
      }
      if (data.primary_color) {
        applyTheme({
          primary_color: data.primary_color,
          primary_darken_color: data.primary_darken_color,
        })
      }
      if (data.typography) {
        applyTypography(data.typography)
      }
    }
    catch {
      // Use defaults
    }
  })
}
