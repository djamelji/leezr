// Leezr 5.0 DESIGN LAB (prototype, not wired to production pages).
// Progressive counter for KPI values: animates ONCE on first display, then follows data updates instantly
// (no artificial animation on every refresh). Honors prefers-reduced-motion.
import { usePreferredReducedMotion, useTransition } from '@vueuse/core'

export function useCountUp(source, { duration = 900 } = {}) {
  const reduced = usePreferredReducedMotion()
  const first = ref(true)
  const target = ref(0)

  const output = useTransition(target, {
    duration: computed(() => (reduced.value === 'reduce' || !first.value ? 0 : duration)),
    transition: [0.22, 1, 0.36, 1],
  })

  watch(() => unref(source), v => {
    target.value = Number(v) || 0
  }, { immediate: false })

  onMounted(() => {
    requestAnimationFrame(() => {
      target.value = Number(unref(source)) || 0
      setTimeout(() => { first.value = false }, duration + 50)
    })
  })

  return output
}
