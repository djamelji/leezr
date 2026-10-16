# Livrable 6 — Plan de remédiation (Master Audit 360°, octobre 2026)

> Méthode BMAD : **1 lot = 1 objectif cohérent = 1 commit**. Ordre par dépendances et criticité. Aucun développement lancé avant validation du plan. Chaque lot = un ADR + tests verts + build clean.

## Ordre d'exécution (dépendances)

```
LOT-0 Sécurité d'urgence ──► LOT-1 Déploiement & SSL sûrs ──► LOT-2 Tests verts (gate CI)
   │                                                               │
   └──► LOT-3 Dépendances (CVE) ◄──────────────────────────────────┘
                 │
                 ▼
LOT-4 Intégrité catalogue ──► LOT-5 Isolation tenant ──► LOT-6 Workforce P0 produit
                                                               │
                                                               ▼
                               LOT-7 Frontière Core (sortir métier FR) ──► LOT-8 Socle horizontal (contacts→invoicing)
```

## Lots

### 🔴 LOT-0 — Sécurité d'urgence (bloquant absolu)
- Retirer la création d'admin du seeder prod (SEC-01) ; mot de passe aléatoire + reset obligatoire ; **2FA obligatoire super_admin**.
- Retirer les credentials email du code (SEC-02), bascule `env()`, **rotation immédiate** du mot de passe `@Crinshow31`, purge historique git.
- ADR dédié. **Effort : S. Priorité : immédiate.**

### 🔴 LOT-1 — Déploiement & SSL sûrs
- Supprimer/garder `migrate:fresh --force` derrière un garde-fou d'environnement explicite (SEC-03) ; retirer DevSeeder du chemin prod.
- **Ré-émettre le cert dev** via panneau ISPConfig (SSL-01, runbook existant).
- **Effort : S.**

### 🔴 LOT-2 — Tests verts (restaurer le gate CI)
- Corriger la contamination d'isolation (`jobdomains.key` via `firstOrCreate`/factory) et le test bloquant (TEST-01) ; `php artisan test` complet doit terminer vert.
- **Effort : M.**

### 🟠 LOT-3 — Dépendances (CVE)
- `composer update` ciblé sur les 13 advisories HIGH (DEP-01) ; `pnpm audit` + mises à jour JS ; re-test + build.
- **Effort : M.**

### 🟠 LOT-4 — Intégrité du catalogue (ADR-561 #4)
- `is_sellable=false` / masquer `logistics_fleet/tracking/analytics` (CAT-01) tant qu'il n'y a pas de surface. Seed/config.
- **Effort : S.**

### 🟠 LOT-5 — Isolation tenant durcie
- Ajouter `BelongsToCompany` aux ~16 modèles concernés (SEC-05) ; auditer tous les jobs/commandes billing & workforce pour scoping explicite (SEC-04) ; sécuriser `/media` (SEC-06, auth/tenant pour les assets sensibles).
- **Effort : M.**

### 🟠 LOT-6 — Workforce P0 produit (stabilisation, ADR-561)
- Montants en **euros** (×100 au store) ; **validation `.validate()` bloquante** ; **congé self-service scoped** (nouveau formulaire employé) ; **sélecteurs de run** (bulletins/DSN) ; **9 clés i18n** `workforceMe.*` (FR/EN/AR) + 5 clés EN billing ; corriger `watch debounce` (6 pages → `watchDebounced`) ; brancher le bandeau conformité ou le retirer (FUNC-01).
- **Effort : L.** (= un vrai sprint, mais découpable en sous-lots cohérents)

### 🟡 LOT-7 — Frontière Core (ADR-562)
- Déplacer `PayrollCalculationEngine`, `Dsn/`, `LeaveLedger`, calculateurs FR de `app/Core/Workforce/` → `app/Modules/Workforce/` ; supprimer `if('FR')` du Core (passer par Markets).
- **Effort : L.** (refactor structurant, à faire une fois les tests verts — LOT-2 est prérequis).

### 🟡 LOT-8 — Socle horizontal (ADR-563, T1)
- `contacts` (référentiel universel personnes/organisations, **pas un CRM**) puis `invoicing` (facturation client, priorité #1). Chaque module réutilise les Cores (Documents/Notifications/Audit) et est composable par jobdomain.
- **Effort : XL.** (chantier produit principal — après stabilisation).

## Notes
- LOT-0 à LOT-3 = **stabilisation technique** (prérequis à toute reprise).
- LOT-4 à LOT-6 = **assainissement produit** (vendabilité Workforce + catalogue honnête).
- LOT-7/LOT-8 = **réalignement stratégique** (exécution enfin du réalignement juillet 2026).
- Le working tree contient 15 fichiers UI (ADR-559/560) non commités : à committer proprement dans un lot dédié ou à intégrer à LOT-6.
