import { onBeforeUnmount, onMounted, ref } from 'vue'

/**
 * SMART RESPONSIVE UX 2026 — observe la largeur RÉELLE d'un conteneur (pas le viewport).
 * Permet un comportement adaptatif « container-query-like » : un composant peut être étroit
 * même sur desktop (drawer, dialog, split view, panneau). ResizeObserver + rAF (anti-thrash).
 *
 * @param {import('vue').Ref<HTMLElement|null>} elRef
 * @returns {{ width: import('vue').Ref<number> }}
 */
export function useContainerWidth(elRef) {
  const width = ref(0)
  let ro = null
  let frame = null

  const measure = () => {
    if (frame) cancelAnimationFrame(frame)
    frame = requestAnimationFrame(() => {
      const el = elRef?.value
      if (el) width.value = el.clientWidth
    })
  }

  onMounted(() => {
    measure()
    if (typeof ResizeObserver !== 'undefined' && elRef?.value) {
      ro = new ResizeObserver(measure)
      ro.observe(elRef.value)
    }
  })

  onBeforeUnmount(() => {
    if (frame) cancelAnimationFrame(frame)
    ro?.disconnect()
  })

  return { width }
}
