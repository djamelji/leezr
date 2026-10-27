# Billing Consolidé V2 — M10-A : audit préproduction & recette (ADR-632)

> **LECTURE SEULE PROD. Aucun push main, aucun déploiement PROD, aucun flag PROD activé, aucun encaissement.**
> État au 2026-10-27. DEV servi : `4b1654f`. PROD servi : `2bcf3a4` (pré-V2, aucun schéma V2).

## 1. Vérification préproduction (DEV)

| Contrôle | Résultat |
|---|---|
| SHA DEV réellement servi | `4b1654f` (`releases/20261027061240_4b1654f`) — vérifié `readlink current` |
| Migrations V2 | 7 migrations `2026_10_27_*` **Ran** (model_version, billable_events, reserved_at, v2_eligibility, watermarks+runs, covered_intervals, line_analytics) |
| Tests backend Billing+Platform | **898 passés, 1 skipped** (3417 assertions) — dont 7 simulateur + 19 recette + 7 read-model/PDF + parité Company/Platform |
| Playwright Company/Platform | **7 specs vertes** (M9, navigateur réel) |
| Build Vite | propre (exit 0) |
| `billing.consolidated.emit` | **OFF** (DEV vérifié en prod-config) |
| `billing.consolidated.route_activations` | **OFF** |
| Cron V2 | **aucun** (`routes/console.php` : 0 référence consolidated/BillingRun/emit) |
| Émission involontaire | **impossible** : `BillingRun` appelé uniquement par les tests ; seul point de prod référant V2 = `AddonBillingListener::shouldRouteV2`, gardé par `route_activations` (OFF) |

## 2. Audit de migration des contrats PROD (lecture seule)

PROD (`2bcf3a4`) **n'a aucun schéma V2** (`invoices.billing_model_version` absent, `billable_events` absent). Inventaire via script inline read-only (logique `PeriodBounds` répliquée), exécuté puis supprimé.

**Périmètre réel : 3 companies, 2 abonnements actifs, 4 factures finalisées.**

| Sub | Company | Plan | Dev | Anniversaire | Période courante | Prix | Modules actifs | Inv/Anx | Paiements | Avoirs | Classement |
|---|---|---|---|---|---|---|---|---|---|---|---|
| 1 | Leezr Logistics | pro | EUR | 00:00:00 | 2026-05-01 → 2026-05-31 23:59:59 (`inclusive_eod`, résolu) | **provisional** | 3 (docs/leave/planning, activés 2026-10-21) | 1/2 | 0 | 0 | **manual_review** |
| 3 | Pending Approval Co | business | — (aucune facture) | **12:14:35 (non-minuit)** | 2026-10-21 12:14:35 → 2026-11-21 12:14:35 (`anniversary`, résolu) | **provisional** | 0 | 0/0 | 0 | 0 | **manual_review** |

**Candidats : 2 · Éligibles : 0 · Revue manuelle : 2.**

