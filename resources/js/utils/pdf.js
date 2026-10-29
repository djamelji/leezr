// W-SIGNATURE-1 correctif PDF (ADR-652) — gestion PDF unifiée et sûre.
// Récupère un PDF via $api (en-têtes auth + X-Company-Id inclus), VALIDE que la réponse est bien
// un PDF (et non une page HTML ou une réponse JSON d'erreur transformée en .pdf), puis permet
// prévisualisation (visionneuse intégrée) et téléchargement, avec nettoyage correct des Object URLs.
// Jamais de token dans l'URL, jamais de chemin de stockage exposé.
import { $api } from '@/utils/api'

/**
 * Récupère un PDF authentifié et le renvoie sous forme de Blob validé.
 * @throws {Error} message lisible si la réponse n'est pas un PDF (erreur serveur, JSON, HTML…).
 */
export async function fetchPdfBlob(path) {
  let blob
  try {
    blob = await $api(path, { responseType: 'blob' })
  } catch (e) {
    // ofetch lève sur 4xx/5xx : tenter d'extraire un message du corps.
    const data = e?.data
    if (data instanceof Blob) {
      const msg = await readErrorMessageFromBlob(data)
      throw new Error(msg || (e?.response?.statusText) || `HTTP ${e?.response?.status || ''}`.trim())
    }
    throw new Error(data?.message || e?.message || 'pdf_fetch_failed')
  }

  if (!(blob instanceof Blob)) {
    throw new Error('pdf_invalid_response')
  }

  // Une réponse d'erreur peut arriver en 2xx avec un corps JSON/HTML : on refuse de la traiter en PDF.
  const looksPdfByType = blob.type && blob.type.toLowerCase().includes('pdf')
  const magic = await readMagic(blob)
  const looksPdfByMagic = magic.startsWith('%PDF')

  if (!looksPdfByMagic) {
    // Pas un PDF : si c'est du JSON/HTML, remonter un message clair plutôt qu'un fichier illisible.
    const msg = await readErrorMessageFromBlob(blob)
    throw new Error(msg || 'pdf_invalid_content')
  }
  // Garantir le bon type MIME pour la visionneuse même si le serveur ne l'a pas renseigné.
  return looksPdfByType ? blob : new Blob([blob], { type: 'application/pdf' })
}

async function readMagic(blob) {
  try {
    const slice = blob.slice(0, 5)
    const buf = await slice.arrayBuffer()
    return new TextDecoder('latin1').decode(buf)
  } catch { return '' }
}

async function readErrorMessageFromBlob(blob) {
  try {
    const text = await blob.text()
    if (!text) return ''
    const trimmed = text.trim()
    if (trimmed.startsWith('{') || trimmed.startsWith('[')) {
      try { return JSON.parse(trimmed).message || '' } catch { return '' }
    }
    // HTML ou texte : ne pas renvoyer la page entière.
    return ''
  } catch { return '' }
}

/** Déclenche un téléchargement navigateur d'un Blob, puis libère l'Object URL. */
export function downloadBlob(blob, filename = 'document.pdf') {
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = filename
  document.body.appendChild(a)
  a.click()
  a.remove()
  // Révocation après le déclenchement du téléchargement (le navigateur a déjà lu le blob).
  setTimeout(() => URL.revokeObjectURL(url), 1000)
}

/**
 * Crée un Object URL pour une visionneuse intégrée (iframe/embed). L'appelant DOIT appeler
 * revokeObjectUrl() à la fermeture de la visionneuse (jamais avant la fin d'utilisation).
 */
export function createViewerUrl(blob) {
  return URL.createObjectURL(blob)
}

export function revokeObjectUrl(url) {
  if (url) URL.revokeObjectURL(url)
}
