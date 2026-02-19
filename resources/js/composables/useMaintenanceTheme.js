import { useTheme } from 'vuetify'
import underConstructionData from '@/assets/lottie/under-construction.json'
import arrowData from '@/assets/lottie/arrow.json'

/**
 * Recolors maintenance animations to match the Vuetify theme primary color.
 * Reactively updates when the theme changes (no refresh needed).
 */
export function useMaintenanceTheme() {
  const { current } = useTheme()

  function getPrimaryRgb01() {
    const hex = current.value.colors.primary

    return [
      parseInt(hex.slice(1, 3), 16) / 255,
      parseInt(hex.slice(3, 5), 16) / 255,
      parseInt(hex.slice(5, 7), 16) / 255,
    ]
  }

  function rgbToHsl(r, g, b) {
    const mx = Math.max(r, g, b)
    const mn = Math.min(r, g, b)
    const l = (mx + mn) / 2

    if (mx === mn)
      return [0, 0, l]

    const d = mx - mn
    const s = l > 0.5 ? d / (2 - mx - mn) : d / (mx + mn)
    let h

    if (mx === r) h = ((g - b) / d + (g < b ? 6 : 0)) / 6
    else if (mx === g) h = ((b - r) / d + 2) / 6
    else h = ((r - g) / d + 4) / 6

    return [h, s, l]
  }

  function hslToRgb(h, s, l) {
    if (s === 0)
      return [l, l, l]

    const q = l < 0.5 ? l * (1 + s) : l + s - l * s
    const p = 2 * l - q

    const hue2rgb = (pp, qq, t) => {
      if (t < 0) t += 1
      if (t > 1) t -= 1
      if (t < 1 / 6) return pp + (qq - pp) * 6 * t
      if (t < 1 / 2) return qq
      if (t < 2 / 3) return pp + (qq - pp) * (2 / 3 - t) * 6

      return pp
    }

    return [hue2rgb(p, q, h + 1 / 3), hue2rgb(p, q, h), hue2rgb(p, q, h - 1 / 3)]
  }

  function recolorArray(arr, ph, ps) {
    if (!Array.isArray(arr) || arr.length < 3)
      return

    const [r, g, b] = arr

    if (typeof r !== 'number' || typeof g !== 'number' || typeof b !== 'number')
      return
    if (r < 0 || r > 1 || g < 0 || g > 1 || b < 0 || b > 1)
      return

    const [h, s, l] = rgbToHsl(r, g, b)

    // Only replace saturated colors in red/orange hue range (0-30° or 330-360°)
    if (s > 0.4 && l > 0.1 && l < 0.9 && (h < 0.083 || h > 0.917)) {
      const [nr, ng, nb] = hslToRgb(ph, ps, l)

      arr[0] = nr
      arr[1] = ng
      arr[2] = nb
    }
  }

  function recolorLottie(data, primaryRgb01) {
    const [ph, ps] = rgbToHsl(...primaryRgb01)

    function walkShapes(shapes) {
      if (!Array.isArray(shapes))
        return

      for (const s of shapes) {
        if (s.ty === 'gr' && s.it)
          walkShapes(s.it)

        if ((s.ty === 'fl' || s.ty === 'st') && s.c) {
          if (s.c.a === 0 && Array.isArray(s.c.k))
            recolorArray(s.c.k, ph, ps)

          if (s.c.a === 1 && Array.isArray(s.c.k)) {
            for (const kf of s.c.k) {
              if (kf.s) recolorArray(kf.s, ph, ps)
              if (kf.e) recolorArray(kf.e, ph, ps)
            }
          }
        }
      }
    }

    function walkLayers(layers) {
      if (!Array.isArray(layers))
        return

      for (const layer of layers) {
        if (layer.shapes) walkShapes(layer.shapes)
        if (layer.layers) walkLayers(layer.layers)
      }
    }

    if (data.layers) walkLayers(data.layers)
    if (data.assets) {
      for (const asset of data.assets)
        if (asset.layers) walkLayers(asset.layers)
    }
  }

  function loadThemed(playerEl, animationKey) {
    if (!playerEl || typeof playerEl.load !== 'function')
      return

    const source = animationKey === 'arrow' ? arrowData : underConstructionData
    const json = JSON.parse(JSON.stringify(source))
    const primary = getPrimaryRgb01()

    recolorLottie(json, primary)
    playerEl.load(json)
  }

  /**
   * Binds a lottie-player ref to an animation key.
   * Loads on mount + re-recolors reactively when the primary color changes.
   * Must be called during setup().
   */
  function bindPlayer(playerRef, animationKey) {
    const load = () => loadThemed(playerRef.value, animationKey)

    onMounted(async () => {
      await customElements.whenDefined('lottie-player')
      await nextTick()
      load()
    })

    watch(() => current.value.colors.primary, load)
  }

  return { bindPlayer }
}
