import { ref, onBeforeUnmount, watch } from 'vue'

/**
 * Hauteur DISPONIBLE mesurée d'une zone graphe (ADR-750, pattern ADR-748b factorisé).
 *
 * Renvoie une hauteur px STABLE : immédiate au 1er rendu, puis débouncée lors d'un resize
 * continu (évite tout churn de `:height` pendant le drag — seule la largeur suit, gérée
 * nativement par LzrApexChart). À utiliser avec un hôte en `position:relative; flex:1 1 auto;
 * min-block-size:0` dont le graphe est en `position:absolute; inset:0` → la hauteur du graphe
 * ne rétro-agit jamais sur le flux flex (la cause racine du bug « graphe à 0px » d'ADR-748b).
 *
 * IMPORTANT (ADR-750) : l'hôte graphe vit souvent dans une branche `v-if`/`v-else-if`
 * (modes micro/compact) ABSENTE au montage. On attache donc le ResizeObserver via
 * `watch(hostRef)` (flush 'post') pour le (ré)observer dès que l'élément apparaît — un
 * simple `onMounted` observerait `null` et ne mesurerait jamais.
 *
 * @param {import('vue').Ref<HTMLElement|null>} hostRef
 * @param {object} [opts]
 * @param {number} [opts.min=40]       plancher sous lequel on ignore la mesure
 * @param {number} [opts.debounce=160] délai de stabilisation (ms)
 * @returns {import('vue').Ref<number>} hauteur stable en px
 */
export function useMeasuredBlockSize(hostRef, opts = {}) {
  const min = opts.min ?? 40
  const debounce = opts.debounce ?? 160
  const raw = ref(0)
  const stable = ref(0)
  let ro = null
  let timer = null

  const attach = el => {
    if (ro) { ro.disconnect(); ro = null }
    if (!el) return
    ro = new ResizeObserver(es => { const r = es[0]?.contentRect; if (r) raw.value = Math.round(r.height) })
    ro.observe(el)
  }

  // (Ré)attache dès que l'élément hôte apparaît/change dans le DOM (branches v-if).
  watch(hostRef, el => attach(el), { immediate: true, flush: 'post' })
  onBeforeUnmount(() => { if (ro) ro.disconnect(); clearTimeout(timer) })

  watch(raw, v => {
    if (v < min) return
    if (stable.value < min) { stable.value = v; return } // 1er rendu : immédiat
    clearTimeout(timer)
    timer = setTimeout(() => { stable.value = v }, debounce) // resize : contrôlé
  }, { immediate: true })

  return stable
}
