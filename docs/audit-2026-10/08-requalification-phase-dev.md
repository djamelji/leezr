# Livrable 8 — Audit requalifié « SaaS en développement » + roadmap A/B/C (octobre 2026)

> Contexte intégré : **Leezr est en phase de développement, pas encore commercialisé.** Les seeders, comptes de démo, bases réinitialisables et déploiements simples sont **des atouts à préserver**, pas des défauts. Ce document requalifie les findings du dossier selon leur **exposition réelle** et remplace le plan de remédiation (Livrable 6) par un découpage **A / B / C**.

## 0. Corrections d'honnêteté par rapport à l'audit initial

Deux conclusions de mon audit initial étaient **sur-classées** et sont corrigées ici après vérification directe :

1. **La suite de tests NE HANG PAS (corrigé).** Exécution complète mesurée : **2955 tests verts, 0 échec, ~427 s (~7 min)**. La « contamination `jobdomains.key` » est **infirmée** (`RefreshDatabase` isole chaque test dans une transaction rollbackée ; `JobdomainRegistry::sync()` est idempotent). Les 331 échecs que j'avais observés provenaient d'un run `--filter` partiel (sous-ensemble non représentatif), pas de la suite réelle. **Le vrai problème est la lenteur** : suite séquentielle, `brianium/paratest` installé mais non utilisé, un `sleep(2)` réel dans `DsnPreflightTest.php:220` → sur un timeout CI, se lit comme un hang. → **Requalifié de P1-bloquant à P2-perf.**

