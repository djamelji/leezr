# 04 — Decisions (ADR - Architecture Decision Records)

> Chaque décision structurante est enregistrée ici.
> Format : Date | Contexte | Décision | Conséquences

---

## ADR-001 : Méthodologie BMAD

- **Date** : 2026-02-10
- **Contexte** : Le projet démarre de zéro, besoin d'une méthode pour éviter les dérives
- **Décision** : Appliquer BMAD (Business → Model → Architecture → Decisions) comme système directeur
- **Conséquences** : Pas de code sans documentation préalable, `docs/` est la source de vérité

## ADR-002 : Vuexy comme librairie UI exclusive

- **Date** : 2026-02-10
- **Contexte** : Besoin d'une UI riche sans effort de design
- **Décision** : Toute UI provient exclusivement de Vuexy (presets dans `resources/ui/presets/`, infrastructure dans `resources/js/`). Interdiction d'inventer des composants.
- **Conséquences** : Stock UI fini, extraction en presets avant utilisation, politique documentée dans `06-ui-policy.md`

## ADR-003 : Séparation presets UI / logique métier

- **Date** : 2026-02-10
- **Contexte** : Éviter le couplage entre UI et métier
- **Décision** : Les presets UI vivent dans `resources/ui/presets/`, le métier les consomme sans les modifier
- **Conséquences** : 1 fichier = 1 preset, props explicites, pas de logique métier dans les presets

## ADR-004 : Structure docs/ comme cerveau projet

- **Date** : 2026-02-10
- **Contexte** : Besoin de continuité entre sessions et agents
- **Décision** : Toute décision, règle, audit ou contrainte est consignée dans `docs/bmad/`
- **Conséquences** : `docs/` doit être consulté avant toute action

## ADR-005 : pnpm comme package manager

- **Date** : 2026-02-10
- **Contexte** : Projet Vuexy utilise pnpm
- **Décision** : Utiliser pnpm exclusivement
- **Conséquences** : `pnpm dev:all` pour le dev, pas de npm/yarn

## ADR-006 : Plateforme SaaS multi-tenant modulaire

- **Date** : 2026-02-11
- **Contexte** : Leezr doit servir des métiers différents (logistique, coiffure, restauration…) sans devenir une usine à gaz. Audit BMAD validé.
- **Décision** : Leezr est une plateforme SaaS multi-tenant avec : un core invariant, des modules métier autonomes, un jobdomain comme sélecteur d'expérience, une bulle UX par company.
- **Conséquences** : Le core ne connaît aucun métier. La logique métier vit exclusivement dans les modules. Le jobdomain sélectionne, il ne calcule pas.

## ADR-007 : Premier vertical — Logistique

- **Date** : 2026-02-11
- **Contexte** : Besoin d'un premier métier concret pour valider l'architecture core/modules/jobdomain avant toute généralisation.
- **Décision** : Le premier vertical est la **Logistique**. Il sert à valider : la bulle UX par jobdomain, l'activation de modules, les options de configuration, la séparation core/modules.
- **Conséquences** : Toute l'architecture sera d'abord construite et validée sur ce vertical. Les abstractions seront extraites après, pas avant. Un deuxième vertical sera le test de scalabilité.

## ADR-008 : Modèle de tenancy — DB partagée avec company_id

- **Date** : 2026-02-11 (tranché 2026-02-11)
- **Contexte** : Multi-tenant nécessite un modèle d'isolation des données. Deux options : DB partagée (colonne `company_id`) ou DB par tenant.
- **Décision** : **DB partagée** avec colonne `company_id` sur toute table scopée tenant. Isolation par query scoping côté Laravel, jamais par DB séparée.
- **Conséquences** :
  - Chaque table métier (future) aura un `company_id` (FK → companies)
  - La table `users` n'a PAS de `company_id` — le user est global, lié aux companies via `memberships`
  - L'isolation est garantie par middleware + query scoping, pas par infrastructure DB
  - Simplifie les migrations, le déploiement et la maintenance
  - RGPD : isolation logique, pas physique — à documenter dans les politiques de données

## ADR-009 : Le jobdomain est un profil déclaratif, pas un moteur

- **Date** : 2026-02-11
- **Contexte** : Risque que le jobdomain absorbe progressivement de la logique métier (calculs, validations, règles) et devienne un God Object.
- **Décision** : Le jobdomain est strictement un **profil de configuration déclaratif** : modules par défaut, navigation, dashboard, vocabulaire (labels). Aucun `if (jobdomain === 'x')` dans le code. Si une logique diffère entre métiers, elle vit dans un module.
- **Conséquences** : Ajouter un jobdomain = ajouter une configuration, pas du code. Tout `if/switch` sur jobdomain dans la logique est un code smell à refuser.

## ADR-010 : Isolation UX par pages distinctes, pas par logique conditionnelle

- **Date** : 2026-02-11
- **Contexte** : La "bulle company" change selon le jobdomain. Deux approches : pages différentes sélectionnées par jobdomain, ou mêmes pages avec `v-if` par jobdomain.
- **Décision** : Chaque jobdomain sélectionne des **pages/presets différents** dans le stock Vuexy. Interdit : logique conditionnelle par jobdomain dans les composants. Un coiffeur et un logisticien voient des pages différentes, pas la même page avec des blocs masqués.
- **Conséquences** : La navigation est le point d'entrée de la bulle UX. Chaque jobdomain a sa config de navigation qui pointe vers des pages différentes, toutes assemblées depuis le stock Vuexy.

## ADR-011 : Décisions explicitement reportées

