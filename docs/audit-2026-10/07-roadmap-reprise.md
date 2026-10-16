# Livrable 7 — Roadmap de reprise (Master Audit 360°, octobre 2026)

> Séquence par phases. Principe directeur : **stabiliser avant d'étendre ; largeur avant profondeur** (ADR-565/566). Rien de nouveau tant que la base n'est pas saine.

## Phase 1 — Urgences immédiates (jours 1-3)
- **LOT-0** : neutraliser SEC-01/SEC-02 (admin `password`, secrets committés, rotation, 2FA super_admin).
- **LOT-1** : ré-émettre le cert SSL dev ; sécuriser le script de déploiement (`migrate:fresh`).
- Objectif de sortie : aucune porte d'entrée triviale, HTTPS dev rétabli.

## Phase 2 — Stabilisation technique (semaine 1-2)
- **LOT-2** : suite de tests complète verte (gate CI restauré).
- **LOT-3** : résorber les 13 CVE HIGH (composer/pnpm).
- **LOT-5** : durcir l'isolation tenant (trait manquants, audit jobs, `/media`).
- Objectif : CI fiable, dépendances saines, multi-tenant étanche hors HTTP.

## Phase 3 — Assainissement produit & UX (semaine 2-4)
- **LOT-4** : catalogue honnête (retirer les 3 coquilles vendables).
- **LOT-6** : P0 produit Workforce (euros, validation, self-service congé, sélecteurs, i18n AR/ES/clés manquantes, debounce). Commiter les 15 fichiers UI en attente.
- **Audit UX live** (navigateur) une fois SSL/tests OK, pour valider les parcours réels.
- Objectif : Workforce démontrable et cohérent ; expérience « produit unique » (PP5).

## Phase 4 — Réalignement produit / architecture (mois 2-3)
- **LOT-7** : sortir le métier FR du Core (frontière ADR-562 enfin respectée).
- **LOT-8** : socle horizontal — `contacts` puis `invoicing` (ADR-563). `CreateJobdomainUseCase` complet (ADR-564).
- Objectif : fondation réutilisable, dette P1 résorbée.

## Phase 5 — Reprise du développement (mois 3-4)
- **2ᵉ vertical léger non réglementaire** assemblé du socle horizontal (preuve de largeur, ADR-564).
- Automatisations manquantes (notifications/scheduler Workforce, copilote IA sur Support).
- Objectif : promesse multi-vertical enfin démontrée.

## Phase 6 — Préparation commerciale (mois 4-6)
- Repricing par capacité métier + devises réelles ; email de bienvenue + event `CompanyCreated`.
- Rétention logs/backups/monitoring infra (NON VÉRIFIÉ aujourd'hui → à fiabiliser).
- Internationalisation maîtrisée (2ᵉ market SaaS, sans paie).
- Objectif : produit vendable, onboarding sans intervention technique.

## Jalons de sortie (go/no-go)
- **G1 (fin Phase 2)** : 0 P0 sécurité ouvert, suite de tests verte, SSL dev + prod valides, 0 CVE HIGH.
- **G2 (fin Phase 3)** : Workforce vendable (0 P0 produit), catalogue honnête, UX live validée.
- **G3 (fin Phase 4)** : Core propre (0 métier dans Core), `contacts` + `invoicing` livrés.
- **G4 (fin Phase 5)** : 2ᵉ vertical démontré → promesse plateforme prouvée.