Anomalies temporelles / risques de chevauchement :
- **Sub 1** : les lignes de module des annexes (inv 2/3/4) **n'ont AUCUNE période** (`period_start`/`period_end` null) → couverture **non reconstructible** → watermarks `workforce_*` = **inconnu (null)**. Aucune charge dérivée (comportement sûr). Pas de chevauchement détecté.
- **Sub 3** : anniversaire **non-minuit (12:14:35)** correctement **résolu** (classe `anniversary`, décision #2 ADR-632 — instant préservé, pas d'arrondi). Aucune facture encore émise.
- Aucune correction automatique appliquée.

## 3. Reconstitution des couvertures (simulation, sans écriture)

Processus livré : `App\Core\Billing\Migration\BillingV2MigrationSimulator` + commande `billing:v2-migration-audit` (**sans `--apply`** — la persistance relève de M10-B). Règles :
- `prestation_key` = `plan` | `module_key` ; période semi-ouverte `[start, end)` ; borne exclusive via `PeriodBounds::fromLegacy`.
- `billed_through` = borne couverte max **uniquement si toutes les lignes de la prestation sont résolues** ; sinon **null (inconnu)** → l'émission V2 de cette prestation reste **bloquée** (jamais déduit). Contrat → revue manuelle.
- Factures **void** ne couvrent rien.
- **Rapport avant/après** par abonnement (watermarks/intervalles existants vs proposés).

**Cas spécial testé** (module facturé « mois plein depuis activation », non aligné au cycle plan) : couverture reconstruite sur **sa propre période** (ex. 16→16), watermark = fin de SA période, indépendant du cycle plan. ✅ `test_module_billed_full_month_from_activation_is_tracked_independently`.

Résultat PROD simulé : **0 watermark fiable dérivable** (sub 1 = périodes manquantes ; sub 3 = aucune facture). Conforme : rien n'est migré tant que la couverture n'est pas certaine.

## 4. Cohérence des paiements

- Factures **V1** : payables / consultables / téléchargeables (PDF) / réconciliables / avoirs / dunning — **inchangées** (aucune régression, 898 tests verts, suite paiement/dunning/avoirs incluse).
- Factures **V2** : traitées par les **mêmes services** (`InvoiceBatchPayService`, `DunningEngine`, `CreditNoteIssuer`), **indépendamment du flag d'émission** — prouvé par la recette s08/s11/s12/s19.
- **Aucun encaissement réel** en M10-A (prestataires simulés ; `provider != internal` succeeded = 0 dans tous les scénarios).

## 5. Recette complète — 19 scénarios RÉELLEMENT exécutés

`tests/Feature/BillingV2RecetteM10Test` — **19/19 passés** (fixtures isolées, prestataires simulés) :

| # | Scénario | Vérifié |
|---|---|---|
| 1 | Une Company, un plan | 1 ligne, 1 facture, v=2 |
| 2 | Plan + 134 modules | 135 lignes, **1 solde** |
| 3 | Prorata antérieur | consolidé au cycle, event→invoiced |
| 4 | Module activé immédiatement | `emitImmediate` hors cycle |
| 5 | Module programmé (différé) | **aucune** facture hors cycle |
| 6 | Facturation immédiate contractuelle | `bill_immediately`→émise |
| 7 | Facturation différée | facturée **au** cycle |
| 8 | Paiement wallet | solde unique, 0 externe |
| 9 | Provider indisponible | facture reste **open**, 0 collecte |
| 10 | Paiement partiel | pas de double, reliquat non collecté |
| 11 | Dunning | par facture, V2, 0 charge réelle |
| 12 | Avoir | credit note rattaché à la facture V2 |
| 13 | Remboursement simulé | **aucune** charge réelle |
| 14 | Prix provisional | émission **bloquée** (`cycle_hold`) |
| 15 | Anniversaire non-minuit | instant préservé |
| 16 | Ancienne facture + annexe | V1 intact, 0 ventilation V2 |
| 17 | Deux workers concurrents | **1 seule** facture (idempotence `run_identity`) |
| 18 | Crash & retry | lease expiré libéré, event re-facturable |
| 19 | Rollback des flags | émission OFF ; V2 déjà émise **reste payable** |

> Note honnête : scénarios 17 (concurrence) et 18 (crash) testés au niveau **idempotence/lease** (unicité `run_identity`, `releaseStale`), pas via threads OS réels. 9/10/13 utilisent des prestataires simulés (pas d'appel externe).

## 6. Matrice GO / NO-GO

| Catégorie | Statut | Détail |
|---|---|---|
| **Blocant financier** | 🔴 NO-GO | Les **2 abonnements PROD** sont en **prix provisional** → non fiables (ADR-593) → émission V2 **bloquée par conception**. Aucun contrat réel confirmé. |
| **Blocant technique** | 🔴 NO-GO | Lignes de module (sub 1) **sans période** → couverture non reconstructible → watermark inconnu. Prérequis : corriger/qualifier les périodes **avant** toute migration de couverture. |
| **Blocant contractuel** | 🟠 revue | Aucun prix contractuel validé ; anniversaire non-minuit (sub 3) à confirmer comme politique voulue. |
| **Régression V1** | 🟢 GO | 898 tests verts ; V1 (parent+annexes, numérotation, paiement, PDF) inchangé ; aucune ventilation V2 sur V1. |
| **Risque de déploiement** | 🟢 GO (code) | Code V1/V2 cohabite, flags OFF ; déjà servi sur DEV sans incident ; migrations additives réversibles. |
| **Limites de la recette** | 🟠 | Pas de concurrence OS réelle ni de provider réel ; pas de données V2 réelles (fixtures only) ; PROD jamais émis. |

**Verdict M10-A : NO-GO pour une activation PROD immédiate.** Le code et la mécanique sont prêts et prouvés ; **les données contractuelles PROD ne le sont pas** (prix provisional + périodes de module manquantes). M10-B ne doit cibler **aucun** des 2 abonnements actuels tant que : (a) leur prix n'est pas confirmé, (b) les périodes de module ne sont pas qualifiées.

## 7. Procédure de déploiement PROD (préparée, NON exécutée — M10-B)

1. **Sauvegarde / point de restauration** : dump DB PROD + note du SHA courant (`2bcf3a4`) pour rollback immédiat.
2. **Déploiement code V1/V2, flags OFF** : push `main` → déploie le code consolidé (migrations additives) avec `emit=OFF`, `route_activations=OFF`. Aucun comportement changé.
3. **Vérif migrations + lecture historique** : `migrate:status` ; factures V1 + annexes consultables/payables/PDF inchangées ; `billing:v2-migration-audit` en lecture seule.
4. **Cohorte pilote explicite** : choisir **1** abonnement **à prix confirmé** et **périodes qualifiées** (aucun des 2 actuels ne qualifie aujourd'hui). Fixer `v2_effective_at` à une date d'effet future.
5. **Migration contrôlée des couvertures** : exécuter le simulateur, **revue manuelle** du rapport avant/après, puis (M10-B, option `--apply` à créer) persister watermarks + intervalles **uniquement** pour les prestations résolues du pilote. Les ambiguës restent bloquées.
6. **Activation coordonnée pilote** : `route_activations=ON` d'abord (événements enregistrés, **émission toujours OFF**) → observer ; puis `emit=ON`.
7. **Première émission surveillée** : au cycle du pilote, 1 facture consolidée réelle.
8. **Vérification** : HT+TVA=TTC = grand livre ; 1 solde ; ventilation analytique réconciliée au centime ; paiement réel (wallet+Stripe) ; PDF ; dunning/avoir par facture.
9. **Extension progressive** : élargir la cohorte par vagues, chacune re-passant l'audit + revue manuelle.
10. **Rollback testé** (cf. §8) avant toute extension.

## 8. Procédure de rollback

- **Flags** : `emit=OFF` puis `route_activations=OFF` (réversible à chaud, sans déploiement).
- **Effet** : les nouveaux cycles repassent en V1 ; **les factures V2 déjà émises restent intactes, consultables et payables** (prouvé s19) — elles ne sont jamais réécrites.
- **Code** : si rollback code nécessaire, re-symlink vers la release précédente (`deploy/rollback.sh`) ; migrations V2 étant **additives**, aucune donnée V1 n'est perdue (pas de `down` destructif requis).
- **Couvertures** : un `--apply` M10-B devra être **idempotent** et réversible (watermarks/intervalles identifiables par `source_model_version='v2'`), supprimables sans toucher aux factures.
- **Interdits permanents** : modification des factures historiques, confirmation auto des prix provisional, refacturation rétroactive.

## 9. Livrables (références code)
- `app/Core/Billing/Migration/BillingV2MigrationSimulator.php` — simulateur lecture seule.
- `app/Console/Commands/BillingV2MigrationAuditCommand.php` — `billing:v2-migration-audit` (sans `--apply`).
- `tests/Feature/BillingV2MigrationSimulatorTest.php` — 7 tests (dont no-write, module désaligné, ambigu, overlap).
- `tests/Feature/BillingV2RecetteM10Test.php` — 19 scénarios de recette.

**M10-B (déploiement + activation PROD) nécessite une autorisation explicite.**
