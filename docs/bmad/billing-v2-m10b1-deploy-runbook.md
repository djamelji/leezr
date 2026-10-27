# Billing Consolidé V2 — M10-B1 : runbook de déploiement PROD (flags OFF)

> **Préparé, NON exécuté.** M10-B1 = déployer le code V1/V2 sur PROD avec `emit=OFF` + `route_activations=OFF`.
> **M10-B2** (activation sur contrats qualifiés) = opération distincte, autorisation séparée.
> Interdits : push main, déploiement PROD, activation de flag, écriture financière PROD, encaissement — **tant que GO M10-B1 explicite non donné.**

## Candidat PROD
- **SHA candidat** : `833a754` (dev HEAD). 18 commits `main..dev`, **tous ADR-632** (M0→M10-A + design) — aucun autre changement embarqué.
- **PROD courant** : `2bcf3a4` (pré-V2, aucun schéma V2).
- Promotion = `main` fast-forward vers `833a754` → GitHub Actions déploie web2.

## Migrations appliquées au déploiement (7, strictement additives)
| Migration | Effet | Table existante ? |
|---|---|---|
| `…100000_add_billing_model_version_to_invoices` | +colonne `billing_model_version` TINYINT **default 1** | ALTER `invoices` (4 lignes → toutes `1`) |
| `…100001_create_billable_events_table` | CREATE | nouvelle |
| `…110000_add_reserved_at_to_billable_events` | +colonne `reserved_at` | nouvelle (billable_events) |
| `…120000_add_v2_eligibility_to_subscriptions` | +`v2_effective_at` (nullable, **NULL**) + `commercial_policy_version` (nullable) | ALTER `subscriptions` (aucun marqué éligible) |
| `…130000_create_billing_watermarks_and_runs` | CREATE ×2 | nouvelles |
| `…140000_create_billing_covered_intervals` | CREATE (+`bill_immediately` sur billable_events) | nouvelle |
| `…150000_create_billing_invoice_line_analytics` | CREATE | nouvelle |

**Aucune migration ne modifie/supprime une facture, un paiement, un avoir ou le ledger (`financial_ledger_entries`).** Les 2 seuls ALTER sur tables financières sont des **ajouts de colonnes** (default 1 / nullable). Tables PROD minuscules → ALTER instantané.

## Check-list de déploiement M10-B1
1. **Sauvegarde** : dump complet DB PROD (`c1leezr`) + archive du répertoire release courant ; noter SHA courant `2bcf3a4`.
2. **Fenêtre** : hors pic ; prévenir qu'aucun changement fonctionnel n'est attendu (code dormant).
3. **Promotion** : `main` ← `833a754` (push main) → déclenche le workflow `deploy.yml` (pnpm épinglé 10.28.1).
4. **Migrations** : le deploy exécute `migrate --force` → 7 migrations additives. Vérifier `migrate:status` : 7 × `Ran`.
5. **Seed** : `db:seed --class=SystemSeeder` (idempotent, RBAC/modules — **financièrement inerte**, ne touche ni `subscriptions.v2_effective_at` ni les factures). `BillingRecetteSeeder` **ne s'exécute pas** (`APP_ENV=production`).
6. **Vérif flags** : `config('billing.consolidated.emit')===false` ET `route_activations===false` (aucun override `.env` PROD → defaults OFF). **Confirmé en préparation.**
7. **Vérif V1** : ouvrir/paginer les 4 factures existantes ; PDF téléchargeable ; `/company/billing/pay` ; aucune régression.
8. **Vérif éligibilité** : `SELECT COUNT(*) FROM subscriptions WHERE v2_effective_at IS NOT NULL` = **0**. Aucune Company V2-éligible.
9. **Vérif émission dormante** : 0 cron V2 (`routes/console.php`) ; `BillingRun` non câblé ; `billing:v2-migration-audit` disponible en lecture seule.
10. **SHA servi** : `readlink -f current` = release `833a754`. Clôture.

## Check-list de rollback M10-B1
- **Code** : `deploy/rollback.sh` → re-symlink vers la release `2bcf3a4` (ou précédente). Immédiat.
- **Migrations** : **NE PAS exécuter `migrate:rollback`**. Les colonnes/tables V2 sont additives et inertes ; les laisser en place est sans effet sur V1. (Le `down()` existe mais ne doit servir qu'en dernier recours **avant toute émission V2**.)
- **Flags** : déjà OFF — rien à faire.
- **Données** : aucune donnée financière créée par M10-B1 → rollback sans perte.

### Version minimale de rollback compatible V2
- **Avant toute émission V2** (état M10-B1) : rollback jusqu'à `2bcf3a4` **sûr** (aucun document V2 n'existe).
- **Après M10-B2 (émission réelle)** : **plancher de rollback = la release M10-B1 (`833a754`)**. En deçà (`2bcf3a4`), le code ne connaît pas `billing_model_version` ni les read models V2 → les documents V2 deviendraient illisibles. **Règle : une fois un document V2 émis, ne jamais revenir sous la release qui sait le lire.** `migrate:rollback` des colonnes V2 devient **interdit** (détruirait la lecture des factures V2).

## Prérequis restant pour M10-B2 (activation) — procédure, NON exécutée
1. **Confirmer un prix fiable depuis une preuve** : via la gouvernance tarifaire (ADR-593), passer `plan_price_source` d'un contrat de `provisional` → valeur fiable (`admin_confirmed`) **sur preuve documentée** (devis/contrat signé). **Aucune confirmation automatique.**
2. **Qualifier les périodes des modules déjà facturés** : pour chaque ligne de module sans période (cas Leezr Logistics, inv 2/3/4), déterminer manuellement la période de service réelle (activation → fin), la documenter. Sans période → reste bloqué.
3. **Déterminer les intervalles couverts V1** : `billing:v2-migration-audit` (lecture seule) → revue du rapport avant/après.
4. **Construire les watermarks fiables** : via un futur `billing:v2-migration-apply` (M10-B2, à créer) **idempotent et réversible** (`source_model_version='v2'`), **uniquement** pour les prestations résolues ; les ambiguës restent bloquées (watermark null).
5. **Documenter la date d'effet V2** : fixer `v2_effective_at` à un instant futur (anniversaire **préservé**, non arrondi) + `commercial_policy_version`.
6. **Valider l'absence de double facturation** : `BillingRun::simulate` (shadow) sur le prochain cycle du pilote ; vérifier que la couverture empêche de refacturer une période déjà couverte.
7. **Sélectionner la cohorte pilote** : **1** abonnement à prix confirmé **et** périodes qualifiées. (Aujourd'hui : **0 candidat** — les 2 subs actifs sont provisional, 1 a des périodes manquantes.)
8. **Vérifier les prérequis avant activation** : check-list bloquante (prix fiable ✓, périodes qualifiées ✓, couverture simulée sans double ✓, date d'effet documentée ✓) → seulement alors `route_activations=ON` puis `emit=ON` pour le pilote.

## Verdict M10-B1
**GO conditionnel** — déployer le code V1/V2 avec flags OFF est **sûr** : additif, réversible, aucune Company éligible, aucune émission, V1 intact (898 tests verts). Conditions d'exécution : (a) sauvegarde PROD préalable, (b) check-list déploiement respectée, (c) **autorisation explicite d'exécution** (push main + déploiement PROD).

**M10-B2 reste NO-GO** tant que les prérequis ci-dessus ne sont pas remplis sur un contrat réel.
