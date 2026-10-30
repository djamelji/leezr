// W-SIGNATURE-4 (ADR-656) — signature PERSONNELLE côté navigateur (WebCrypto ECDSA P-256).
// La clé PRIVÉE est générée dans le navigateur, NON extractible, conservée en IndexedDB. Elle ne
// quitte JAMAIS l'appareil et n'est jamais transmise à Leezr. Seule la clé PUBLIQUE est envoyée.
// Le signataire contrôle réellement son opération (son navigateur signe avec SA clé privée).

const DB_NAME = 'leezr-signature'
const STORE = 'keys'
const KEY_ID = 'signer-ecdsa-p256'

export function webcryptoSupported() {
  return typeof window !== 'undefined' && window.crypto?.subtle && window.indexedDB
}

function openDb() {
  return new Promise((resolve, reject) => {
    const req = indexedDB.open(DB_NAME, 1)
    req.onupgradeneeded = () => { if (!req.result.objectStoreNames.contains(STORE)) req.result.createObjectStore(STORE) }
    req.onsuccess = () => resolve(req.result)
    req.onerror = () => reject(req.error)
  })
}

async function idbGet(key) {
  const db = await openDb()

  return new Promise((resolve, reject) => {
    const tx = db.transaction(STORE, 'readonly').objectStore(STORE).get(key)
    tx.onsuccess = () => resolve(tx.result)
    tx.onerror = () => reject(tx.error)
  })
}

async function idbSet(key, val) {
  const db = await openDb()

  return new Promise((resolve, reject) => {
    const tx = db.transaction(STORE, 'readwrite').objectStore(STORE).put(val, key)
    tx.onsuccess = () => resolve()
    tx.onerror = () => reject(tx.error)
  })
}

export async function hasKey() {
  try { return !!(await idbGet(KEY_ID)) } catch { return false }
}

/** Récupère la paire de clés du signataire, ou en génère une (clé privée NON extractible). */
async function getOrCreateKeyPair() {
  const existing = await idbGet(KEY_ID)
  if (existing?.privateKey && existing?.publicKey) return existing

  // extractable=false → la clé PRIVÉE n'est pas exportable ; la clé publique reste exportable (spec).
  const pair = await crypto.subtle.generateKey({ name: 'ECDSA', namedCurve: 'P-256' }, false, ['sign', 'verify'])
  await idbSet(KEY_ID, pair) // les CryptoKey non-extractibles sont stockables en IndexedDB
  return pair
}

function b64(buf) {
  const bytes = new Uint8Array(buf)
  let s = ''
  for (let i = 0; i < bytes.length; i++) s += String.fromCharCode(bytes[i])

  return btoa(s)
}

/** Construit la représentation CANONIQUE (doit correspondre EXACTEMENT au backend). */
export function canonicalPayload(claims) {
  // Ordre de clés identique au PHP json_encode(JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE).
  const ordered = {
    domain: claims.domain,
    company_id: Number(claims.claims.company_id),
    request_id: Number(claims.claims.request_id),
    signer_id: Number(claims.claims.signer_id),
    signer_email: String(claims.claims.signer_email),
    document_sha256: String(claims.claims.document_sha256),
    challenge: String(claims.claims.challenge),
  }

  return JSON.stringify(ordered)
}

/**
 * Signe la charge canonique avec la clé privée du signataire.
 * @returns {Promise<{publicKeySpki:string, signatureP1363:string}>}
 */
export async function signPersonal(personalSigning) {
  const pair = await getOrCreateKeyPair()
  const payload = canonicalPayload({ domain: personalSigning.domain, claims: personalSigning.claims })
  const data = new TextEncoder().encode(payload)
  const sig = await crypto.subtle.sign({ name: 'ECDSA', hash: 'SHA-256' }, pair.privateKey, data) // P1363 r||s
  const spki = await crypto.subtle.exportKey('spki', pair.publicKey)

  return { publicKeySpki: b64(spki), signatureP1363: b64(sig) }
}

function fromB64(s) {
  const bin = atob(s)
  const bytes = new Uint8Array(bin.length)
  for (let i = 0; i < bin.length; i++) bytes[i] = bin.charCodeAt(i)

  return bytes
}

/**
 * W-SIGNATURE-7 — signe des octets BRUTS (le SET d'attributs CMS `to_sign` renvoyé par /prepare)
 * avec la MÊME clé privée non extractible. Utilisé pour la signature PAdES externe (phase 2).
 * @returns {Promise<string>} signature ECDSA P1363 (r||s) en base64
 */
export async function signRawBase64(toSignB64) {
  const pair = await getOrCreateKeyPair()
  const sig = await crypto.subtle.sign({ name: 'ECDSA', hash: 'SHA-256' }, pair.privateKey, fromB64(toSignB64))

  return b64(sig)
}
