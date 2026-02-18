/**
 * Runtime typography injection for platform-governed font configuration.
 *
 * Uses the CSS custom property --lzr-font-family defined in the SCSS
 * variables ($font-family-custom). Vuetify compiles all typography to:
 *   font-family: var(--lzr-font-family, "Public Sans"), sans-serif, …
 *
 * At runtime we just set --lzr-font-family on :root — every element
 * (text, headings, dialogs, buttons, charts…) picks it up naturally.
 *
 * Injects up to 2 elements in <head>:
 *   - lzr-typography-faces  : <style> with @font-face rules (local fonts)
 *   - lzr-typography-google : <link>  to Google Fonts CSS (google source)
 *
 * Called from auth store login/fetchMe when `ui_typography` is present,
 * and from the platform typography settings page for live preview.
 */

const FACES_ID = 'lzr-typography-faces'
const GOOGLE_ID = 'lzr-typography-google'
const PRELOAD_ID = 'lzr-typography-preload'
const PRELOAD_GOOGLE_ID = 'lzr-typography-google-preload'
const STORAGE_KEY = 'lzr-typography'
const CSS_PROP = '--lzr-font-family'

function cleanup() {
  document.getElementById(FACES_ID)?.remove()
  document.getElementById(GOOGLE_ID)?.remove()

  // Also remove preload elements injected by Blade early init
  document.getElementById(PRELOAD_ID)?.remove()
  document.getElementById(PRELOAD_GOOGLE_ID)?.remove()

  document.documentElement.style.removeProperty(CSS_PROP)
}

function persist(payload) {
  if (!payload?.active_family_name) {
    localStorage.removeItem(STORAGE_KEY)

    return
  }

  localStorage.setItem(STORAGE_KEY, JSON.stringify({
    active_source: payload.active_source,
    active_family_name: payload.active_family_name,
    font_faces: payload.font_faces || [],
    google_weights: payload.google_weights || [],
  }))
}

function injectFontFaces(familyName, fontFaces) {
  if (!fontFaces?.length || !familyName) return

  const css = fontFaces.map(f => `
@font-face {
  font-family: "${familyName}";
  font-weight: ${f.weight};
  font-style: ${f.style};
  src: url("${f.url}") format("${f.format}");
  font-display: swap;
}`).join('\n')

  const style = document.createElement('style')

  style.id = FACES_ID
  style.textContent = css
  document.head.appendChild(style)
}

function injectGoogleLink(familyName, weights) {
  if (!familyName) return

  const weightsStr = (weights?.length ? weights : [400]).join(';')

  const link = document.createElement('link')

  link.id = GOOGLE_ID
  link.rel = 'stylesheet'
  link.href = `https://fonts.googleapis.com/css2?family=${encodeURIComponent(familyName)}:wght@${weightsStr}&display=swap`
  document.head.appendChild(link)
}

/**
 * Apply typography from a resolved payload (as returned by the API).
 * Persists to localStorage for early hydration from Blade template.
 * Payload shape: { active_source, active_family_name, font_faces[], google_weights[] }
 */
export function applyTypography(payload) {
  if (!payload) return

  cleanup()

  const { active_source, active_family_name } = payload

  if (!active_family_name) {
    persist(null)

    return
  }

  if (active_source === 'local') {
    injectFontFaces(active_family_name, payload.font_faces)
  }
  else if (active_source === 'google') {
    injectGoogleLink(active_family_name, payload.google_weights)
  }

  document.documentElement.style.setProperty(CSS_PROP, `"${active_family_name}"`)
  persist(payload)
}

/** Alias for live preview in settings page. */
export function previewTypography(payload) {
  applyTypography(payload)
}

/** Remove all injected typography (return to SCSS compile-time font). */
export function resetTypography() {
  cleanup()
  localStorage.removeItem(STORAGE_KEY)
}
