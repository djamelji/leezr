// @ts-check
// ADR-770 (M7 LOT 4) — propriété des données de test d'une exécution E2E.
//
// Règle : une exécution ne peut supprimer QUE ce qu'elle a créé.
//   1. Chaque exécution reçoit un identifiant unique (`runId`), porté par toutes les données créées
//      (e-mails, noms) — jamais un simple préfixe partagé entre sessions.
//   2. Chaque création est inscrite dans un registre (ids exacts lus dans les réponses de l'API).
//   3. Le démontage passe par `php artisan e2e:teardown-run <registre>` : il ne supprime que les ids
//      du registre, après preuve de propriété (marqueur de l'exécution + date de création). Aucune
//      suppression par motif n'existe.
// Recommandé : pointer E2E_BASE_URL vers une instance dotée de sa propre base de données.
import { execFileSync } from 'node:child_process'
import fs from 'node:fs'
import path from 'node:path'

const DIR = path.resolve('tests/e2e/.ledger')

export function createRun(label = 'run') {
  const runId = `r${Date.now().toString(36)}${Math.random().toString(36).slice(2, 8)}`
  const file = path.join(DIR, `${label}-${runId}.json`)
  const ledger = { run_id: runId, label, started_at: new Date().toISOString(), company_id: null, records: [] }
  const save = () => { fs.mkdirSync(DIR, { recursive: true }); fs.writeFileSync(file, JSON.stringify(ledger, null, 2)) }

  save()

  return {
    runId,
    file,

    /** Entreprise dans laquelle l'exécution crée ses données : le démontage y est confiné. */
    useCompany(id) {
      ledger.company_id = Number(id)
      save()
    },

    /** Adresse e-mail unique de cette exécution. */
    email: (name = 'user') => `e2e.${name}.${runId}@leezr.test`,

    /** Nom portant le marqueur de l'exécution (fiches salarié). */
    name: (base = 'E2E') => `${base}${runId}`,

    /** Inscrit une ligne créée par cette exécution (id exact renvoyé par l'API). */
    track(type, id) {
      if (id === null || id === undefined) return
      ledger.records.push({ type, id: Number(id) })
      save()
    },

    /** Démontage borné : ne supprime que les lignes du registre, après preuve de propriété. */
    teardown() {
      try {
        const out = execFileSync('php', ['artisan', 'e2e:teardown-run', file], { encoding: 'utf8' })

        fs.writeFileSync(file.replace(/\.json$/, '.teardown.json'), out)

        return JSON.parse(out.trim().split('\n').pop())
      }
      catch (e) {
        return { error: String(e).slice(0, 300) }
      }
    },
  }
}
