/**
 * ADR-769 (M7 LOT 3) — registre des états PERSONNELS liés à l'entreprise courante.
 *
 * Un store qui garde des données personnelles d'une entreprise (pointage, mon espace, mes
 * documents…) s'enregistre ici. Au changement d'entreprise SANS rechargement de page (autre onglet,
 * via le canal de diffusion), le runtime purge ces états AVANT de recharger le contexte, puis les
 * recharge une fois le nouveau contexte prêt : aucune donnée de l'entreprise A ne reste affichée
 * pendant que l'entreprise B est sélectionnée.
 */
const entries = new Map()

/** @param {string} id  @param {{ reset: Function, reload?: Function }} handlers */
export function registerTenantScoped(id, handlers) {
  entries.set(id, handlers)
}

export function resetTenantScoped() {
  entries.forEach(h => { try { h.reset() } catch { /* un store non initialisé n'a rien à purger */ } })
}

export function reloadTenantScoped() {
  entries.forEach(h => { try { h.reload?.() } catch { /* idem */ } })
}