2. **Les mécanismes de déploiement : réalité vérifiée.** Il existe **deux** scripts. `deploy.sh` (séparation d'env : `migrate --force` + `SystemSeeder`, `DevSeeder` **uniquement si branche=dev`) n'est **PAS** celui utilisé. Le **CI (`.github/workflows/deploy.yml:182-190`) exécute `deploy/deploy_release.sh`** sur dev **et** prod, lequel fait `migrate:fresh --seed --force` (ligne 187) + `DevSeeder` (ligne 191) avec le commentaire explicite *« Demo data on ALL environments (no real clients yet — temporary) »*. C'est donc un **choix temporaire assumé de phase dev**, pas un accident — mais il n'implémente pas (encore) la séparation d'environnement que tu demandes.

## 1. Grille de requalification des findings

Légende exposition : **🟥 réellement exploitable maintenant** · **🟧 bloque le dev/les tests** · **🟨 dette acceptable temporairement** · **🟦 deviendra critique à la commercialisation** · **⬜ comportement dev normal**.

| Finding | Classif. initiale | Exposition réelle | Requalifié |
|---|---|---|---|
| **SEC-02** secrets prod `@Crinshow31` committés | P0 | 🟥 Un secret réel dans l'historique git est exploitable quel que soit l'env | **RESTE P0 — urgence A** |
| **SEC-01** admin `admin@leezr.com`/`password` | P0 | 🟥 **sur domaine public** (dev.leezr.com, prod si déployé) un super-admin à mot de passe connu est exploitable ; ⬜ en local/test c'est normal | **P0 pour les env PUBLICS (A)** ; ⬜ local/test OK. Fix = séparation d'env, **pas** suppression du seeder |
| **SEC-03** `migrate:fresh --seed` sur tous les env | P1 | 🟦 intentionnel/temporaire, « no real clients » → aucune perte **aujourd'hui** ; 🟥 catastrophique dès qu'il y a des données réelles (dev public ou prod) | **A (garde-fou) sans urgence de perte** : bloquer sur bases sensibles (prod + dev public à données), garder en local/test |
| **SEC-04** `CompanyScope` no-op hors HTTP | P1 | 🟦 fuite inter-tenant réelle **dès** qu'il y a plusieurs tenants + un job non scopé ; peu exposé en dev mono-tenant | **P1 — B** (durcir avant données multi-tenant réelles) |
| **SEC-05** modèles `company_id` sans trait | P1/P2 | 🟦 IDOR latent, dépend du scoping manuel | **P2 — B** |
| **SEC-06** `/media` public | P2 | 🟨 sert avatars/logos (disque `public` par design) ; docs sensibles sur disque `local` privé (OK) | **P3 — vérifier qu'aucun fichier sensible n'est sur `public`, sinon acceptable** |
| **SSL dev** expiré | P1 | 🟧 bloque l'usage de dev.leezr.com ; mauvais signal | **P1 — A** (ré-émission panneau ISPConfig) |
| **TEST-01** suite | P1 (hang) | ⬜ suite verte ; 🟧 lenteur 7 min | **P2 — B** (paratest + retirer sleep) |
| **DEP-01** ~49 advisories (13 HIGH) | P1 | 🟨 dette dev acceptable court terme ; 🟦 à résorber avant prod réelle | **P2 — B** |
| **F2** Workforce 100% synchrone (0 job queue) | P1 | 🟧 timeout HTTP sur génération de paie en masse ; risque opérationnel réel du module | **P1 — B** |
| **WF-01→06** P0 produit Workforce | P1 | 🟦 défauts de **finition produit** (centimes, i18n brutes, self-service, validation) ; bloquent la vendabilité, pas le dev | **B (i18n brutes, validation) + C (euros, self-service, sélecteurs)** |
| **CAT-01** coquilles vendables | P2 | 🟦 ne gêne pas le dev ; problème d'intégrité **commerciale** | **C** (ou quick-win A si démo client prévue) |
| **F3** atomicité upserts Platform | P2 | 🟨 defect réel mais rare | **P2 — B** |
| **I18N/UX** (AR 96%, ES fantôme, debounce, bandeau mort, endpoints fantômes) | P2/P3 | 🟨/⬜ qualité dev | **B/C selon item** |
| **ARCH-01** métier FR dans Core (ADR-562) | — | 🟨 dette assumée (ADR-562 la borne) | **B/C — réalignement planifié (LOT-7)** |

## 2. Ce qui RESTE réellement critique maintenant (malgré la phase dev)

Par ton propre critère (« secret prod committé, faille inter-tenant, compte admin public à mot de passe connu restent critiques même en dev ») :
1. **SEC-02** — rotation + purge du secret `@Crinshow31` (email + vérifier qu'il ne protège pas aussi SSH/ISPConfig).
2. **SEC-01 sur env publics** — l'admin `password` est joignable sur `dev.leezr.com` (domaine public). À protéger : mot de passe non prédéfini hors local/test + 2FA.
3. **SSL dev** — ré-émettre le certificat.

Tout le reste n'est **pas** une urgence de phase dev.

## 3. Séparation d'environnement cible (ta demande, à construire)

| | LOCAL / TEST | DEV PUBLIC (dev.leezr.com) | PRODUCTION |
|---|---|---|---|
| Seeders | complets (fictifs, mdp connus, démo) | contrôlés (admin de test **protégé**, pas de mdp trivial public) | référence **idempotents** seulement, **aucun** admin à mdp prédéfini, aucune donnée fictive |
| `migrate:fresh` | autorisé | **bloqué** (pas de reset auto destructif) | **bloqué** (garde-fou `App::environment`/confirmation) |
| Secrets | `.env` local | `.env` serveur (jamais en git) | `.env` serveur (jamais en git) |
| Réinitialisation | libre | contrôlée | interdite |

**Mécanisme recommandé (simple, sans sur-ingénierie)** : un garde dans le code — ex. `migrate:fresh` refusé si `App::environment('production')` ou si un flag `ALLOW_DESTRUCTIVE_DB=false` ; le seeder d'admin à mot de passe connu encadré par `if (App::environment(['local','testing']))`. Zéro complexité ajoutée, séparation nette.

## 4. Roadmap requalifiée A / B / C

### A — Urgences réelles à corriger maintenant (sécurité exposée, SSL, perte de données)
- **A1** Rotation du secret `@Crinshow31` + retrait du code + purge historique git (SEC-02). *Toi : rotation ; moi : code/env.*
- **A2** Protéger l'admin sur env publics : mdp non prédéfini hors local/test + 2FA super-admin (SEC-01).
- **A3** Garde-fou `migrate:fresh`/reset destructif : interdit en production et sur dev public à données (SEC-03) — **tout en le gardant disponible en local/test**.
- **A4** Ré-émettre le certificat SSL dev (SSL-01, runbook existant).

### B — Stabilisation pour continuer à développer sereinement
- **B1** Séparer les seeders par environnement (local/test complets vs dev-public contrôlés vs prod idempotents) — **sans rien supprimer**.
- **B2** Isolation tenant durcie : trait `BelongsToCompany` manquants + audit des jobs/commandes pour scoping explicite (SEC-04/05).
- **B3** Tests : activer `php artisan test --parallel` (paratest déjà installé) + retirer le `sleep(2)` + timeout CI → boucle de feedback rapide (TEST-01).
- **B4** Workforce async : passer génération PDF bulletins + soumission DSN en jobs `ShouldQueue` (F2).
- **B5** Dépendances : `composer update` CVE HIGH + `pnpm audit` (DEP-01).
- **B6** Qualité dev : corriger `watch debounce` (6 pages), 9 clés i18n `workforceMe.*` + 5 clés EN, endpoints fantômes jobdomains, bandeau conformité (brancher ou retirer), atomicité upserts Platform (F3).
- **B7** Rotation/rétention des logs dev (196 Mo).

### C — Préparation à la commercialisation (plus tard, conforme ADR-561→567)
- **C1** Workforce produit : euros au lieu de centimes, self-service congé scoped, sélecteurs de run (fin des P0 produit).
- **C2** Intégrité catalogue : retirer les 3 coquilles de la vente (CAT-01).
- **C3** Frontière Core (ADR-562) : sortir le métier FR de `app/Core/Workforce/`.
- **C4** Socle horizontal (ADR-563) : `contacts` puis `invoicing` ; `CreateJobdomainUseCase` complet (ADR-564) ; 2ᵉ vertical (preuve de largeur).
- **C5** Durcissement prod : monitoring, sauvegardes (NON VÉRIFIÉ aujourd'hui), i18n AR/ES complètes + RTL Vuetify, finitions UX, repricing par capacité.

## 5. Principe directeur
**Ne rien supprimer des outils de dev. Séparer par environnement, pas appauvrir.** Priorité immédiate = A (3-4 correctifs ciblés, effort S). Puis B pour développer sereinement. C suit la trajectoire ADR-561→567 quand le produit approche de la commercialisation.
