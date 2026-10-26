# BILLING 3.0 — DOSSIER DE STABILISATION POST-PRODUCTION

> PROD en service · `main`/servi = **`d9e9467`** (code applicatif = recette `e963264`/`a6a767d`). Serveur 2026-10-26 ~22:26 UTC. **Lecture seule — aucune écriture, aucun déclenchement de job, aucun changement de config.** Ce document est préparé localement ; non poussé sans autorisation.

---

## 1. Contrôles RÉUSSIS (✅ vérifiés en lecture seule sur PROD)

| Contrôle | Résultat |
|---|---|
| SHA servi = main | `releases/20261026220446_d9e9467`, runtime commit confirmé |
| HTTP santé | `/up` + home → 200 |
| Migrations | 0 pending (no-op) |
| Erreurs/exceptions/500 (logs depuis déploiement) | **0** |
| Scheduler **exécute réellement** | `recover-checkouts`/`recover-webhooks` lancés **22:10 (après déploiement 22:04)** `ok` ; `scheduler.log` montre des runs toutes les ~15 s |
| Heartbeats existants (6) | tous `ok`, cadences respectées |
| Données financières vs baseline | **identiques** : subs 3/2, 4 factures (0 void), Σ contracté **10800¢**, ledger 13, dlq 0, paiements 0 |
| Cockpit | `blocked_renewals = 2` |
| Revue tarifaire | 2 abonnements (Leezr Logistics #1, Pending Approval Co #3) |
| **Leezr Logistics** | plan **pro**, actif, **période échue** (fin 2026-05-31), **renouvellement en revue** (`hasReliableContractedPrice=NON`, prix `provisional 2900¢` inchangé) |
| Leezr Logistics — factures HT/TVA/TTC | corrects (20%) : `1900/380/2280`, `2900/580/3480`, `900/180/1080` |
| Poppins (PDF premium) | 5 `.ttf` présents dans la release |
| Moyens de paiement | `driver=null` → paiement en ligne indisponible ; `internal` lève une exception sur collecte (aucun prélèvement possible) |
| Prix provisional | **non confirmés, inchangés** (aucune régularisation, aucun renouvellement rétroactif) |

## 2. Contrôles NON VÉRIFIÉS (ouverts — ni réussis ni échoués)

| Contrôle | Raison | Quand lever |
|---|---|---|
| Heartbeats `collect-scheduled`, `expire-trials`, `check-trial-expiring`, `check-expiring-cards` | **Fenêtre planifiée non encore atteinte** (jobs quotidiens 00:00 / collect-scheduled 06:00 ; déploiement 22:04). Aucune ligne heartbeat (jamais exécutés). **Non déclenchés manuellement.** | Re-contrôle lecture seule après 2026-10-27 00:00 puis 06:00 UTC |
| Rendu **navigateur** des interfaces Company/Platform + **téléchargement PDF** | Nécessite un **compte PROD autorisé** (SPA + Sanctum) ; aucun identifiant inventé/forcé | Avec un accès fourni, ou validation manuelle (checklist §5) |

## 3. Anomalie résiduelle — BILL-011 (fiscal `tax_mode='none'` trompeur)

**Constat.** Priorité fiscale réelle (lecture code, non modifiée) :
- **Taux** `TaxResolver::resolveRateBps()` : `market.vat_rate_bps` (FR = 2000 = 20 %) en priorité, sinon `PlatformBillingPolicy.default_tax_rate_bps`. ⇒ **le taux vient du Market.**
- **Mode** `TaxResolver::compute()` : `match(tax_mode)` → `'inclusive'` = extraction ; **`default`** (inclut `'none'` ET `'exclusive'`) = **TVA ajoutée (exclusive)**. **Aucune branche `'none' => 0`**, aucun court-circuit amont.

**Conséquence.** `tax_mode='none'` (valeur PROD actuelle) **n'annule pas la TVA** : avec le Market FR, 20 % exclusive est appliqué → c'est pourquoi les factures FR portent 20 % malgré `tax_mode=none`. **Les factures sont fiscalement correctes** (20 % B2B FR), mais l'option UI **« Aucune taxe (mode: aucun) »** est **trompeuse** : elle promet l'absence de taxe que le moteur n'applique pas.

**Sévérité.** Pas de facture erronée aujourd'hui (sortie correcte). Risque = **piège de configuration** (un admin choisissant « Aucune taxe » obtiendrait quand même la TVA si le market a un taux). 

**Correction ultérieure proposée (NON appliquée — nécessite autorisation)** : soit ajouter `'none' => 0` au `match` (supprimer réellement la taxe), soit retirer l'option `'none'` de l'UI (la taxe étant pilotée par le Market) — à l'image du retrait des paramètres décoratifs (ADR-631h). À trancher côté métier.

## 4. Autre dette connue (hors billing, non bloquante)
- `EmailInboxController` (327 l.) et `EmployeeSelfController` (285 l.) > 250 lignes (`ControllerSizeInvariantTest`) — **pré-existant, identique sur l'ancien main**, hors périmètre Billing 3.0. Ticket séparé.

## 5. Checklist recette navigateur PROD (pour un utilisateur autorisé, lecture seule)

> N'effectuer **aucune** mutation (pas de paiement, pas de confirmation de prix, pas de modification de facture).

**Company — Leezr Logistics**
- [ ] Overview s'affiche (abonnement → échéance → situation).
- [ ] Plan **Pro** visible, **période échue** signalée.
- [ ] **Renouvellement en revue** indiqué (prix non fiable / bloqué).
- [ ] Liste des factures (3 documents : INV-2026-000002 + 2 annexes).
- [ ] Montants **HT/TTC** cohérents (TVA 20 %).
- [ ] Addons / droits affichés.
- [ ] **PDF** facture : téléchargement, typographie **Poppins** premium, montants = snapshot.
- [ ] Moyens de paiement : **aucun** paiement en ligne proposé (provider null/internal).

**Platform**
- [ ] Cockpit financier (MRR/ARR contractuels, facturé ≠ encaissé).
- [ ] **2 renouvellements bloqués** (KPI + liste).
- [ ] **Tariff-review** : 2 abonnements, dialogue de confirmation exige une preuve (ne rien confirmer).
- [ ] Factures & Paiements (statuts corrects, `void` = « Annulée »).
- [ ] Paramètres Billing (lecture) — noter l'anomalie BILL-011 sur `tax_mode`.
- [ ] Supervision heartbeats (recovery) : jobs `ok` + les 4 nouveaux après leur fenêtre.

## 6. Rollback
- Disponible et inchangé : **`releases/20261024191921_1097d8a`** (présent/intact). Repointage symlink, **0 migration à réverser**. Jamais `8573ba8`.

## 7. VERDICT : **MAINTIEN EN PRODUCTION** ✅
Fondé sur preuves : déploiement sain, scheduler exécutant réellement, données financières intactes, prix provisional bloqués/inchangés, HT/TTC corrects, 0 erreur. Aucune raison de rollback. Les contrôles non vérifiés (4 heartbeats, navigateur) **ne bloquent pas** le fonctionnement — ils restent ouverts jusqu'à leur fenêtre / un accès autorisé. Anomalie BILL-011 documentée pour correction ultérieure (sans impact sur la justesse des factures actuelles).