- **Date** : 2026-02-11
- **Contexte** : Certaines décisions ne doivent pas être prises maintenant pour éviter la généricité prématurée.
- **Décision** : Les sujets suivants sont **explicitement reportés** :
  - Marketplace de modules (nécessite d'abord un module qui fonctionne)
  - API publique / webhooks (pas de consommateurs externes)
  - Billing / abonnements (dépend du produit en fonctionnement)
  - Onboarding self-service des companies (valider le parcours manuellement d'abord)
  - Migration de données entre jobdomains (edge case)
  - Multi-langue i18n utilisateur (complexité orthogonale)
- **Conséquences** : Ces sujets ne doivent pas influencer les choix architecturaux actuels. Ils seront traités quand leur besoin sera concret.

## ADR-012 : Couche Public Serving distincte de la SPA back-office

- **Date** : 2026-02-11
- **Contexte** : L'audit "Présence en ligne & Domaines" révèle que les pages publiques des companies (visiteurs anonymes, SEO, cache, milliers de tenants) ont des contraintes fondamentalement différentes de la SPA back-office. Aucune frontière architecturale n'existait entre ces deux modes de serving.
- **Décision** : Créer une couche architecturale **"Public Serving"** distincte du core et des modules. Cette couche :
  - Résout domaine → company (middleware dédié)
  - Sert des pages publiques (potentiellement hors SPA)
  - N'accède qu'aux données explicitement publiées
  - Est cacheable (CDN / HTTP cache)
  - Est isolée du back-office SPA
- **Conséquences** : Le diagramme des couches dans `03-architecture.md` est mis à jour. Le module "présence en ligne" ne fait que gérer le contenu (back-office) ; le Public Serving le rend. L'infrastructure de domaines vit dans le core.

## ADR-013 : Thèmes limités par jobdomain pour la présence en ligne

- **Date** : 2026-02-11
- **Contexte** : Le jobdomain influence l'expérience back-office (ADR-009, ADR-010). Son rôle sur les pages publiques était indéfini. Risque que le jobdomain devienne un "architecte d'expérience publique" violant ADR-009.
- **Décision** : Chaque jobdomain propose un **catalogue restreint de thèmes** (2 à 3 maximum) pour le module "Présence en ligne". Règles :
  - Un **thème = un assemblage de templates/presets existants**, pas une UI sur mesure
  - La company **choisit un thème** parmi ceux proposés pour son jobdomain
  - La **configuration finale** du site appartient à la company, dans les limites du thème choisi
  - Le jobdomain fournit des **defaults**, il ne dicte pas la structure
  - **Aucune logique conditionnelle** par jobdomain dans le rendering public
  - La company **ne peut pas configurer librement** son thème en dehors des options prévues
- **Conséquences** : Le jobdomain reste un sélecteur de defaults (cohérent avec ADR-009). Le champ `public_themes` est ajouté à la structure indicative du jobdomain dans `03-architecture.md`. Cette règle devient référence BMAD.

## ADR-014 : Gestion des domaines = infrastructure core

- **Date** : 2026-02-11
- **Contexte** : La gestion des domaines (sous-domaine, domaine personnalisé, SSL) est une responsabilité transversale. Risque de contamination du core si non isolée, ou de God Module si absorbée par le module "présence en ligne".
- **Décision** : La gestion des domaines (`Domain`, `DomainMapping`, certificats SSL) est une **responsabilité d'infrastructure du core**, pas du module "présence en ligne" :
  - Chaque company a un sous-domaine (`company.leezr.com`), même sans le module "présence en ligne"
  - Le sous-domaine est créé automatiquement à la création de la company
  - La résolution domaine → tenant est un concern de routing
  - Le module "présence en ligne" **consomme** le domaine, il ne le gère pas
- **Conséquences** : La table `domains` vit dans le core. Le provisioning DNS/SSL est une responsabilité d'infrastructure. Le module "présence en ligne" dépend du core pour le domaine, comme tout autre module.

## ADR-015 : Rendering des pages publiques — à trancher

- **Date** : 2026-02-11
- **Contexte** : Les pages publiques de milliers de companies doivent être rapides, indexables (SEO), et cacheables. Le choix du mode de rendering a un impact architectural majeur.
- **Décision** : **À trancher**. Options identifiées :

  | Option | Avantages | Inconvénients |
  |---|---|---|
  | Laravel Blade | SEO natif, rapide, simple, cacheable | Pas de réutilisation des presets Vue |
  | Vue SSR (Inertia/Nuxt) | Réutilise les composants Vue, SEO ok | Complexité stack, overhead serveur |
  | Static generation | Performance maximale, CDN-friendly | Build à chaque modification, latence de publication |
  | SPA Vue avec pré-rendering | Réutilise le framework existant | SEO limité, performance initiale faible |

  **Recommandation audit** : Laravel Blade + cache HTTP est l'option la plus pragmatique et scalable pour des milliers de companies. Les presets Vuexy `front/` servent pour la landing page Leezr.com (SPA), pas pour les mini-sites companies.
- **Conséquences** : Bloquant pour l'implémentation du module "présence en ligne". Doit être tranché avant tout code de serving public.

## ADR-016 : Module "Présence en ligne" = double responsabilité

- **Date** : 2026-02-11
- **Contexte** : Le module "présence en ligne" a une nature atypique par rapport aux modules classiques (fleet, dispatch, billing). Il mélange gestion de contenu (back-office) et serving public (visiteurs anonymes).
- **Décision** : Le module "présence en ligne" est séparé en **deux responsabilités distinctes** :

  | Responsabilité | Lieu | Description |
  |---|---|---|
  | Gestion du site | Module "présence en ligne" (classique) | CRUD des pages, sections, configuration, preview — dans la SPA, pour l'admin de la company |
  | Serving public | Couche Public Serving | Rendu des pages publiques, résolution de domaine, cache — hors SPA, pour les visiteurs anonymes |

- **Conséquences** : Le module ne fait que le CRUD et la configuration. Le Public Serving consomme le contenu publié et le thème sélectionné pour le rendre. Pas de mélange de responsabilités.

## ADR-017 : Environnement local — Laravel Valet HTTPS

- **Date** : 2026-02-11
- **Contexte** : Le projet utilisait `php artisan serve` (HTTP, port 8000). Pour un environnement local proche de la production (HTTPS, domaine `.test`, pas de port explicite), Laravel Valet est plus adapté.
- **Décision** : Utiliser **Laravel Valet** comme serveur local avec HTTPS :
  - Domaine local : `https://leezr.test` (via Valet secure)
  - Valet parke `/Users/djamel/sites` — résolution automatique
  - Vite détecte les certificats TLS Valet via `detectTls: true`
  - `pnpm dev:all` lance uniquement Vite (Valet gère PHP en arrière-plan)
  - `php artisan serve` n'est plus utilisé
- **Conséquences** : `APP_URL=https://leezr.test` dans `.env`. Le script `dev:server` est supprimé de `package.json`. `pnpm dev:all` = `pnpm dev` (Vite seul). Valet doit être installé et démarré sur chaque machine de développement.

## ADR-018 : Déploiement — VPS OVH unique, webhook GitHub

- **Date** : 2026-02-11
- **Contexte** : Le projet a besoin de deux environnements distants : staging (`dev.leezr.com`) pour valider avant production, et production (`leezr.com`). L'infrastructure doit rester simple et maîtrisée au démarrage.
- **Décision** : Déployer sur un **VPS OVH unique (Debian)** avec :
  - **Apache** comme serveur web (deux vhosts : `dev.leezr.com` et `leezr.com`)
  - **MySQL** sur le même VPS
  - **PHP 8.3** (cohérent avec le local)
  - **Webhook GitHub** pour le déploiement automatique :
    - Endpoint : `https://leezr.com/webhook.php`
    - Événement : `push` uniquement
    - Signature vérifiée via secret partagé
    - Le script identifie la branche et déploie sur le bon vhost
  - **Pas de CI/CD externe** (pas de GitHub Actions)
  - **Branches** : `dev` = travail actif, `main` = production, `feature/*` = features
- **Conséquences** :
  - Un seul serveur à gérer au démarrage
  - Les deux environnements partagent les ressources du VPS (acceptable au démarrage, à réévaluer si besoin de scalabilité)
  - `webhook.php` est le seul point d'entrée de déploiement — il doit valider la signature et loguer les déploiements
  - Pas de push direct sur `main` — uniquement via merge depuis `dev`

## ADR-019 : Authentification SPA via Laravel Sanctum (cookie-based)

- **Date** : 2026-02-11
- **Contexte** : La SPA Vue.js a besoin d'une authentification sécurisée vers l'API Laravel. Deux options principales : JWT tokens (Passport) ou cookie-based SPA auth (Sanctum).
- **Décision** : Utiliser **Laravel Sanctum** en mode **SPA cookie-based authentication** :
  - `statefulApi()` activé dans `bootstrap/app.php`
  - Le frontend appelle `/sanctum/csrf-cookie` avant login/register
  - L'auth repose sur les cookies de session Laravel (pas de token dans le localStorage)
  - Pas de Passport, pas de JWT
  - **CORS** : `config/cors.php` publié avec `supports_credentials: true` (obligatoire pour Sanctum SPA)
  - **Credentials** : toute requête fetch utilise `credentials: 'include'` (pas `'same-origin'`) pour garantir l'envoi des cookies
  - **CSRF** : le frontend lit le cookie `XSRF-TOKEN` (URL-décodé) et l'envoie via le header `X-XSRF-TOKEN`
  - **Headers** : `Accept: application/json` sur toutes les requêtes API
- **Conséquences** :
  - Sécurité renforcée (cookies HttpOnly, pas de token exposé côté client)
  - Configuration CORS avec `supports_credentials: true` dans `config/cors.php`
  - `SANCTUM_STATEFUL_DOMAINS` doit inclure les domaines front (`.env`)
  - `SESSION_DOMAIN` doit être `.leezr.test` en local (avec le point pour les sous-domaines)
  - Les requêtes API utilisent `auth:sanctum` comme guard
  - Simplifie l'implémentation par rapport à Passport/JWT
  - **Aucun service worker** ne doit intercepter les requêtes API (incompatible avec le flow cookie/CSRF de Sanctum)

## ADR-020 : Deux scopes applicatifs — Platform et Company

- **Date** : 2026-02-11
- **Contexte** : La plateforme SaaS (Leezr) a deux modes de fonctionnement fondamentalement différents : la gestion globale de la plateforme (supervision, admin) et l'utilisation par les tenants (companies). Ces deux modes ne doivent jamais se mélanger.
- **Décision** : L'application est structurée en **deux scopes physiquement séparés** partageant un Core commun :

  | Scope | Dossier backend | Routes | Middleware | Accès |
  |---|---|---|---|---|
  | **Core** | `app/Core/` | `routes/api.php` | `auth:sanctum` | Auth, models partagés |
  | **Company** | `app/Company/` | `routes/company.php` | `auth:sanctum` + `company.context` | Tenant, membres, données scopées |
  | **Platform** | `app/Platform/` | `routes/platform.php` | `auth:sanctum` + `platform.admin` | Admin SaaS global |

  Règles strictes :
  - **Aucun controller Platform dans Company, aucun controller Company dans Platform**
  - **Aucun `if (is_platform_admin)` dans les controllers Company**
  - Le Core fournit les models et l'auth, les deux scopes le consomment
  - Les rôles company (`owner/admin/user` via `memberships.role`) ne polluent pas le scope Platform
  - Le rôle platform (`is_platform_admin` sur `users`) ne pollue pas le scope Company
  - Un user peut être platform_admin ET membre d'une company (scopes indépendants)

- **Conséquences** :
  - Structure backend : `app/Core/`, `app/Company/`, `app/Platform/`
  - Structure frontend : `resources/js/core/`, `resources/js/company/`, `resources/js/platform/`
  - Enregistrement des routes et middleware dans `bootstrap/app.php` (pattern Laravel 12)
  - Le scope Platform est structuré mais fonctionnellement vide en LOT 1

## ADR-021 : Module system — platform-defined, company-activated

- **Date** : 2026-02-11
- **Contexte** : La plateforme doit supporter des modules fonctionnels (fleet, dispatch, billing…) activables par company. Le catalogue de modules est global (plateforme), l'activation est locale (company). Il faut un mécanisme universel avant tout module métier.
- **Décision** : Système de modules à deux niveaux :
  - **`platform_modules`** : catalogue global défini par la plateforme (key unique, enabled/disabled globalement)
  - **`company_modules`** : activation par company (lien explicite vers `platform_modules.key`)
  - **Règle d'activation** : un module est actif pour une company si et seulement si :
    1. `platform_modules.is_enabled_globally = true`
    2. `company_modules` row existe pour cette company + module_key
    3. `company_modules.is_enabled_for_company = true`
  - Le catalogue est alimenté par un `ModuleRegistry` déclaratif (seeder `updateOrCreate`)
  - Chaque module expose des **capabilities** déclaratives (nav, routes, guards) — voir ADR-022
- **Conséquences** :
  - La plateforme contrôle l'existence et la disponibilité globale des modules
  - La company ne peut activer que ce qui est autorisé globalement
  - Si la plateforme désactive un module, il est inactif pour toutes les companies (même si activé localement)
  - Le `ModuleGate` centralise la logique d'activation (pas de `if` dispersés)
  - Le middleware `EnsureModuleActive` protège les routes scopées par module
  - Pas de RBAC par permission dans ce lot — les capabilities suffisent

## ADR-022 : Capabilities déclaratives (pas de RBAC par permission)

- **Date** : 2026-02-11
- **Contexte** : Chaque module doit exposer ce qu'il apporte à l'application (navigation, routes, gardes) sans que le core doive connaître le contenu de chaque module. Un RBAC granulaire par permission est prématuré.
- **Décision** : Chaque module déclare ses **capabilities** dans le `ModuleRegistry` :
  - `nav_items` : entrées de navigation à injecter dans le menu (label, route, icon)
  - `route_names` : noms de routes appartenant au module (pour le filtrage côté router)
  - `middleware_key` : clé utilisée par `EnsureModuleActive` pour protéger les routes du module
  - Les capabilities sont **déclaratives et statiques** — pas de logique, pas de calcul
  - Le frontend consomme les capabilities pour filtrer la navigation et les routes dynamiquement
  - Aucune permission granulaire dans LOT 2 — le module est "tout ou rien" (actif ou inactif)
- **Conséquences** :
  - Ajouter un module = ajouter une entrée dans le registry avec ses capabilities
  - L'UI filtre automatiquement les nav items et routes selon les modules actifs
  - Pas de tables de permissions, pas de RBAC — la gouvernance reste owner/admin/user (LOT 1)
  - Extensible vers un RBAC futur sans casser l'existant

## ADR-023 : Exposition UI — navigation et routes filtrées par capabilities

- **Date** : 2026-02-11
- **Contexte** : Les modules actifs doivent se refléter dans l'UI (menu, pages accessibles). Il faut un mécanisme centralisé plutôt que des `v-if` dispersés.
- **Décision** : L'exposition UI des modules est capabilities-driven :
  - Le endpoint `GET /api/modules` retourne les modules actifs avec leurs capabilities (nav_items, route_names)
  - Le frontend stocke les modules actifs dans le `useModuleStore` (Pinia)
  - La navigation est construite dynamiquement : items statiques LOT 1 + nav_items des modules actifs
  - Les gardes router vérifient que la route appartient à un module actif (via route_names)
  - Le backend protège les routes via le middleware `EnsureModuleActive` (source de vérité)
  - La UI filtre côté client pour l'UX, mais le backend est l'autorité finale
- **Conséquences** :
  - Un module désactivé disparaît du menu ET ses routes sont protégées (double protection)
  - Pas de `v-if(module === 'xxx')` dans les pages — la navigation est data-driven
  - Les pages LOT 1 (dashboard, settings, members, profile) restent accessibles sans module
  - Mécanisme prêt pour les modules métier futurs sans modification du core UI

## ADR-024 : Jobdomain déclaratif — sélection, pas calcul

- **Date** : 2026-02-11
- **Contexte** : Le jobdomain influence l'UX d'une company (navigation, landing page, modules par défaut). Risque de transformer le jobdomain en God Object portant de la logique métier.
- **Décision** : Le jobdomain est un **profil de configuration déclaratif** :
  - Il sélectionne : landing route, profil de navigation, modules par défaut
  - Il ne calcule rien, ne porte aucune logique métier
  - Toute résolution passe par `JobdomainRegistry` (définitions statiques) et `JobdomainGate` (service de résolution)
  - **Aucun `if (jobdomain === 'xxx')` dispersé dans le code** — tout passe par le Gate/Registry
  - Le Registry est hardcodé (pas en DB) : ajouter un jobdomain = ajouter une entrée au Registry
  - La table `jobdomains` stocke les métadonnées (key, label), le Registry porte la logique déclarative
- **Conséquences** :
  - Ajouter un jobdomain = ajouter une entrée dans `JobdomainRegistry::definitions()` + seed
  - Pas de `switch/if` sur jobdomain dans les controllers, pages ou composants
  - Le frontend consomme le profil résolu via l'API, jamais la clé brute

## ADR-025 : Company = exactement 1 jobdomain

- **Date** : 2026-02-11
- **Contexte** : Une company doit avoir un contexte métier pour personnaliser son UX. Deux options : multi-jobdomain (company peut combiner plusieurs profils) ou mono-jobdomain (1 company = 1 profil).
- **Décision** : **1 company = 1 jobdomain** (relation one-to-one via `company_jobdomain` pivot) :
  - `company_jobdomain` : `company_id` UNIQUE, `jobdomain_id` FK
  - Nullable au départ (la company existe avant d'avoir un jobdomain assigné)
  - Doit devenir défini dès l'onboarding (futur LOT)
  - L'assignation d'un jobdomain active automatiquement les modules par défaut du profil
- **Conséquences** :
  - Pas de gestion multi-jobdomain — simplicité architecturale
  - La relation est via pivot (pas de colonne `jobdomain_id` sur `companies`) pour éviter de modifier la table core
  - Le changement de jobdomain est possible mais rare (admin+ uniquement)

## ADR-026 : Premier module métier — Logistics Shipments

- **Date** : 2026-02-11
- **Contexte** : Le core SaaS (LOT 1), le module system (LOT 2) et le jobdomain system (LOT 3) sont en place. Il faut valider l'architecture avec un premier vrai module métier.
- **Décision** : Le premier module métier est **logistics_shipments** — un CRUD d'expéditions avec workflow de statuts :
  - **Module key** : `logistics_shipments`
  - **Entité** : `Shipment` (reference, status, origin_address, destination_address, scheduled_at, notes, company_id, created_by_user_id)
  - **Statuts** : `draft → planned → in_transit → delivered`. Tout statut non terminal → `canceled`.
  - **Référence** : format `SHP-YYYYMMDD-XXXX` (auto-généré)
  - **CRUD** : Create (admin+), List (all), Show (all), ChangeStatus (admin+). Pas de Delete.
  - **Protection** : middleware `module.active:logistics_shipments` sur toutes les routes shipment
  - **Scope** : Company (scopé par `company_id`, routes dans `company.php`)
  - **Architecture** : controller dans `app/Company/Http/Controllers/`, model dans `app/Core/Models/`, Form Requests dans `app/Company/Http/Requests/`
- **Conséquences** :
  - Valide le contrat module métier défini dans `03-architecture.md`
  - Le module est enregistré dans `ModuleRegistry` avec ses capabilities (nav, routes, middleware)
  - Le jobdomain "logistique" inclut ce module dans ses `default_modules`
  - Pattern réutilisable pour tous les modules métier futurs

## ADR-027 : Frontend Module Guards — protection des routes par module

- **Date** : 2026-02-11
- **Contexte** : Le backend protège les routes module via `module.active:{key}` middleware, et la navigation dynamique n'affiche que les modules actifs. Cependant, un utilisateur peut accéder manuellement à une URL de module inactif (ex: `/company/shipments`), ce qui provoque un 403 backend disgracieux. Le frontend doit être aligné avec le backend.
- **Décision** : Implémenter un **guard global dans le router Vue** qui bloque les routes de modules inactifs :
  - Chaque page liée à un module déclare `meta.module` via `definePage()` (ex: `{ meta: { module: 'logistics_shipments' } }`)
  - Le guard `beforeEach` vérifie `route.meta.module` via `moduleStore.isActive(key)`
  - Si le module est inactif : redirection vers `/`, toast "Module not available"
  - Aucun appel API n'est déclenché si le module est inactif côté frontend
  - Un composable `useAppToast` fournit le système de feedback (VSnackbar dans App.vue)
- **Conséquences** :
  - Navigation et router parfaitement alignés : le menu n'affiche que les modules actifs, le router les bloque aussi
  - Pas de 403 backend visible par l'utilisateur
  - Pattern déclaratif : tout nouveau module ajoute `meta.module` à ses pages
  - Aucun changement backend nécessaire

## ADR-028 : LOT 5 — Hardening, Isolation & Production Readiness

- **Date** : 2026-02-11
- **Contexte** : Le SaaS est fonctionnel (LOTs 1-4.5) mais présente des failles de sécurité (CORS ouvert, pas de rate limiting) et des lacunes d'observabilité (pas de logging structuré, pas d'événements module). Le frontend manque de gestion d'erreurs API globale et de résilience au changement de company en cours de session.
- **Décision** : Implémenter un LOT de hardening qui ne rajoute aucun module métier mais renforce l'architecture existante :
  - **CORS** : restreindre `allowed_origins` aux domaines explicites via env `CORS_ALLOWED_ORIGINS`, restreindre `allowed_methods` et `allowed_headers`
  - **Rate limiting** : `throttle:5,1` sur `/api/login` et `/api/register`
  - **Module lifecycle events** : `ModuleEnabled` et `ModuleDisabled` dispatchés lors de l'activation/désactivation (audit trail, futur billing)
  - **Structured logging** : `Log::info` sur module enable/disable, jobdomain change, shipment status change
  - **Frontend API error handler** : interception globale des erreurs HTTP (401→login, 403→toast, 419→CSRF retry, 500→toast)
  - **Frontend company switch resilience** : watch `currentCompanyId`, re-fetch modules automatique, redirection si route module inactive
- **Conséquences** :
  - Aucun endpoint exposé sans guard
  - Aucun module accessible hors activation
  - Aucun accès cross-company possible
  - Erreurs API proprement gérées côté frontend
  - Observabilité suffisante pour debug et futur audit trail

## ADR-029 : Platform RBAC — table de rôles, pas boolean

- **Date** : 2026-02-11
- **Contexte** : L'accès Platform est contrôlé par un simple boolean `is_platform_admin` sur le model User. C'est trop grossier pour la gouvernance future (support, billing admin, ops). Le Platform RBAC doit être structurellement séparé du Company RBAC (`memberships.role`).
- **Décision** : Créer une table `platform_roles` (id, key unique, name, timestamps) et un pivot `platform_role_user` (user_id FK, platform_role_id FK, UNIQUE(user_id, platform_role_id)). Le model User gagne `hasPlatformRole($key)` et `isSuperAdmin()` qui interrogent la table de rôles. Le boolean legacy `is_platform_admin` reste en DB mais ne pilote plus l'accès. Un nouveau middleware `EnsurePlatformRole` remplace `EnsurePlatformAdmin` et accepte un paramètre rôle (ex: `platform.role:super_admin`).
- **Conséquences** :
  - L'alias middleware `platform.admin` est remplacé par `platform.role`
  - Toutes les routes platform utilisent `platform.role:super_admin`
  - Les futurs rôles platform (support, billing_admin) peuvent être ajoutés sans migration
  - Le bool `is_platform_admin` est conservé pour backward compat mais effectivement déprécié

## ADR-030 : Platform Backoffice comme Control Plane SaaS

- **Date** : 2026-02-11
- **Contexte** : Le scope Platform existe structurellement (ADR-020) mais n'a pas de backoffice UI. Les admins platform doivent gérer les companies (suspendre/réactiver), les users (assigner rôles platform), et les modules (toggle global). L'endpoint `/me` doit exposer les platform_roles pour que le frontend enforce l'accès.
- **Décision** : Construire un Platform Backoffice avec 4 pages (dashboard, companies, users, modules). Les pages platform vivent dans `resources/js/pages/platform/` et utilisent un layout dédié `platform.vue` avec une navigation spécifique. Un `platformGuard` dans le router bloque les routes `/platform/*` pour les non-super_admin. L'API retourne `platform_roles` dans les réponses `/me` et `/login`. La suspension company est enforced dans le middleware `SetCompanyContext` (ajout d'un check status après Company::find, avant isMemberOf). Ajout d'une colonne `status` (active|suspended) à la table companies.
- **Conséquences** :
  - Platform et Company UIs physiquement séparées (répertoires pages distincts, configs nav distinctes)
  - Le auth store expose un getter `isPlatformAdmin`
  - Company suspendue → 403 sur toutes routes company-scoped (aucun module métier impacté)
  - Aucun `if(is_platform_admin)` dans les controllers Company

## ADR-031 : Platform Identity Split — table séparée `platform_users`

- **Date** : 2026-02-11
- **Contexte** : LOT 6 stockait les admins platform dans la table `users` (company users). Le model User portait des méthodes platform (`platformRoles()`, `hasPlatformRole()`, `isSuperAdmin()`), créant un couplage architectural entre les deux identités. Les platform employees n'ont rien à faire dans la table `users`.
- **Décision** : Créer une table **`platform_users`** séparée avec son propre model `PlatformUser extends Authenticatable`. Le pivot `platform_role_user` référence `platform_user_id` FK → `platform_users` (et non plus `user_id` FK → `users`). Le model `User` est nettoyé de toute référence platform. La colonne `is_platform_admin` reste en DB (dead data, pas de migration destructive) mais ne pilote plus rien.
- **Conséquences** :
  - Deux identités physiquement séparées : company users dans `users`, platform employees dans `platform_users`
  - Aucun User n'a de platform_roles, aucun PlatformUser n'a de company membership
  - Les deux auth systems sont complètement indépendants

## ADR-032 : Platform auth endpoints + guard `auth:platform`

- **Date** : 2026-02-11
- **Contexte** : LOT 6 utilisait `auth:sanctum` pour les routes platform (resolves against `users` table). Avec la séparation, platform auth doit utiliser son propre guard.
- **Décision** : Créer un guard `platform` (driver session, provider `platform_users`) dans `config/auth.php`. Les endpoints platform auth sont `/api/platform/login`, `/api/platform/me`, `/api/platform/logout` via `PlatformAuthController`. `config/sanctum.php` reste inchangé (`guard: ['web']`), garantissant que `auth:sanctum` ne résout jamais un platform user.
- **Conséquences** :
  - `auth:platform` pour les routes platform, `auth:sanctum` pour les routes company
  - Sessions distinctes par guard (Laravel gère nativement le multi-guard session)
  - Cross-scope impossible : company session → platform routes = 401, platform session → company routes = pas de company context

## ADR-033 : Platform RBAC sur `platform_users`

- **Date** : 2026-02-11
- **Contexte** : Le pivot `platform_role_user` référençait `user_id`. Avec ADR-031, il doit référencer `platform_user_id`.
- **Décision** : Drop et recréer `platform_role_user` avec `platform_user_id` FK → `platform_users`, `platform_role_id` FK → `platform_roles`, UNIQUE(platform_user_id, platform_role_id). Le model `PlatformUser` porte `roles()`, `hasRole()`, `isSuperAdmin()`.
- **Conséquences** :
  - Le middleware `EnsurePlatformRole` utilise `$request->user('platform')?->hasRole($role)`
  - Aucune dépendance vers le model User

## ADR-034 : Platform Backoffice pages — arborescence par domaine

- **Date** : 2026-02-11
- **Contexte** : Le backoffice platform LOT 6 avait une seule page `users.vue` qui gérait les company users + roles. Avec la séparation, il faut distinguer CRUD PlatformUser et supervision company users.
- **Décision** : Structure pages platform par domaine :
  - `platform/index.vue` → Dashboard
  - `platform/companies.vue` → Companies management
  - `platform/users.vue` → CRUD PlatformUser (employees SaaS)
  - `platform/company/users.vue` → Read-only supervision des company users
  - `platform/roles.vue` → CRUD PlatformRole
  - `platform/modules.vue` → Module catalog management
  - `platform/login.vue` → Platform login (blank layout, platformAuth store)
- **Conséquences** :
  - Auto-route names : `platform-users` (PlatformUser CRUD), `platform-company-users` (readonly), `platform-roles`
  - Stores séparés : `platformAuth.js` (auth platform), `platform.js` (CRUD platform)
  - API client séparé : `$platformApi` (baseURL `/api/platform`, pas de `X-Company-Id`)

## ADR-035 : Platform RBAC Permissions Layer

- **Date** : 2026-02-12
- **Contexte** : LOT 6B a instauré un RBAC basé sur des rôles uniquement (`PlatformUser → roles`). Le seul contrôle d'accès est `platform.role:super_admin`, ce qui est trop grossier pour des rôles futurs (support, billing_admin, ops). Il faut une couche de permissions granulaires sans casser l'identity split.
- **Décision** : Ajouter une entité **`PlatformPermission`** (key, label) et une relation many-to-many `PlatformRole ↔ PlatformPermission` via pivot `platform_role_permission`. Le contrôle d'accès aux routes platform est piloté par un middleware `platform.permission:{key}` qui vérifie `PlatformUser → roles → permissions`. Le rôle `super_admin` bypass automatiquement toute vérification de permission. Les permissions initiales sont : `manage_companies`, `view_company_users`, `manage_platform_users`, `manage_roles`, `manage_modules`. Le middleware `platform.role` est conservé uniquement si nécessaire (bootstrap), les routes utilisent `platform.permission` pour le contrôle d'accès granulaire.
- **Conséquences** :
  - Nouvelle table `platform_permissions` (id, key unique, label, timestamps)
  - Nouveau pivot `platform_role_permission` (platform_role_id FK, platform_permission_id FK, UNIQUE)
  - `PlatformRole` gagne `permissions()` et `hasPermission()`
  - `PlatformUser.hasPermission()` : `isSuperAdmin() → true`, sinon check via `roles→permissions`
  - Nouveau middleware `platform.permission` enregistré dans `bootstrap/app.php`
  - Routes platform utilisent `platform.permission:{key}` au lieu de `platform.role:super_admin`
  - Le CRUD `PlatformRole` accepte `permissions[]` dans store/update avec `sync()`
  - Le frontend expose les permissions dans le store et les utilise pour la navigation conditionnelle
  - Aucun impact sur le scope Company — isolation totale maintenue

### LOT 6C.1 — RBAC Hardening (2026-02-12)

**Invariants** :
- Toutes les routes `api/platform/*` (hors login) → `auth:platform`
- Toute route métier platform → protégée par `platform.permission:{key}`
- Les rôles ne protègent jamais directement une route — les permissions sont la seule unité d'autorisation
- `super_admin` bypass uniquement côté backend via `PlatformUser::hasPermission()`
- `/api/platform/me` retourne `{ user, roles, permissions }` — source de vérité frontend
- `PermissionCatalog` (`app/Platform/RBAC/PermissionCatalog.php`) = source unique de vérité pour les permissions
- Le middleware `platform.role` est supprimé — seul `platform.permission` subsiste
- Le menu platform est filtré dynamiquement par `platformAuth.hasPermission()`

**Convention pages platform** (obligatoire, hors dashboard/login) :
```js
definePage({
  meta: {
    layout: 'platform',
    platform: true,
    permission: 'permission_key',
  },
})
```

**Protection super_admin** :
- Impossible de retirer le rôle `super_admin` au dernier super_admin → 409 Conflict
- Impossible de supprimer le dernier super_admin → 409 Conflict
- Aucune logique JS pour ça — backend est autorité finale

---

## ADR-036 : Deployment Discipline — seeders et migrations

- **Date** : 2026-02-12
- **Contexte** : Le déploiement distant (webhook → `deploy-leezr.sh`) exécute `migrate --force` mais ne seed pas. Les seeders contenaient des `create()` non idempotents nécessitant `migrate:fresh`. La migration 500002 utilisait `dropIfExists` (perte de données). Il faut un système de déploiement safe et automatisable.
- **Décision** : Discipline stricte de déploiement :

  **Seeders — séparation SystemSeeder / DevSeeder** :
  - `SystemSeeder` : données système (RBAC, modules, jobdomains). 100% idempotent (`updateOrCreate`). Safe en prod.
  - `DevSeeder` : données de demo (users test, company, shipments). Idempotent aussi, mais **jamais exécuté hors local**.
  - `DatabaseSeeder` : dispatch — appelle `SystemSeeder` toujours, `DevSeeder` uniquement si `APP_ENV=local`.
  - Le script de déploiement distant exécute `php artisan db:seed --class=SystemSeeder --force`.

  **Migrations — règles non négociables** :
  - `migrate:fresh` interdit en staging/production.
  - `migrate --force` est le seul mode d'exécution distant.
  - Toute migration doit être **non destructive** : pas de `dropIfExists` sur des tables avec données.
  - Les migrations de transition (changement de FK, renommage) utilisent : ajout colonne → migration data → suppression ancienne colonne.
  - Toute migration doit être **idempotente** (guards `Schema::hasColumn` si nécessaire).

  **Pipeline de déploiement** :
  ```
  push → webhook.php → deploy-leezr.sh
    ├── git pull
    ├── composer install --no-dev
    ├── php artisan migrate --force
    ├── php artisan db:seed --class=SystemSeeder --force
    ├── pnpm install && pnpm build
    └── php artisan optimize
  ```

- **Conséquences** :
  - Les données système (permissions, rôles, modules, jobdomains, super_admin) sont garanties après chaque déploiement.
  - Les données de demo n'existent qu'en local.
  - Aucune migration ne peut casser la production.
  - Le `PermissionCatalog` est la source de vérité — les permissions retirées du catalog sont nettoyées par `SystemSeeder`.

---

## ADR-037 : Auth & Session Hardening + Password Lifecycle

- **Date** : 2026-02-12
- **Contexte** : L'audit AUTH/SESSIONS/SECURITY a révélé un score SaaS-readiness de 6.5/10. Cinq incohérences critiques : session non régénérée au register (fixation), redirections open-redirect (login + guards), helpers CSRF dupliqués entre `api.js` et `platformApi.js`, absence de hydratation session au boot frontend, et 401 handler qui casse le SPA (`window.location.href`). La couche password n'existe pas (pas d'invitation, pas de reset, politique non centralisée). Plan validé en 6 LOTs.
- **Décision** : Hardening auth/sessions en **6 LOTs ordonnés** :

  **LOT-AUTH-1 — Session & Middleware Correctness (P0)** :
  - `AuthController::register()` : ajouter `$request->session()->regenerate()` après `Auth::login()`
  - Extraire `resources/js/utils/csrf.js` comme module unique (supprime duplication api.js/platformApi.js)
  - `platformApi.js` : nettoyer `platformPermissions` en plus de `platformRoles` sur 401
  - Supprimer `options.credentials = 'include'` redondant dans `api.js` (déjà default d'ofetch)

  **LOT-AUTH-2 — Redirect Policy + Guards + Session Hydration (P0)** :
  - Créer `resources/js/utils/safeRedirect.js` : valide que la destination est same-origin uniquement
  - Réécrire `guards.js` : appeler `fetchMe()` sur première navigation (flag `_hydrated` dans les stores)
  - Intercepteur 401 dans `api.js`/`platformApi.js` : flag `_sessionExpired` + `router.push('/login')` (pas `window.location`)
  - Supprimer la checkbox "Remember me" de `login.vue` (non implémentée)
  - Module guard : fail-closed (si fetch modules échoue → redirection, pas silent allow)

  **LOT-AUTH-5 — Password Lifecycle & Policy (P0)** :
  - Classe centralisée `App\Core\Auth\PasswordPolicy` : `min:8|mixedCase|numbers|symbols|uncompromised`
  - Migrations : `users.password` nullable, `platform_users.password` nullable, table `platform_password_reset_tokens`
  - Flow d'invitation : création user sans password → token via Password Broker → notification mail avec lien set-password
  - `MembershipController::store()` : si email inconnu → créer user (password null) + envoyer invitation
  - `PlatformUserController::store()` : supprimer champ password → créer avec null + envoyer invitation
  - `PlatformUserController::update()` : supprimer champ password (credential management séparé)
  - Endpoint dédié `POST /api/platform/users/{id}/reset-password` protégé par `platform.permission:manage_platform_user_credentials`
  - Nouvelle permission `manage_platform_user_credentials` dans `PermissionCatalog`
  - Config `auth.php` : broker `platform_users` (provider `platform_users`, table `platform_password_reset_tokens`, expire 60)
  - Pages frontend depuis presets Vuexy : `forgot-password-v2.vue`, `reset-password-v2.vue`
  - `PlatformUser` : ajouter trait `Notifiable` + override `sendPasswordResetNotification()`

  **LOT-AUTH-4 — Security Hardening (P1)** :
  - Créer `.env.production.example` avec les valeurs sécurisées (SESSION_SECURE_COOKIE, SESSION_SAME_SITE, etc.)
  - Rate limiting : `throttle:5,1` sur login/register endpoints
  - Navigation platform : filtrer dynamiquement par `platformAuth.hasPermission()` (déjà en place, valider)

  **LOT-DEVX-AUTH — Dev Tooling & Smoke Tests (P2)** :
  - Feature tests Laravel : login, register, logout, session regeneration, CSRF, 401/403 responses
  - Tests invitation flow : create user without password, token generation, password set
  - Documentation Mailpit pour dev local
  - Smoke test checklist dans `docs/`

- **Conséquences** :
  - Session fixation éliminée (regenerate systématique)
  - Open redirect éliminé (safeRedirect same-origin)
  - CSRF helpers centralisés (un seul module)
  - Session hydratée au boot (fetchMe obligatoire)
  - 401 handler SPA-compatible (router.push, pas window.location)
  - Password lifecycle complet (invitation-first, reset, politique centralisée)
  - Aucun password en clair dans les controllers (PasswordPolicy seule source de validation)
  - Dual-scope password reset (users + platform_users, brokers séparés)
  - Aucun impact sur le scope Company existant (isolation maintenue)

  **UX Alignment (2026-02-12)** :

  **LOT-UX-AUTH-1 — Platform User Drawer Completion** :
  - `PlatformUser` : accessor `status` (invited/active) via `$appends`, basé sur `is_null(password)`. Aucun hash exposé (password dans `$hidden`).
  - `PlatformUserController::store()` : accepte `invite` (bool, default true). Si `invite=false`, `password` + `password_confirmation` requis et validés via `PasswordPolicy::rules()`. Pas d'envoi d'invitation si mot de passe fourni.
  - Nouvel endpoint `PUT /api/platform/platform-users/{id}/password` : permet de définir manuellement un mot de passe (credential management). Protégé par `manage_platform_user_credentials`.
  - Drawer create : choix radio "Send invitation" / "Set password now" (visible seulement si permission `manage_platform_user_credentials`).
  - Drawer edit : section Credential Management (force reset + set password manually). Cachée si super_admin ou self.
  - Bouton delete caché (pas disabled) pour les super_admin.

  **LOT-UX-AUTH-2 — Company Members Status** :
  - `MembershipController::index()` : eager load `password` côté serveur uniquement, calcul `status` dans le map. Aucun hash envoyé au frontend.
  - Colonne Status dans VTable (VChip warning "Invitation pending" / success "Active").
  - AddMemberDrawer : info alert "invitation automatique si email inconnu".

  **LOT-UX-AUTH-3 — Password Pages Polish** :
  - Composable `usePasswordStrength.js` : règles visuelles matching `PasswordPolicy` (8 chars, majuscule, minuscule, chiffre, caractère spécial).
  - Pages reset-password (company + platform) : VProgressLinear + checklist de règles sous le champ password.
  - Pages forgot-password : bouton désactivé après envoi.

---

## ADR-038 : Invitation status via `password_set_at` (pas password null check)

- **Date** : 2026-02-12
- **Contexte** : LOT-UX-AUTH-1 déterminait le statut d'invitation des `PlatformUser` via `is_null($this->password)`. Pour les company Users, cette approche est architecturalement incorrecte : elle couple le statut métier (invited/active) à un détail d'implémentation (la colonne password). Le password hash ne doit jamais être lu, même côté serveur, pour dériver un statut métier.
- **Décision** : Introduire un champ dédié `password_set_at` (nullable timestamp) sur la table `users`. Le statut d'invitation est déterminé exclusivement par ce champ :
  - `password_set_at IS NULL` → statut `invited`
  - `password_set_at IS NOT NULL` → statut `active`
  - Le champ est mis à jour (`now()`) dans tous les cas où un mot de passe est défini : self-registration, reset-password, set-password
  - Un accessor `status` sur le model User expose le statut via `$appends`
  - Le `MembershipController` retourne `user.status` au frontend — aucun hash exposé, aucun password lu
  - Migration avec backfill : les users existants avec password non-null reçoivent `password_set_at = created_at`
- **Conséquences** :
  - Le statut d'invitation est un concept de domaine premier, pas un dérivé technique
  - Le frontend affiche un VChip (warning "Invitation pending" / success "Active") dans la table members
  - Le `AddMemberDrawer` affiche un info alert expliquant l'envoi automatique d'invitation
  - Pattern réutilisable si `PlatformUser` migre vers la même approche (ADR futur)
  - Aucun impact sur le scope Platform — isolation maintenue

### UX Password Strength & Dev Mail Alignment (LOT-UX-AUTH-3 + LOT-DEVX-AUTH)

- **Date** : 2026-02-12
- **Décisions** :
  - Le composable `usePasswordStrength` reflète exactement les règles de `PasswordPolicy` (min 8, mixedCase, numbers, symbols). Aucune duplication de logique dans les controllers ou les pages.
  - Le check `uncompromised` (Have I Been Pwned) reste backend-only — pas de simulation côté frontend.
  - Les pages reset-password (company + platform) affichent un VProgressLinear + checklist de règles sous le champ password.
  - Les pages forgot-password (company + platform) désactivent le bouton submit après succès pour empêcher la double soumission.
  - Mailpit est l'outil de test email en dev local (SMTP `127.0.0.1:1025`, UI `http://localhost:8025`).
  - Mailpit n'est pas démarré par `pnpm dev:all` (Vite seul). `pnpm dev:leezr` lance Vite + Mailpit en parallèle via `concurrently`.
  - La configuration mail prod n'est pas impactée (`.env.production.example` non modifié).

---

## ADR-039 : Dynamic Field System (Champs dynamiques multi-scope)

- **Date** : 2026-02-13
- **Contexte** : Les profils company, user et platform user ont besoin de champs extensibles sans modification de schéma. Le système doit supporter des champs définis par la plateforme, activés par company, et remplis par entité. Aucun pattern de champs dynamiques n'existait dans le codebase.
- **Décision** : Architecture EAV (Entity-Attribute-Value) en trois couches :
  - **FieldDefinition** (catalogue) : code unique, scope (enum DB : `platform_user`, `company`, `company_user`), type (enum DB : `string`, `number`, `boolean`, `date`, `select`, `json`), validation_rules, options. `code`/`scope`/`type` immutables après création.
  - **FieldActivation** (par company) : company_id (nullable pour platform_user scope), enabled, required_override, order. Max 50 activations par scope par company.
  - **FieldValue** (par entité, morph) : field_definition_id + model_type/model_id (morphMap enforced), value (JSON). Unique constraint (definition, model_type, model_id).
  - **Services** : `FieldResolverService` (3 queries max, pas de N+1), `FieldValidationService` (retourne des règles, ne mutate pas), `FieldWriteService` (transactional, vérifie activation avant écriture).
  - **ReadModels** : `CompanyProfileReadModel`, `CompanyUserProfileReadModel`, `PlatformUserProfileReadModel` — seuls points de lecture des dynamic_fields (jamais dans les controllers directement). Uniquement sur les endpoints show/profile, pas sur les index.
  - **FieldDefinitionCatalog** : registry des champs système (siret, vat_number, legal_form, phone, job_title, internal_note). `sync()` idempotent, ne modifie jamais scope/type des champs existants.
  - **Frontend** : `DynamicFormRenderer.vue` générique (type → App* wrapper). Aucune logique métier frontend.
  - **Permission** : `manage_field_definitions` dans PermissionCatalog.
  - **MorphMap** : `user` → User, `company` → Company, `platform_user` → PlatformUser (enforced globalement).
- **Conséquences** :
  - Aucune modification des schémas existants (users, companies, platform_users)
  - Les champs système (`is_system = true`) ne peuvent pas être supprimés
  - Cross-tenant write impossible (activation vérifiée avant écriture)
  - Partial update ne wipe pas les valeurs EAV existantes
  - Extensible à de futurs scopes (clients, fournisseurs) sans migration
  - Aucun eager loading de dynamic_fields dans les endpoints de liste

---

## ADR-040 : JobDomain × Fields — Presets de champs par métier

- **Date** : 2026-02-13
- **Contexte** : ADR-039 a instauré le Dynamic Field System avec FieldDefinition → FieldActivation → FieldValue. Le système fonctionne mais chaque company démarre avec zéro champ activé. Il n'existe aucun lien entre le jobdomain (profil métier) et les champs dynamiques. Une company "Logistique" devrait avoir ses champs métier pré-activés dès l'assignation du jobdomain.
- **Décision** : Étendre le `JobdomainRegistry` avec un champ `default_fields` (liste structurée `[{code, required, order}]`). Lors de `JobdomainGate::assignToCompany()`, après l'activation des modules par défaut, les champs listés dans `default_fields` sont automatiquement activés pour la company :
  - Pour chaque entrée dans `default_fields`, une `FieldActivation` est créée (`updateOrCreate` idempotent)
  - Le scope est lu depuis la `FieldDefinition` — seuls `company` et `company_user` sont applicables
  - Les activations de scope `platform_user` sont ignorées (filtrage `whereIn`)
  - `enabled = true`, `required_override` et `order` proviennent du preset structuré
  - L'activation est idempotente : si le champ est déjà activé, rien ne change
  - Le guard max 50 n'est pas vérifié lors de l'assignation jobdomain (les presets sont supposés raisonnables)
- **Conséquences** :
  - Ajouter un jobdomain = ajouter default_modules + default_fields dans le Registry
  - L'expérience onboarding est cohérente : jobdomain → modules + champs en une opération
  - Les presets sont persistés en DB (via Registry::sync()) et éditables via l'admin platform
  - Le mécanisme est extensible à de futurs jobdomains sans migration
  - L'isolation est maintenue : seuls les scopes company/company_user sont activés, jamais platform_user

---

## ADR-041 : Platform JobDomain Administration

- **Date** : 2026-02-13
- **Contexte** : Les jobdomains étaient définis exclusivement en code (`JobdomainRegistry`). Les platform admins ne pouvaient ni en créer de nouveaux, ni modifier les presets (modules, champs) sans intervention développeur. ADR-040 a introduit les field presets mais ils restaient hardcodés.
- **Décision** : Créer une surface d'administration Platform pour les jobdomains avec CRUD + gestion des presets :
  - **Migration** : colonnes `default_modules` (json) et `default_fields` (json) ajoutées à la table `jobdomains`
  - **Source de vérité runtime** : les presets sont lus depuis la DB (colonnes `default_modules`/`default_fields`), pas depuis le Registry. Le Registry reste la seed initiale (`JobdomainRegistry::sync()` persiste les presets en DB).
  - **CRUD API** : `GET/POST/PUT/DELETE /api/platform/jobdomains` + `GET /api/platform/jobdomains/{id}`, protégé par `platform.permission:manage_jobdomains`
  - **Immutabilité** : le `key` est immutable après création (non accepté en update)
  - **Suppression gardée** : impossible si le jobdomain est assigné à ≥ 1 company (422)
  - **Format default_fields structuré** : `[{code, required, order}, ...]` — chaque preset contient le code du champ, un booléen `required` (required_override lors de l'activation), et un entier `order` (ordre d'affichage). Remplace le format flat `['siret', 'phone']` initial.
  - **Validation default_fields** : les codes doivent exister dans `field_definitions` et ne doivent PAS être de scope `platform_user`
  - **Séparation stricte Presets vs Activations** :
    - Les presets (`default_modules`, `default_fields`) sont des templates — ils ne touchent JAMAIS les activations existantes des companies
    - Les presets sont appliqués uniquement lors de `JobdomainGate::assignToCompany()` (moment de l'assignation)
    - Modifier un preset APRÈS assignation n'a aucun effet rétroactif sur les companies déjà assignées
    - Chaque company prend le contrôle total de ses `FieldActivation` et `CompanyModule` après assignation
  - **Frontend** : deux pages distinctes (pas de drawer) :
    - **Page liste** `platform/jobdomains/index.vue` : VDataTable (Key, Name, Companies, Actions) + bouton "Manage" → profil + VDialog pour création
    - **Page profil** `platform/jobdomains/[id].vue` : Header + 3 tabs (Overview, Default Modules, Default Fields). Tab Fields : groupé par scope (company/company_user), colonnes Enabled/Required/Order par champ, badge `system`. Badges "Preset Only" + alertes de non-rétroactivité.
  - **Permission** : `manage_jobdomains` ajoutée au `PermissionCatalog`
- **Conséquences** :
  - Les platform admins peuvent créer des jobdomains et configurer leurs presets sans code
  - La séparation Definitions → Presets → Activations est formalisée et testée
  - Pas de sync rétroactif — les companies sont autonomes après assignation
  - Le `JobdomainRegistry` reste la seed source mais la DB est l'autorité runtime
  - Le format structuré des presets permet un contrôle granulaire (required/order) par jobdomain
  - Extensible : ajouter un jobdomain = créer via l'UI + configurer modules/champs

---

## ADR-042 : Company Custom Fields gated by JobDomain

- **Date** : 2026-02-13
- **Contexte** : ADR-039 a instauré le Dynamic Field System mais seuls les platform admins peuvent créer des FieldDefinitions. Certains métiers nécessitent que les companies puissent créer leurs propres champs (ex: un transporteur veut un champ "Permis CACES" pour ses membres). Ce droit doit être contrôlé par jobdomain car tous les métiers n'en ont pas besoin.
- **Décision** : Introduire un flag `jobdomains.allow_custom_fields` (boolean, default false) qui gate la capacité d'une company à créer des FieldDefinitions custom. Architecture :
  - **Isolation multi-tenant** : `field_definitions.company_id` (nullable FK → companies, cascadeOnDelete). Platform-owned = `company_id IS NULL`, company-owned = `company_id = current company`. Toute lecture/écriture custom filtre par `company_id` courant.
  - **Unicité des codes** : contrainte composite `UNIQUE(company_id, code)`. Pour les champs platform (`company_id IS NULL`), l'unicité est enforced au niveau applicatif (updateOrCreate dans FieldDefinitionCatalog::sync(), validation `unique` dans PlatformFieldDefinitionController). SQLite et MySQL traitent `NULL != NULL` dans les unique constraints, ce qui requiert cette double protection.
  - **Scopes autorisés custom** : `company` et `company_user` uniquement (jamais `platform_user`). Validé dans CompanyFieldDefinitionController::store().
  - **Immutabilité** : `code`, `scope`, `type` immutables après création (cohérent ADR-039).
  - **Suppression protégée** : un custom field ne peut pas être supprimé s'il a au moins une FieldValue liée (`used_count > 0`). Désactivation (enabled=false) toujours possible. Les valeurs persistent et réapparaissent à la réactivation.
  - **Limite anti-explosion** : max 20 custom fields par company (toutes scopes confondues). 422 si dépassement.
  - **Non-rétroactivité** : changer `allow_custom_fields` sur un jobdomain n'affecte pas les custom fields déjà créés par les companies assignées. Le flag gate uniquement la permission de création/modification/suppression.
  - **API Company** : CRUD via `CompanyFieldDefinitionController` (GET index, POST store, PUT update, DELETE destroy). Routes protégées par `company.role:admin`.
  - **Frontend** : intégré au drawer "Member Fields Settings" de `members.vue`. Section "Create Custom Field" visible uniquement si `allow_custom_fields = true`. Dialog de création avec code, label, scope, type. Aucune nouvelle page — zero code mort.
- **Conséquences** :
  - Les companies autonomes en matière de champs custom, sous contrôle du jobdomain
  - Isolation stricte : aucun accès cross-tenant aux custom fields
  - Le catalogue platform reste séparé et protégé
  - Les custom fields s'intègrent au système existant (activations, values, ReadModels)
  - Extensible sans migration : ajouter un jobdomain avec `allow_custom_fields: true` suffit

---

## ADR-043 : Profile UX Scaling — first/last name, pages profil, drawers commerciaux

- **Date** : 2026-02-13
- **Contexte** : Le système EAV est opérationnel (ADR-039). Les profils sont gérés via drawers (read-only company, edit platform). La table `users` et `platform_users` n'ont qu'un champ `name` monolithique. Les profils manquent de pages dédiées pour l'édition approfondie (base fields + dynamic fields). Les drawers de liste doivent rester légers (quick edit commercial).

- **Décision** :

  ### 1. `display_name` accessor (pas override `name`)

  - **Pourquoi pas `getNameAttribute()` ?** L'override de `name` crée une collision silencieuse avec la colonne DB : `$user->name` retournerait la valeur computée au lieu de la colonne réelle, empêchant toute lecture de la valeur legacy. Un accessor explicite et distinct (`display_name`) est préférable pour la clarté et évite toute magie.
  - Nouvelles colonnes : `first_name`, `last_name` = source de vérité
  - Accessor : `getDisplayNameAttribute()` = `trim($this->first_name . ' ' . $this->last_name)`, ajouté à `$appends`
  - **Règle frontend** : toujours `{{ user.display_name }}`, jamais concat inline `{{ user.first_name + ' ' + user.last_name }}` (future-proof middle name, etc.)

  ### 2. Stratégie transition colonne `name`

  - Colonne `name` rendue nullable, jamais écrite, jamais consommée
  - Absent de `$fillable`, présent dans `$guarded` → mass assignment impossible
  - **Deprecation timeline** : colonne `name` sera supprimée en v2 (migration destructive après validation que zéro code la consomme)
  - CI grep guard automatisé : `test_frontend_does_not_use_user_name_property`

  ### 3. UX : liste + drawer commercial + page profil

  | Surface | Usage | Composant |
  |---|---|---|
  | Company members list | VDataTableServer | N/A |
  | Company member drawer | Quick edit commercial (admin) ou read-only (user) | `MemberProfileForm.vue` |
  | Company member page `/company/members/[id]` | Deep edit (base + dynamic + role) | `MemberProfileForm.vue` |
  | Platform users list | VDataTable | N/A |
  | Platform user page `/platform/users/[id]` | Deep edit (overview + custom fields + credentials) | Direct (tabs) |
  | Platform user drawer | Create only (plus d'edit) | Direct (form) |
  | Account settings | Self-edit (first/last + dynamic, email readonly) | Direct (form) |

  ### 4. `MemberProfileForm.vue` — composant partagé

  - Props : `member`, `baseFields`, `dynamicFields`, `editable`, `loading`, `roleOptions`
  - Emit : `save(payload)` avec `{ first_name, last_name, role?, dynamic_fields }`
  - **Règle stricte** : toute logique formulaire dans ce composant. Ni `[id].vue` ni `index.vue` ne contiennent de logique formulaire inline.

  ### 5. `MembershipController::update()` — 3 blocs distincts

  - **Bloc A** : base fields (`first_name`, `last_name`) → `$membership->user->update()`
  - **Bloc B** : dynamic fields → `FieldWriteService::upsert()`
  - **Bloc C** : role → `$membership->update(['role' => ...])` avec guard owner
  - 3 blocs séparés, testés unitairement séparément. Pas de monstre.

  ### 6. Credential guards (platform)

  - Super admin credentials **ne peuvent pas** être modifiés via la page profil (backend guard 403)
  - User **ne peut pas** modifier ses propres credentials via le endpoint admin (backend guard 403)
  - Permission `manage_platform_user_credentials` requise (middleware existant)
  - Guards enforced backend ET frontend (double protection)

  ### 7. Routes REST — zéro multiplication

  - Company : `GET/PUT /company/members/{id}` enrichis (pas de `/profile`)
  - Platform : `GET/PUT /platform-users/{id}` déjà complets
  - Account : `GET/PUT /profile` existant, email rendu optionnel
  - Zéro endpoint supplémentaire créé

  ### 8. Performance invariant

  - `test_member_profile_query_count_is_constant` vérifie via `DB::enableQueryLog()` que le ReadModel ne génère pas de N+1 (≤ 15 queries)

- **Conséquences** :
  - L'identité utilisateur est atomique (`first_name` + `last_name`), pas monolithique (`name`)
  - La colonne `name` est techniquement obsolète, protégée par guards et tests
  - Les profils sont éditables à deux niveaux (drawer quick edit + page deep edit)
  - L'EAV est accessible de façon ergonomique sur toutes les surfaces profil
  - Les controllers restent passifs (ReadModels + FieldWriteService)
  - Les credentials sont protégées par des guards backend stricts

---

## ADR-044 : UX Alignment Platform vs Company — Widgets & Profiles

- **Date** : 2026-02-13
- **Contexte** : Après LOT-PROFILES-UX-SCALE (ADR-043), deux incohérences UX :
  1. Le profil Platform User avait des VTabs (Overview/Custom Fields/Credentials) mais le profil Company Member n'avait qu'un formulaire direct sans onglets
  2. Les widgets globaux (Search, I18n, Theme, Shortcuts, Notifications, Customizer) étaient dans le layout Company mais absents du layout Platform — inversant la hiérarchie SaaS
- **Décision** :
  - **Profils unifiés** : Company Member `[id].vue` restructuré avec VTabs identiques au Platform User (Overview, Custom Fields conditionnel, Credentials conditionnel)
  - **Credential management symétrique** : `MemberCredentialController` avec les mêmes guards que Platform (owner protégé = super_admin protégé, self interdit). Broker `users` pour company, `platform_users` pour platform.
  - **Widget migration** : Extraction `NavbarGlobalWidgets.vue` (I18n, ThemeSwitcher, Shortcuts, Notifications). Ajouté au layout Platform avec NavSearchBar et TheCustomizer. Retiré des layouts Company (vertical + horizontal).
  - **NavbarShortcuts** : Routes Vuexy demo remplacées par routes platform réelles (Dashboard, Users, Companies, Job Domains, Custom Fields, Roles)
  - **Hiérarchie claire** : Platform = SaaS admin (full control, all global widgets), Company = Client business (minimal, UserProfile only)
- **Conséquences** :
  - Les profils ont une structure identique sur les deux scopes (VTabs, mêmes patterns)
  - Les company layouts ne portent plus de widgets globaux — navbar = IconBtn + VSpacer + UserProfile
  - Le layout Platform concentre tous les outils SaaS admin
  - 3 fichiers créés (`MemberCredentialController`, `NavbarGlobalWidgets`, `CompanyMemberCredentialTest`), 8 modifiés
  - Zéro modification model, zéro migration, zéro modification @core

---

## ADR-045a : Auto-import Governance — scopes explicites

- **Date** : 2026-02-14
- **Contexte** : `unplugin-vue-components` scanne 3 répertoires seulement (`@core/components`, `views/demos`, `components`). Les composants de `layouts/components/` (12 fichiers), `company/components/` (1), `core/components/` (1) ne sont PAS auto-importés. Aucune documentation. Incident AppShellGate (import manquant silencieux).
- **Décision** : Formaliser les deux régimes d'import :
  - **Auto-importés** (unplugin-vue-components) : `@core/components/`, `views/demos/`, `components/`
  - **Import explicite obligatoire** : `layouts/components/`, `company/components/`, `core/components/`
  - Rationale : les composants structurels (layouts) et les composants scopés (company, core) doivent déclarer explicitement leurs dépendances pour éviter les erreurs silencieuses
  - Un script CI `pnpm check:imports` vérifie que tout usage d'un composant à import explicite est accompagné d'un import dans le même fichier
- **Conséquences** : Règle ajoutée dans `07-dev-rules.md`. Script `scripts/check-explicit-imports.sh` ajouté. Tout composant hors des 3 dirs auto-import nécessite un import explicite.

## ADR-045b : Router Base — assets base vs app base

- **Date** : 2026-02-14
- **Contexte** : `laravel-vite-plugin` set `base: '/build/'` au build. `import.meta.env.BASE_URL` vaut `/build/` en prod. `createWebHistory(import.meta.env.BASE_URL)` crée le routeur avec base `/build/` — c'est faux. `/build/` = base des assets Vite (JS, CSS, images), PAS base du routeur SPA.
- **Décision** :
  - **Assets base** = `/build/` (géré par laravel-vite-plugin, ne pas toucher)
  - **App base (routeur)** = `/` (sauf sous-path explicite)
  - **Interdit** d'utiliser `import.meta.env.BASE_URL` pour `createWebHistory()` dans un projet laravel-vite-plugin — cette variable contient la base assets, pas la base app
  - Le catch-all Laravel (`routes/web.php`) sert le shell SPA à toutes les URLs non-API
- **Conséquences** : Fix 1 ligne dans `resources/js/plugins/1.router/index.js`. Aucun impact sur le build ou les assets.

## ADR-045c : Version Discipline — dual env vars (frontend + backend)

- **Date** : 2026-02-14
- **Contexte** : `cache.js:10` lit `VITE_APP_VERSION` mais la variable n'existe nulle part. `CACHE_VERSION` vaut toujours `'__dev__'`. Le runtime cache ne s'invalide jamais par version.
- **Décision** : Séparer frontend et backend versioning :
  - `VITE_APP_VERSION` = version frontend (injecté à build-time par Vite, lu par `cache.js`)
  - `APP_BUILD_VERSION` = version backend (lu par Laravel à runtime, exposé via middleware X-Build-Version)
  - Même valeur (git short hash), injectée par le deploy script : `$(git rev-parse --short HEAD)`
  - `VITE_APP_VERSION` est build-time (figé dans le bundle JS)
  - `APP_BUILD_VERSION` est runtime (lu par `config/app.php`)
- **Conséquences** : `.env.production.example` mis à jour. `config/app.php` expose `build_version`. Le deploy script doit injecter les deux variables.

## ADR-045d : Chunk Resilience — auto-reload on stale chunks

- **Date** : 2026-02-14
- **Contexte** : Après un deploy, les anciens chunks n'existent plus. `import()` échoue → page blanche. Aucun handler.
- **Décision** : Intercepter les erreurs de chunk via 2 canaux (`vite:preloadError` + `unhandledrejection` ChunkLoadError) avec logique anti-boucle :
  - 1er échec : reload silencieux (sessionStorage timestamp)
  - 2ème échec dans les 10s : overlay utilisateur "Application mise à jour — Veuillez rafraîchir"
  - Code placé dans `main.js` avant `createApp`
- **Conséquences** : ~25 lignes dans `main.js`. Réversible en supprimant le bloc. Aucun impact sur le build.

## ADR-045e : Version Handshake — passive header detection

- **Date** : 2026-02-14
- **Contexte** : Le frontend ne détecte pas un deploy backend mid-session. Risque de mismatch frontend/backend silencieux.
- **Décision** : Header HTTP passif (zero polling) :
  - Middleware `AddBuildVersion` ajoute `X-Build-Version` à toutes les réponses API
  - Les intercepteurs `onResponse` dans `api.js` et `platformApi.js` comparent la version serveur avec `VITE_APP_VERSION`
  - En cas de mismatch : flag `sessionStorage.lzr:version-mismatch`
  - Le router guard détecte le flag et reload la page à la prochaine navigation
- **Conséquences** : Le frontend se met à jour automatiquement après un deploy backend, sans polling. Réversible en supprimant le middleware + hooks.

---

## ADR-046 : Enterprise Runtime Hardening (Backlog)

- **Date** : 2026-02-14
- **Statut** : **Backlog** — documenté, NON implémenté
- **Priorité** : Low
- **Risque** : Faible
- **Impact** : Observabilité / Robustesse production
- **Prérequis** : ADR-045 (Production Hardening) implémenté

- **Contexte** : Le runtime SPA est désormais robuste (versioning ADR-045c, handshake ADR-045e, chunk resilience ADR-045d). Il manque 4 briques d'observabilité et de discipline production pour atteindre un niveau enterprise-grade. Ce lot est optionnel, non urgent, mais stratégique pour le passage à l'échelle.

- **Décision** : Créer un LOT F composé de 4 sous-lots indépendants, chacun déployable et réversible séparément :

  ### F1 — API Cache Discipline

  **Problème** : Les endpoints `/api/*` ne forcent pas explicitement la non-mise en cache côté navigateur/proxy. Un proxy ou CDN mal configuré pourrait cacher des réponses API authentifiées.

  **Solution future** :
  - Middleware `ApiNoCacheHeaders` ajoutant `Cache-Control: no-store, no-cache, must-revalidate, max-age=0` sur toutes les routes API
  - Enregistrement dans le groupe middleware `api` (comme `AddBuildVersion`)

  **Impact** : Zéro impact métier. Protection contre proxy/CDN mal configuré.

  **Fichiers estimés** :
  - `app/Http/Middleware/ApiNoCacheHeaders.php` (nouveau, ~10 lignes)
  - `bootstrap/app.php` (1 ligne — appendToGroup)

  ---

  ### F2 — Global Error Monitoring

  **Problème** : Aucune centralisation des erreurs runtime JS. Les erreurs silencieuses (chunk failures, API errors, mismatch) ne sont pas détectées côté serveur.

  **Solution future** :
  - Hooks globaux `window.onerror` + `window.addEventListener('unhandledrejection')` (complémentaires au handler chunk ADR-045d)
  - Envoi d'un payload minimal vers `POST /api/runtime-error` (endpoint dédié, non authentifié, rate-limited)
  - Payload : `{ build_version, route, message, stack?, user_agent, timestamp }`
  - Backend : log structuré (`Log::warning`) — pas de table dédiée au départ
  - Rate limiting strict : `throttle:10,1` sur l'endpoint

  **Impact** : Détection proactive des erreurs chunk, API, mismatch. Traçabilité des incidents runtime.

  **Fichiers estimés** :
  - `resources/js/utils/errorReporter.js` (nouveau, ~30 lignes)
  - `resources/js/main.js` (import + init)
  - `app/Http/Controllers/RuntimeErrorController.php` (nouveau, ~20 lignes)
  - `routes/api.php` (1 route)

  ---

  ### F3 — Chunk Failure Logging

  **Problème** : Le handler chunk (ADR-045d) reload silencieusement ou affiche un overlay, mais ne trace pas l'événement. Impossible de savoir si un deploy a causé des chunk failures massifs.

  **Solution future** :
  - Avant le reload (dans `handleChunkError`), envoyer un beacon vers `POST /api/runtime-event`
  - Payload : `{ type: 'chunk_failure', build_version, asset_url?, route, timestamp }`
  - Utiliser `navigator.sendBeacon()` (fire-and-forget, survit au reload)
  - Backend : log structuré — pas de table dédiée
  - Dépend de F2 pour l'endpoint (ou endpoint séparé si F2 non implémenté)

  **Impact** : Traçabilité des déploiements problématiques. Dashboarding futur possible.

  **Fichiers estimés** :
  - `resources/js/main.js` (ajout `sendBeacon` dans `handleChunkError`)
  - `app/Http/Controllers/RuntimeEventController.php` (nouveau si séparé de F2)
  - `routes/api.php` (1 route si séparé)

  ---

  ### F4 — Health Endpoint

  **Problème** : Aucun endpoint de health check pour le monitoring externe, la vérification CI/CD, ou le debug mismatch en production.

  **Solution future** :
  - `GET /health` (hors groupe `api`, pas d'auth) retournant :
    ```json
    {
      "status": "ok",
      "build_version": "abc1234",
      "environment": "production",
      "timestamp": "2026-02-14T12:00:00Z"
    }
    ```
  - Laravel fournit déjà `/up` (configuré dans `bootstrap/app.php`) mais il ne retourne que HTTP 200 sans metadata
  - Le health endpoint enrichi expose `config('app.build_version')` et `config('app.env')`
  - Pas de check DB/Redis dans la réponse (simplicité, vitesse) — extensible plus tard

  **Usages** :
  - Monitoring externe (UptimeRobot, Pingdom)
  - Vérification post-deploy dans le deploy script
  - Debug mismatch : comparer `X-Build-Version` header vs `/health` response
  - CI/CD : smoke test après deploy

  **Fichiers estimés** :
  - `routes/web.php` (1 route) ou controller dédié
  - Optionnel : `app/Http/Controllers/HealthController.php` (~10 lignes)

- **Conséquences** :
  - Chaque sous-lot (F1-F4) est indépendant et déployable séparément
  - F2 et F3 partagent un pattern commun (endpoint runtime events) — à factoriser si les deux sont implémentés
  - Aucun sous-lot ne modifie le comportement métier existant
  - Aucun sous-lot ne nécessite de migration DB
  - L'ordre d'implémentation recommandé : F4 (le plus simple) → F1 → F3 → F2 (le plus complexe)
  - Ce lot ne sera implémenté que lorsqu'un besoin concret d'observabilité se manifestera (incident en prod, scaling, onboarding monitoring)

---

## ADR-047a : Runtime State Machine + Event Journal

- **Date** : 2026-02-14
- **Contexte** : Les transitions de phase du runtime SPA étaient des assignments directs (`this._phase = 'auth'`) sans validation. Aucun journal d'événements pour le debug.
- **Décision** :
  - `stateMachine.js` — table de transitions autorisées (`cold→auth`, `auth→tenant`, etc.). Toute transition invalide throw en DEV, log + no-op en PROD.
  - `journal.js` — ring buffer (200 entrées) horodaté. Types : `phase:transition`, `run:teardown`, `broadcast:in`, etc.
  - `runtime.js` — toutes les mutations de `_phase` passent par `_transition(target)` qui valide via la state machine et log dans le journal.
  - `_broadcastLog` (ancien) remplacé par le journal.
  - `RuntimeDebugPanel.vue` — section "Event Journal" avec filtre par type.
- **Conséquences** :
  - Zero changement de comportement — même séquence de boot, même API publique
  - Les transitions invalides sont détectées immédiatement en dev
  - Le journal centralise tous les événements runtime pour le debug
  - Foundation pour les LOTs G2-G4 (job system, scheduler, recovery UX)

## ADR-047b : Job System + Per-job Abort

- **Date** : 2026-02-14
- **Contexte** : Le runtime utilisait un signal AbortController global (`setActiveGroup`/`getActiveSignal`) partagé entre toutes les requêtes d'un batch. Impossible d'annuler un job individuel. `_loadResource`/`_resolveResources` mélangeaient orchestration et exécution.
- **Décision** :
  - `job.js` — Classe `Job` (1 AbortController par ressource) + `JobRunner` (exécution parallèle avec dépendances).
  - Chaque store action runtime accepte `{ signal }` (optionnel) et le forward à `$api`/`$platformApi`.
  - `abortRegistry.js` simplifié : `setActiveGroup`/`getActiveSignal` supprimés. `abortAll` gardé comme filet de sécurité.
  - `api.js`/`platformApi.js` : suppression de la lecture de `getActiveSignal()` dans `onRequest`.
  - `runtime.js` : `_loadResource`/`_resolveResources`/`_backgroundRefresh` remplacés par `_runJobs` qui crée un `JobRunner`.
  - Getter `progress` exposé (delegue au JobRunner actif).
- **Conséquences** :
  - Chaque ressource est cancellable individuellement
  - Le signal est explicite (passé par la chaîne store → $api) au lieu de global mutable
  - `JobRunner.retryFailed()` permet le retry partiel (foundation pour G4 recovery UX)
  - Le cache SWR est préservé (logique déplacée dans Job.run)

## ADR-047c : Run Scheduler (Single-Writer Orchestrator)

- **Date** : 2026-02-14
- **Contexte** : Le runtime utilisait `_bootId` (compteur générationnel) pour détecter les boots concurrents après chaque await, mais entre deux awaits l'état pouvait être corrompu. Les méthodes boot/switchCompany/teardown contenaient toute la logique d'orchestration mélangée à la gestion d'état réactif du store Pinia.
- **Décision** :
  - `scheduler.js` — Factory `createScheduler(deps)` : orchestrateur central avec garantie single-writer.
  - Un seul run actif à la fois (`_currentRunId` + `_isStale()`). Tout nouveau `requestBoot` annule le précédent.
  - Promise-based coordination : `whenAuthResolved()` résout après la phase auth, `whenReady(timeout)` résout quand phase === ready.
  - `requestSwitch(companyId)` : annule tenant/features, relance sans toucher auth.
  - `retryFailed()` : relance uniquement les jobs en erreur du run actif, transitions adaptées.
  - `runtime.js` refactoré en façade Pinia : les actions délèguent au scheduler, les getters exposent l'état réactif.
  - Le scheduler mute l'état du store via des callbacks injectées (`deps.transition`, `deps.setScope`, `deps.setError`, etc.).
  - Suppression de `_bootId` du state.
- **Conséquences** :
  - Single-writer : impossible que deux runs concurrents écrivent `_phase` simultanément
  - Le store runtime est une façade mince (~300 lignes vs ~450 avant)
  - Foundation pour G4 : `whenAuthResolved()` permet le guard non-bloquant
  - Le scheduler est testable indépendamment (pure function factory, pas de couplage Pinia)

## ADR-047d : Non-blocking Guard + Recovery UX

- **Date** : 2026-02-14
- **Contexte** : Le guard `beforeEach` bloquait la navigation avec `await runtime.boot(scope)` — attendant auth+tenant+features (~3 API calls). Sur réseau lent, l'utilisateur voyait un écran blanc pendant 2-3s. AppShellGate ne proposait qu'un retry complet (teardown + full reboot) même si un seul job avait échoué.
- **Décision** :
  - `guards.js` : `runtime.boot(scope)` fire-and-forget + `await runtime.whenAuthResolved()` — le guard ne bloque que sur auth.
  - Routes `meta.module` : `await runtime.whenReady(5000)` avant le check module (seul cas qui nécessite la phase features).
  - Le cas `else if (!runtime.isReady)` remplacé par `else if (runtime.phase === 'error')` — les phases intermédiaires (tenant/features) ne provoquent plus de reboot.
  - `AppShellGate.vue` : VProgressLinear déterministe (done/total), timeout 8s avec message + retry, error avec retry partiel (`retryFailed()`) + retry complet.
  - Dev mode : affiche resource status en error, phase/scope/progress en booting.
- **Conséquences** :
  - Navigation instantanée après auth — tenant/features se chargent en arrière-plan
  - AppShellGate montre une barre de progression déterministe au lieu d'un spinner indéterminé
  - Retry partiel : seuls les jobs échoués sont relancés (pas de full teardown)
  - Timeout visible après 8s avec option de retry

## ADR-048 : Runtime v2 Hardening — Invariants + Stress Harness + Observability

- **Date** : 2026-02-14
- **Contexte** : Après l'audit critique de G1-G4 et les fixes F1-F5, le runtime v2 fonctionne correctement. Mais il manque des garanties formelles, un moyen de reproduire les scénarios de stress de manière déterministe, et une observabilité suffisante pour le debug en production.
- **Décision** :
  - **H1 — Invariants formels** : 14 invariants DEV-only (I1-I14) vérifiés aux points critiques du scheduler via `assertRuntime(snapshot, context)`. Métadonnées de run (`_runMeta`: requiredPhases, executedPhases) pour garantir que toutes les phases requises sont exécutées avant ready. Zero impact PROD (tree-shaken par `import.meta.env.DEV`).
  - **H2 — Stress harness** : Module `devtools/runtimeStress.js` avec 5 scénarios (S1: 50 navigations rapides, S2: switches concurrents, S3: offline → error, S4: retryFailed → convergence, S5: teardown mid-boot). Fault injection via monkey-patching des store actions. Rapport JSON. Accessible via `window.__runtimeStress()` et bouton RuntimeDebugPanel.
  - **H3 — Observabilité** : `runtime.getSnapshot()` retourne l'état complet JSON-sérialisable. `journal.toJSON()` pour export. RuntimeDebugPanel enrichi (copy snapshot, export JSON). AppShellGate DEV overlay enrichi (runId, last 3 events).
- **Conséquences** :
  - Les invariants attrapent les régressions immédiatement en DEV (throw + journal log)
  - Le stress harness permet de reproduire et vérifier les scénarios adverses sans backend
  - L'observabilité permet de capturer l'état exact du runtime pour les bug reports
  - Aucune nouvelle dépendance NPM, zero impact bundle PROD
  - Fichiers ajoutés : `invariants.js`, `devtools/runtimeStress.js`
  - Fichiers modifiés : `scheduler.js`, `runtime.js`, `journal.js`, `RuntimeDebugPanel.vue`, `AppShellGate.vue`, `App.vue`

## ADR-049 : Company RBAC — Permissions modulaires par rôle

- **Date** : 2026-02-14
- **Contexte** : Le système actuel utilise un enum `role` (owner/admin/user) sur `memberships` pour contrôler l'accès aux routes company. Ce modèle est trop grossier : un admin a accès à tout, un user à rien. Pour être vendable, Leezr a besoin d'un RBAC fin par module — chaque module déclare ses permissions, chaque rôle est une collection de permissions, chaque company peut personnaliser ses rôles.
- **Décision** :

  **Modèle de données (3 nouvelles tables + 1 modifiée)** :
  - `company_permissions` : `id`, `key` (unique varchar 50), `label` (varchar 100), `module_key` (varchar 50, indexé). Catalogue global, synchronisé depuis `ModuleRegistry`.
  - `company_roles` : `id`, `company_id` (FK), `key` (varchar 50), `name` (varchar 100), `is_system` (bool, défaut false), `timestamps`. Unique sur `[company_id, key]`. Rôles par company.
  - `company_role_permission` : `company_role_id` (FK), `company_permission_id` (FK). Unique sur la paire.
  - `memberships` : ajout `company_role_id` (FK nullable vers `company_roles`).

  **Principes** :
  1. Owner est structurel (`memberships.role = 'owner'`), PAS un rôle RBAC. Owner bypass toutes les permissions.
  2. 1 member = 1 rôle (`company_role_id` FK, pas M:N).
  3. Permissions = catalogue global, synchronisé depuis `ModuleRegistry` via `CompanyPermissionCatalog::sync()`.
  4. Rôles = par company. Le jobdomain seed des rôles par défaut (`is_system = true`) à l'assignation.
  5. Module désactivé → ses permissions sont inactives mais préservées dans les rôles.
  6. Middleware `company.permission:{key}` remplace `company.role:admin` sur les routes.
  7. Pas d'héritage, pas de hiérarchie, pas de row-level security, pas de field-level permissions.

  **10 permissions initiales (3 modules)** :
  - `core.members` : `members.view`, `members.manage`, `members.credentials`
  - `core.settings` : `settings.view`, `settings.manage`
  - `logistics_shipments` : `shipments.view`, `shipments.create`, `shipments.manage_status`, `shipments.manage_fields`, `shipments.delete`

  **Rôles par défaut (jobdomain logistique)** :
  - `admin` (système) : toutes les 10 permissions
  - `dispatcher` (système) : view members, view settings, toutes shipments sauf delete
  - `viewer` (système) : view members, view settings, view shipments

  **Implémentation** : 4 LOTs (R1: tables+catalogue+seed, R2: middleware+routes, R3: frontend, R4: advanced view+hardening).

- **Conséquences** :
  - L'enum `role` sur memberships est conservé pour la compatibilité owner/admin/user, mais `company_role_id` devient la source d'autorisation pour non-owners
  - Chaque module peut déclarer des permissions dans sa définition `ModuleRegistry`, synchronisées automatiquement
  - Les companies peuvent créer des rôles custom ou modifier les rôles système seedés par le jobdomain
  - Le middleware `company.permission` permet un contrôle fin route par route
  - Pattern identique au Platform RBAC (PlatformRole → PlatformPermission, super_admin bypass)

## ADR-050 : RBAC Hardening — Séparation admin / opérationnel

- **Date** : 2026-02-16
- **Contexte** : Avec ADR-049, tout rôle peut recevoir n'importe quelle permission via `permissions()->sync()`. Rien n'empêche techniquement un rôle opérationnel (driver, viewer) de recevoir des permissions de gouvernance (settings.manage, members.manage). L'UI ne suffit pas — un appel API direct pourrait contourner la contrainte.
- **Décision** :
  - `company_permissions.is_admin` (bool, default false) — marque les permissions de gouvernance. Déclaré dans `ModuleRegistry` par chaque permission, synchronisé via `CompanyPermissionCatalog::sync()`.
  - `company_roles.is_administrative` (bool, default false) — marque les rôles autorisés à recevoir des permissions admin.
  - `CompanyRole::syncPermissionsSafe(array $ids)` — valide structurellement : si un rôle `is_administrative = false` reçoit une permission `is_admin = true`, throw `ValidationException`. Pas de fallback silencieux.
  - `permissions()->sync()` direct reste disponible (pour les migrations, les tests) mais `syncPermissionsSafe()` est le point d'entrée pour tout code applicatif (JobdomainGate, futur API de gestion des rôles).
  - 5 permissions admin : `members.manage`, `members.credentials`, `settings.manage`, `shipments.manage_fields`, `shipments.delete`.
  - 5 permissions opérationnelles : `members.view`, `settings.view`, `shipments.view`, `shipments.create`, `shipments.manage_status`.
  - Le rôle `admin` (jobdomain logistique) est `is_administrative = true`. Les rôles `dispatcher` et `viewer` restent `false`.
  - Owner bypass inchangé (structurel, pas RBAC).
  - Aucune hiérarchie, aucun héritage. Séparation binaire explicite.
- **Conséquences** :
  - Impossible techniquement d'attribuer des permissions de gouvernance à un rôle opérationnel, même via API directe
  - Le dispatcher perd `shipments.manage_fields` (permission admin reclassifiée)
  - Tout nouveau module doit marquer ses permissions `is_admin` dans `ModuleRegistry`
  - La validation est au niveau modèle, pas middleware — le middleware reste inchangé

## ADR-051 : Frontend RBAC — Permission-based UI gating

- **Date** : 2026-02-16
- **Contexte** : Le backend RBAC company est complet (R1/R2/R2.5) avec 11 permissions, rôles per-company, owner bypass structurel, et middleware `company.permission:{key}` sur 15 routes. Le frontend utilisait des checks hardcodés `role === 'owner' || role === 'admin'` et n'avait pas de page de gestion des rôles.
- **Décision** :
  - **Frontend RBAC** : `auth.hasPermission(key)` avec owner bypass, pas de CASL. Getters `isOwner` et `permissions` dans le auth store, dérivés de `company_role.permissions` dans `/api/my-companies`.
  - **Navigation** : double filtre `permission` + `ownerOnly` dans `DefaultLayoutWithVerticalNav.vue`. Les navItems statiques et module portent un champ `permission`. Les headings orphelins sont auto-retirés.
  - **Structure owner-only** : modules, industry, roles = `ownerOnly: true`, pas permission-based. La distinction est structurelle (qui façonne la company) vs opérationnelle (qui travaille dedans).
  - **`company_role_id` nullable** remplace l'enum `role` dans les requêtes member (`StoreMemberRequest`, `UpdateMemberRequest`). Un membre peut être invité sans rôle, assignable ensuite.
  - **`membership.role`** = seulement pour owner bypass structurel, jamais dérivé de `is_administrative`. Valeur 'user' pour tout non-owner.
  - **Nouvelle permission `members.invite`** (opérationnelle, `is_admin: false`) pour POST /company/members — un dispatcher peut inviter, seuls les admins peuvent manage/delete/credentials.
  - **Roles CRUD** : `CompanyRoleController` (5 routes owner-only via `company.role:owner`), page `/company/roles` avec liste + drawer, permissions taggées admin/standard.
  - **Pages migrées** : 7 pages passent de `role === 'owner' || role === 'admin'` à `auth.hasPermission(key)` ou `auth.isOwner`.
- **Conséquences** :
  - Un viewer (members.view + settings.view + shipments.view) voit les données en lecture seule, sans boutons d'action
  - Un dispatcher (+ members.invite + shipments.create/manage_status) peut créer des shipments et inviter des membres
  - Seul le owner voit Modules/Industry/Roles dans la nav et peut gérer la structure
  - Les rôles sont per-company, éditables par le owner, avec séparation admin/opérationnel enforced par `syncPermissionsSafe()`
  - `AddMemberDrawer` et `MemberProfileForm` utilisent un select des rôles company au lieu du hardcode admin/user

---

## ADR-052 : Company Roles UX + Platform Jobdomain Role Presets

- **Date** : 2026-02-16
- **Contexte** : La page Company Roles (ADR-051) exposait les permissions brutes inline dans le tableau (chips avec clés techniques). L'UX ressemblait à un panneau Laravel, pas à un produit. De plus, les presets de rôles par jobdomain (admin, dispatcher, viewer) étaient hardcodés dans `JobdomainRegistry` — aucune UI platform pour les éditer.
- **Décision** :
  - **Company Roles UX** : le tableau affiche seulement Name/Type(Manager|Standard)/Members/Actions. Les permissions sont visibles **uniquement dans le drawer**, groupées par module (`module_key` → `module_name` via ModuleRegistry). Les permissions admin sont **invisibles** (pas grisées) pour les rôles non-administratifs. Le label "Admin" change en "Manager".
  - **Permission grouping** : le backend enrichit `permissionCatalog` avec `module_name` (lookup ModuleRegistry). Le frontend groupe via `computed permissionGroups`, pattern VTable avec checkboxes (miroir Vuexy `AddEditRoleDialog.vue`).
  - **Platform Jobdomain Role Presets** : colonne `default_roles` JSON ajoutée à la table `jobdomains`. `JobdomainRegistry::sync()` persiste les presets en DB. `JobdomainGate::assignToCompany()` lit depuis le modèle DB (plus depuis le Registry). Le platform admin édite via un 4ème tab "Default Roles" sur `/platform/jobdomains/{id}`.
  - **Format `default_roles`** : objet associatif `{ key: { name, is_administrative, permissions: [keys] } }`. Les permissions sont stockées comme clés strings (pas IDs) car ce sont des presets — les IDs sont résolus au moment du clonage.
  - **Validation backend** : `PlatformJobdomainController::update()` valide la structure et vérifie que les clés de permission existent dans le `CompanyPermissionCatalog`.
- **Conséquences** :
  - La page Roles company est lisible par un non-technique — permissions groupées par module avec labels métier
  - Le platform admin peut customiser les rôles presets par jobdomain sans toucher au code
  - Les companies existantes ne sont jamais impactées par un changement de preset
  - `default_roles` en DB = source de vérité pour le clonage, Registry = seed initial

## ADR-053 : Module-Aware RBAC UI

- **Date** : 2026-02-16
- **Contexte** : Les permissions de modules désactivés (par Platform ou par la Company) continuaient d'apparaître dans le drawer de `/company/roles`. Un module désactivé ne doit pas proposer ses permissions dans la configuration des rôles — incohérence UX. La sécurité backend est déjà correcte (ModuleGate bloque l'accès aux routes).
- **Décision** :
  - Le backend enrichit `permissionCatalog` avec `module_active` (bool) pour chaque permission, calculé via `ModuleGate::isActive($company, $moduleKey)`. Les modules `core.*` sont toujours actifs.
  - Le frontend filtre `if (!p.module_active) continue` dans le computed `permissionGroups` — les permissions de modules inactifs sont **invisibles** (pas grisées, pas mentionnées).
  - Aucune suppression en base : les permissions attribuées à un rôle restent intactes. Seule l'UI est filtrée.
  - Quand un module est réactivé, ses permissions réapparaissent automatiquement dans le drawer.
- **Conséquences** :
  - La page Roles ne montre que les capacités réellement disponibles pour la company
  - Aucun cleanup destructif — les rôles existants conservent leurs permissions même si un module est temporairement désactivé
  - Aucun impact sur middleware, RBAC tables, ModuleRegistry, ou JobdomainRegistry
  - Décision UX confirmée : invisible > grisé (cohérent avec ADR-052 pour les permissions admin)

---

> Pour ajouter une décision : copier le template ci-dessus, incrémenter le numéro.
