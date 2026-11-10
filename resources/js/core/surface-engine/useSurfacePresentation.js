import { ref, computed, onMounted, onBeforeUnmount } from 'vue'
import { resolvePresentationMode } from './presentationMode'

/**
 * Composable d'auto-présentation d'un widget (ADR-750, généralisation du Presentation
 * Engine ADR-748b). Mesure la racine du widget via un ResizeObserver et dérive le MODE
 * partagé `resolvePresentationMode(w, h)` — source autoritaire (fonctionne aussi hors
 * grille), avec repli sur `viewport.mode` avant la 1re mesure.
 *
 * Ce n'est PAS un moteur parallèle : il réutilise la SEULE fonction de mode (ADR-748) et
 * le même ResizeObserver que le widget commercial de référence. On factorise simplement
 * la mesure pour que chaque widget graphique l'adopte sans dupliquer le code.
 *
 * @param {import('vue').Ref<HTMLElement|null>} rootRef  ref du conteneur racine du widget
 * @param {object} [opts]
 * @param {import('vue').Ref<object>|object} [opts.viewport]  viewport fourni par DashboardGrid (repli)
 * @returns {{ rootW, rootH, mode, isMicro, isCompact, isStandard, isWide, isExpanded }}
 */
export function useSurfacePresentation(rootRef, opts = {}) {
  const rootW = ref(0)
  const rootH = ref(0)
  let ro = null

  onMounted(() => {
    if (!rootRef.value) return
    ro = new ResizeObserver(es => {
      const r = es[0]?.contentRect
      if (r) { rootW.value = Math.round(r.width); rootH.value = Math.round(r.height) }
    })
    ro.observe(rootRef.value)
  })
  onBeforeUnmount(() => { if (ro) ro.disconnect() })

  const fallbackMode = () => {
    const vp = opts.viewport
    const v = vp && typeof vp === 'object' && 'value' in vp ? vp.value : vp

    return v?.mode || 'standard'
  }

  const mode = computed(() => (rootW.value > 0 && rootH.value > 0)
    ? resolvePresentationMode(rootW.value, rootH.value)
    : fallbackMode())

  return {
    rootW,
    rootH,
    mode,
    isMicro: computed(() => mode.value === 'micro'),
    isCompact: computed(() => mode.value === 'compact'),
    isStandard: computed(() => mode.value === 'standard'),
    isWide: computed(() => mode.value === 'wide'),
    isExpanded: computed(() => mode.value === 'expanded'),
  }
}
