# Livrable 5 — Matrice de conformité BMAD (ADR-561 → ADR-569) vs code réel

> Octobre 2026. Confrontation des décisions aux preuves code/DB. « Une décision non appliquée n'existe pas côté code. »

## Matrice

| ADR | Décision clé | État réel (preuve) | Conforme | Action |
|-----|-------------|--------------------|----------|--------|
| **561** | Retirer de la vente les coquilles `logistics_fleet/tracking/analytics` | DB : les 3 toujours `is_sellable=true` + `addon_pricing` actif, 0 page frontend | **NON** | Décision #4 jamais appliquée (quick-win trivial non fait) |
| **561** | Workforce reclassé horizontal, **stabilisé** | Reclassement nominal ; les 6 P0 produit **0/6 corrigés** (cf. Livrable 2) | **PARTIEL** | Stabilisation non exécutée |
| **562** | Règle d'or : **aucun `app/Core/{Vertical}/`** | `app/Core/Workforce/` **existe toujours** : `PayrollCalculationEngine.php`, 14 services (Tax/Rgdu/Ytd/Regularization/Contribution/LeaveLedger…), `Dsn/` complet | **NON** | Violation P1 matérielle non résorbée |
| **562 #6** | Aucun `if('FR')` dans le Core | `PayrollCalculationEngine.php:324` `if ($marketKey === 'FR' && …)` ; `DsnCtpMapping.php` défaut `'FR'` | **NON** | `if('FR')` littéral dans le Core |
| **563** | Créer 6 modules horizontaux (contacts/invoicing/catalog/scheduling/inventory/projects) | **0/6** : aucun dossier, aucun module DB, aucune page. `find app` négatif sur les 6 | **NON** | Socle horizontal 100 % inexistant |
| **564 #2** | `CreateJobdomainUseCase` complet (roles/archetypes/docs/nav) | `CreateJobdomainData.php:7-13` = `key,label,description,defaultModules,defaultFields` seulement ; UseCase = simple `Jobdomain::create` | **NON** | « Créer une verticale sans coder » non atteint |
| **564 #4** | 2ᵉ vertical léger non réglementaire | **1 seul jobdomain** (`logistique`) codé en dur (`JobdomainRegistry.php:37`) | **NON** | Promesse multi-vertical non prouvée |
| **565** | Roadmap T0→T4 | T0 non soldé (catalogue non assaini, P0 Workforce intacts), T1 non démarré (socle inexistant) | **NON** | Roadmap non entamée côté code |
| **566** | Règles anti-dérive (Core propre, pas de coquille vendable) | Core pollué (métier FR), 3 coquilles vendables | **NON** | Règles #2 et #3 violées |
| **567** | Principes produit PP1-PP6 | PP1 (saisie centimes), PP2 (IDs tapés), PP5 (clés i18n brutes, self-service→admin), PP6 (coquilles facturées) tous enfreints | **NON** | Aucun principe enforced |
| **568** | `.htaccess` exclut acme-challenge | `public/.htaccess:10` présent ; déployé (release f7ae49a) | **OUI** | Fix durable OK (ré-émission cert ≠ code) |
| **569** | Épingler pnpm CI + `packageManager` | `deploy.yml:66` `10.28.1` ; `package.json:6` `pnpm@10.28.1` | **OUI** | Déploiements débloqués |

## Verdict de conformité

Sur 9 ADR du réalignement de juillet 2026 :
- **2 appliqués** (568, 569) — tous deux **infra/CI**, livrés dans la foulée de leur rédaction.
- **7 non appliqués** — précisément les décisions **stratégiques et structurantes** (561 vente, 562 frontière Core, 563 socle horizontal, 564 générateur/2ᵉ vertical, 565 roadmap, 566 anti-dérive, 567 philosophie produit).

**Le réalignement a été documenté mais pas exécuté.** Le code d'octobre 2026 est strictement dans l'état décrit par l'audit de juillet 2026 : même violation P1 (Core métier), même mono-vertical, mêmes coquilles vendables, mêmes P0 produit. Les quick-wins triviaux (retirer les 3 coquilles de la vente) n'ont pas été faits.

## Conformité PP1-PP6 (ADR-567) — détail

| Principe | État | Preuve d'écart |
|----------|------|----------------|
| PP1 Automatiser plutôt que saisir | **Violé** | congés en jours tapés, paie en 3 étapes, salaire en centimes |
| PP2 Ne jamais redemander une info connue | **Violé** | ID de run de paie/DSN tapé à la main |
| PP3 Réutiliser les services transverses | **Partiel** | Workforce n'émet aucune notification, n'utilise ni Realtime ni Automation (vs Documents qui exploite l'IA) |
| PP4 Réduire les clics | **Violé** | double-calcul paie, bouton « Modifier » = changement d'onglet |
| PP5 Produit unique, pas une juxtaposition | **Violé** | clés i18n brutes, self-service qui ouvre des écrans admin, expérience « à deux vitesses » |
| PP6 Valeur métier mesurable | **Violé** | 3 modules facturés sans surface utilisateur |
