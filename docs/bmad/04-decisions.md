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

## ADR-018 : Déploiement — VPS OVH unique, webhook GitHub *(supersédé par ADR-063)*

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

## ADR-036 : Deployment Discipline — seeders et migrations *(pipeline supersédé par ADR-063, règles migration toujours en vigueur)*

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

## ADR-046 : Enterprise Runtime Hardening — Implémenté

- **Date** : 2026-02-14 (documenté) → 2026-02-22 (implémenté)
- **Statut** : **Implémenté** — les 4 sous-lots F1–F4 sont en production
- **Impact** : Observabilité / Robustesse production
- **Prérequis** : ADR-045 (Production Hardening) implémenté

- **Contexte** : Le runtime SPA est robuste (versioning ADR-045c, handshake ADR-045e, chunk resilience ADR-045d). Suite à des instabilités post-login (overlay freeze), les 4 briques d'observabilité et de discipline production sont désormais nécessaires.

- **Décision** : LOT F — 4 sous-lots implémentés simultanément :

  ### F1 — API Cache Discipline ✅
  - `NoCacheHeaders` middleware : `Cache-Control: no-store, no-cache, must-revalidate, max-age=0` + `Pragma: no-cache` + `Expires: 0`
  - Enregistré dans le groupe `api` via `bootstrap/app.php` (s'applique à toutes les routes `/api/*`, `/api/platform/*`)
  - Également appliqué au `/health` endpoint via middleware direct
  - **Fichiers** : `app/Http/Middleware/NoCacheHeaders.php` (CREATE), `bootstrap/app.php` (MODIFY)

  ### F2 — Global Error Monitoring ✅
  - Frontend : `errorReporter.js` — listeners `window.error` + `unhandledrejection` → `sendBeacon` vers `/api/runtime-error`
  - Payload : `{ type, message, stack, url, user_agent, timestamp, build_version }`
  - Chunk errors exclus (gérés par F3)
  - Backend : `RuntimeErrorController` — valide le payload, log vers canal `runtime` (`storage/logs/runtime.log`)
  - Canal `runtime` ajouté à `config/logging.php` (single file, séparé de laravel.log)
  - Route `POST /api/runtime-error` avec `throttle:10,1`
  - **Fichiers** : `resources/js/core/runtime/errorReporter.js` (CREATE), `app/Http/Controllers/RuntimeErrorController.php` (CREATE), `config/logging.php` (MODIFY), `routes/api.php` (MODIFY), `resources/js/main.js` (MODIFY)

  ### F3 — Chunk Failure Logging ✅
  - `reportChunkFailure()` dans `main.js` : `sendBeacon` fire-and-forget vers `/api/runtime-error` AVANT tout reload
  - `handleChunkError()` enrichi : reçoit le message d'erreur, log avant reload ou overlay
  - `router.onError` dans `plugins/1.router/index.js` : attrape les chunk failures pendant la navigation, log + reload
  - Réutilise l'endpoint F2 (`/api/runtime-error`) avec `type: 'chunk_load_failure'`
  - **Fichiers** : `resources/js/main.js` (MODIFY), `resources/js/plugins/1.router/index.js` (MODIFY)

  ### F4 — Health Endpoint ✅
  - `GET /health` — public, sans auth, avec `NoCacheHeaders` middleware
  - Retourne : `{ status, app_version, build_version, commit_hash, timestamp }`
  - Lit `config('app.version')`, `config('app.build_version')`, `config('app.commit_hash')` (déjà configurés)
  - Route catch-all SPA mise à jour pour exclure `/health`
  - **Fichiers** : `app/Http/Controllers/HealthController.php` (CREATE), `routes/web.php` (MODIFY)

- **Conséquences** :
  - Les réponses API ne sont jamais cachées par un proxy/CDN/navigateur
  - Les erreurs runtime JS sont centralisées dans `storage/logs/runtime.log` (séparé de l'app log)
  - Les chunk failures sont tracés AVANT le reload — visibilité sur les déploiements problématiques
  - Le `/health` endpoint expose les metadata build pour monitoring externe, CI/CD smoke test, debug mismatch
  - Aucun changement de comportement métier — observabilité pure

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

## ADR-054 : Capability Abstraction Layer (Mode Simple / Mode Avancé)

- **Date** : 2026-02-16
- **Contexte** : La page Company Roles expose des permissions techniques (clés individuelles comme `members.view`, `shipments.manage_status`). Un patron de PME n'a pas besoin de cette granularité. Il veut : "accès équipe", "gestion expéditions". La granularité technique reste nécessaire pour les power users.
- **Décision** :
  - Chaque module déclare des **bundles** (regroupements métier de permissions) dans `ModuleRegistry.bundles[]`. Un bundle = key, label, hint, permissions[], is_admin.
  - Les bundles sont **déclaratifs uniquement** — pas de table en base, pas de migration.
  - L'API `permissionCatalog` retourne `permissions` (inchangé) + `modules[]` avec `capabilities[]` (bundles résolus avec `permission_ids`).
  - Le drawer Company Roles offre deux modes : **Simple** (par défaut, checkboxes par capability bundle) et **Advanced** (toggle discret, checkboxes par permission individuelle).
  - En mode Simple, si les permissions d'un bundle sont partiellement cochées → état **custom** (checkbox indeterminate + badge "Custom").
  - Les bundles admin sont invisibles pour les rôles Operational (même logique que les permissions admin).
  - Le backend continue de recevoir et stocker des **permission IDs** — les bundles sont une abstraction UI uniquement.
  - Nommage interne : `bundles` dans ModuleRegistry (évite le conflit avec `capabilities` Capabilities class existante). Nommage API : `capabilities`.
- **Conséquences** :
  - 90% des companies utilisent le mode Simple — jamais exposés aux permissions techniques
  - Les power users peuvent basculer en Advanced pour fine-tuner
  - Aucune migration, aucun impact middleware, aucune table nouvelle
  - Les bundles sont non-overlapping (chaque permission appartient à un seul bundle) pour éviter les conflits de sélection

## ADR-055 : Role Templates Marketplace — Bundles dans les presets jobdomain

- **Date** : 2026-02-16
- **Contexte** : ADR-054 a introduit les bundles (regroupements métier de permissions) dans ModuleRegistry. Les presets de rôles par jobdomain (`default_roles` dans `JobdomainRegistry`) utilisaient encore des listes de permissions brutes. Le platform UI pour les presets de rôles (`/platform/jobdomains/{id}` tab "Default Roles") exposait des permissions individuelles sans l'abstraction capability.
- **Décision** :
  - `default_roles` supporte désormais `bundles` (array de bundle keys) en plus de `permissions` (array de permission keys). Lors du clonage (`JobdomainGate::assignToCompany()`), les bundles sont résolus en permissions via `ModuleRegistry::resolveBundles()`, puis fusionnés avec les permissions directes.
  - `ModuleRegistry::resolveBundles(array $bundleKeys)` : résout une liste de bundle keys en permission keys uniques. `ModuleRegistry::allBundleKeys()` : retourne toutes les bundle keys valides.
  - `JobdomainRegistry` : les rôles admin et dispatcher utilisent `bundles` (compacte, métier). Le viewer utilise `permissions` (fallback — les bundles view-only n'existent pas).
  - Le platform UI (`/platform/jobdomains/{id}`) offre le même mode Simple/Advanced que la page Company Roles : Simple = checkboxes par capability bundle, Advanced = permissions individuelles.
  - L'API `show()` retourne `module_bundles` (modules avec bundles résolus) pour alimenter le UI capability.
  - La validation `validateDefaultRoles()` vérifie les bundle keys via `ModuleRegistry::allBundleKeys()` en plus des permission keys via `CompanyPermissionCatalog::keys()`.
  - Le format stocké en DB : `{ key: { name, is_administrative?, bundles?: [...], permissions?: [...] } }`. Les deux champs sont optionnels et cumulatifs.
- **Conséquences** :
  - Les presets de rôles jobdomain utilisent le même vocabulaire capability que les rôles company
  - Le platform admin configure les rôles presets en mode Simple (bundles) sans connaître les permissions techniques
  - Backward compatible : les rôles existants avec `permissions` seules continuent de fonctionner
  - Le clonage résout bundles + permissions → IDs au moment de l'assignation (pas de dépendance runtime aux bundles)

## ADR-056 : Logistique Roles Completeness + Clone Role UX

- **Date** : 2026-02-16
- **Contexte** : Le jobdomain logistique n'avait que 2 rôles (manager + dispatcher) qui ne reflétaient pas les vrais métiers terrain. Le dispatcher était Operational alors qu'il crée des livreurs et attribue des tâches — c'est Management. Il manquait les rôles Driver et Operations Manager. Côté Company, créer un rôle from scratch est lourd : les utilisateurs veulent souvent un rôle dérivé ("Livreur Junior", "Dispatcher Senior").
- **Décision** :
  - **4 rôles métier logistique** (suppression définitive du viewer) :
    - `manager` (Management) — tous les 6 bundles, full control
    - `dispatcher` (Management) — team_access + team_management + company_info + shipments.operations. Crée des livreurs, attribue des tâches.
    - `driver` (Operational) — team_access + company_info + permissions directes [shipments.view, shipments.manage_status]. Le bundle shipments.operations inclut `create` qu'un driver ne devrait pas avoir → fallback permissions.
    - `ops_manager` (Management) — team_access + company_info + shipments.operations + shipments.administration. Gère la config des expéditions sans gérer l'équipe.
  - **Clone Role UX** : bouton "Clone" dans la colonne Actions de `/company/roles`. Clone ouvre le drawer en mode création pré-rempli (nom = "{original} Copy", même level, mêmes permissions). Utilise le POST existant — aucune nouvelle route.
- **Conséquences** :
  - Les rôles logistique sont compréhensibles par un patron de société de transport
  - Le dispatcher est Management car il gère l'équipe (invite + manage members)
  - Le driver utilise le fallback `permissions` pour éviter le bundle `shipments.operations` (trop large)
  - Le clone accélère la personnalisation : 2 clics au lieu de recréer from scratch
  - Aucune migration, aucune route nouvelle, aucun impact test

## ADR-057 : Surface Separation Layer (Structure vs Operations)

- **Date** : 2026-02-16
- **Contexte** : Le Company UI mélange les pages de gouvernance (Settings, Members, Modules, Industry, Roles) avec les pages métier (Shipments, Dashboard). Un livreur (rôle opérationnel) voit des liens de navigation vers des pages qu'il ne devrait pas utiliser. Les permissions RBAC contrôlent les actions mais pas la visibilité des sections.
- **Décision** :
  - **Deux surfaces** : `structure` (gouvernance) et `operations` (métier).
  - Chaque module dans `ModuleRegistry` déclare son `surface` (`core.members` → structure, `core.settings` → structure, `logistics_shipments` → operations).
  - Le `surface` est propagé dans les `navItems` des Capabilities.
  - Les items de navigation statiques (Modules, Industry, Roles) sont tagués `surface: 'structure'`.
  - **`roleLevel` getter** dans `auth.js` : Owner OU `is_administrative` → `'management'`, sinon `'operational'`.
  - L'API `myCompanies` inclut maintenant `is_administrative` dans `company_role`.
  - **Triple filtrage nav** : surface → ownerOnly → permission → orphan headings.
  - Les rôles opérationnels ne voient **jamais** les items `surface: 'structure'`.
  - **Page guards** sur les 4 pages structure (settings, modules, jobdomain, roles) : redirect si `roleLevel !== 'management'`.
  - Permissions ≠ Surface : les permissions contrôlent les actions API, le surface contrôle la visibilité UI des sections.
- **Conséquences** :
  - Un livreur voit uniquement Dashboard + Shipments + Account Settings
  - Un dispatcher (Management) voit Members + Settings + Shipments + Dashboard + Account
  - Le owner voit tout (management + ownerOnly)
  - Double barrière : navigation masquée + page guard en cas de navigation directe
  - Les permissions API restent intactes — un driver avec `settings.view` peut toujours lire les settings via API si nécessaire

## ADR-058 : Route-Level Surface Hardening (R3.8.1)

- **Date** : 2026-02-16
- **Contexte** : R3.8 a introduit le filtrage nav par surface et des guards dans les pages. Mais un utilisateur opérationnel (Driver) pouvait taper directement une URL structure (/company/members, /company/settings) et voir la page avant d'être redirigé. Ce n'est pas acceptable : un utilisateur opérationnel ne doit pas percevoir l'existence des surfaces structure.
- **Décision** :
  - **Route meta `surface`** : chaque page company déclare `definePage({ meta: { surface: 'structure' | 'operations' } })`.
  - **Global router guard** dans `guards.js` : si `to.meta.surface === 'structure'` et `auth.roleLevel === 'operational'` → redirect `/not-found` (404).
  - Redirection silencieuse vers 404, pas de snackbar, pas de message technique.
  - Les guards page-level (onMounted) sont supprimés — le router guard global est suffisant et bloque avant le rendu.
  - Le middleware backend reste intact : la sécurité réelle est côté serveur.
  - **Triple barrière** : Navigation Filter → Router Surface Guard → Backend Middleware.
- **Conséquences** :
  - Un Driver qui tape `/company/members` voit la page 404 standard
  - Aucune fuite d'information sur l'existence des pages structure
  - Le guard est centralisé dans le router, pas dispersé dans chaque page
  - Les pages sont déclaratives via `definePage()` meta — facile à auditer

## ADR-059 : Surface Guard After Tenant Hydration (R3.8.2b)

- **Date** : 2026-02-16
- **Contexte** : Le surface guard de R3.8.1 s'exécutait après `whenAuthResolved()` qui ne couvre que la phase auth (fetchMe). La phase tenant (fetchMyCompanies) n'était pas encore terminée. `roleLevel` dépend de `currentCompany.company_role.is_administrative` — données chargées en phase tenant. Résultat : le guard laissait passer un Driver sur les routes structure car `_companies` n'était pas encore hydraté.
- **Décision** :
  - Le surface guard utilise `STRUCTURE_ROUTES` (Set statique de noms de routes) au lieu de `to.meta.surface`.
  - Avant de vérifier `roleLevel`, le guard attend `runtime.whenReady(5000)` — identique au pattern du module guard.
  - Fallback de sécurité : si `currentCompany` est null après hydration → redirect `/login`.
  - Les routes structure sont : `company-members`, `company-members-id`, `company-settings`, `company-modules`, `company-jobdomain`, `company-roles`.
  - Le `definePage({ meta: { surface } })` reste sur les pages comme documentation déclarative.
  - **Triple barrière confirmée** : Navigation Filter → Router Guard (post-tenant) → Backend Middleware.
- **Conséquences** :
  - Le guard surface s'exécute toujours avec des données company fiables
  - Un Driver qui tape `/company/members` voit 404 — même en cold boot
  - Pas de dépendance sur `to.meta` (robuste face aux edge cases de `definePage` + `unplugin-vue-router`)
  - Le Set `STRUCTURE_ROUTES` est facile à auditer et à étendre

## ADR-060 : Forbidden Surface UX — 403 Inside Layout + Smart Fallback (R3.8.3)

- **Date** : 2026-02-16
- **Contexte** : R3.8.2b redirige un Driver vers `/not-found` (page 404 en layout blank). Problèmes : (1) le Driver perd le layout company (navigation invisible) — il semble déconnecté, (2) un 404 est sémantiquement incorrect (la page existe, l'accès est interdit), (3) pas de parcours retour fluide, (4) la redirection hardcodée vers `shipments` n'est pas agnostique du jobdomain/modules actifs.
- **Décision** :
  - Créer `/company/forbidden` — page 403 **dans le layout company** (navigation visible).
  - Contenu : icône lock, titre "Access restricted", texte "This section is reserved for management roles."
  - **Fallback jobdomain-agnostic** : la route de redirection est le premier item navigable de la nav filtrée (via `useCompanyNav().firstAccessibleRoute`). Ne jamais hardcoder un module ou une permission.
  - Factoriser la logique de navigation dans `composables/useCompanyNav.js` — partagé entre `DefaultLayoutWithVerticalNav.vue` et `forbidden.vue` (source unique de vérité).
  - Auto-redirect après 5 secondes. Boutons "Go back" (router.back()) + "Go to dashboard" (firstAccessibleRoute).
  - Le guard router (`STRUCTURE_ROUTES`) redirige vers `{ name: 'company-forbidden' }` au lieu de `/not-found`.
  - La page forbidden a `meta: { surface: 'structure' }` (documentation) mais n'est PAS dans `STRUCTURE_ROUTES` — accessible à tous les rôles.
  - Le middleware backend reste intact — la sécurité réelle est côté serveur.
- **Conséquences** :
  - Un Driver qui tape `/company/members` voit une page 403 claire dans le layout company
  - La navigation reste visible — le Driver comprend qu'il est connecté mais n'a pas accès
  - Auto-redirect agnostique vers la première page visible de sa nav (fonctionne quel que soit le jobdomain/modules actifs)
  - La logique nav n'est plus dupliquée entre le layout et la page forbidden

## ADR-061 : Unified Company Access Layer — CompanyAccess (R4)

- **Date** : 2026-02-16
- **Contexte** : L'autorisation company était fragmentée en 3 middlewares séparés (`company.permission`, `company.role`, `module.active`), chacun avec sa propre logique de bypass owner et ses propres messages d'erreur. Pas de service centralisé pour les vérifications d'accès.
- **Décision** :
  - Créer `App\Company\Security\CompanyAccess` — service statique avec une méthode unique `can(User, Company, ability, context)`.
  - 4 abilities : `access-surface` (structure vs operations), `use-module` (module actif), `use-permission` (RBAC), `manage-structure` (rôle administratif requis).
  - **Owner bypass** : s'applique à toutes les abilities SAUF `use-module` (un module inactif = pas de données, même pour le owner).
  - Créer `EnsureCompanyAccess` middleware unifié — signature `company.access:{ability},{key?}`.
  - Migrer toutes les routes company vers `company.access:*`.
  - `company.role:owner` → `company.access:manage-structure` (ouvre l'accès aux rôles administratifs, pas juste owner).
  - `company.permission:*` → `company.access:use-permission,*` (même sémantique, owner bypass identique).
  - `module.active:*` → `company.access:use-module,*` (même sémantique, pas de owner bypass).
  - Les anciens middlewares (`company.role`, `company.permission`, `module.active`) restent enregistrés mais marqués deprecated.
- **Conséquences** :
  - Un seul point d'entrée pour toute vérification d'accès company
  - Le backend devient la source de vérité unique pour l'autorisation
  - Les rôles administratifs (pas juste owner) peuvent gérer les roles/permissions
  - 191 tests passent (18 nouveaux tests CompanyAccessPolicyTest)
  - Migration progressive possible — les anciens middlewares continuent de fonctionner

## ADR-062 : Module as Folder + Registry as Aggregator (R4.1)

- **Date** : 2026-02-16
- **Contexte** : Les définitions de modules vivaient en tableaux inline dans `ModuleRegistry::definitions()`. Le code spécifique à chaque module (controllers, requests, use cases, read models) était dispersé dans `app/Company/Http/Controllers/`, `app/Company/Http/Requests/`, `app/Company/Shipments/`. Pas d'isolation physique, pas de typage des définitions — les consumers faisaient du `$def['permissions'] ?? []` fragile.
- **Décision** :
  - **ModuleManifest VO** (`app/Core/Modules/ModuleManifest.php`) : objet immutable typé remplaçant les tableaux bruts. Propriétés : `key`, `name`, `description`, `surface`, `sortOrder`, `capabilities`, `permissions`, `bundles`.
  - **Catégorisation module** : `type` (core|addon|internal), `scope` (platform|company), `visibility` (visible|hidden) = metadata idle dans le VO, aucun consumer, aucune logique de filtrage. Les champs existent pour établir le contrat ; les consumers seront ajoutés quand le premier module caché ou platform-only sera conçu.
    - `core.members` → type=core, scope=company, visibility=visible
    - `core.settings` → type=core, scope=company, visibility=visible
    - `logistics_shipments` → type=addon, scope=company, visibility=visible
  - **ModuleDefinition interface** (`app/Core/Modules/ModuleDefinition.php`) : contrat `manifest(): ModuleManifest` pour chaque module.
  - **Module as Folder** : chaque module vit dans `app/Modules/{Category}/{Name}/` avec son propre `XxxModule.php`, `Http/`, `Http/Requests/`, `UseCases/`, `ReadModels/`.
    - `app/Modules/Core/Members/` — MembersModule + 2 controllers + 2 requests
    - `app/Modules/Core/Settings/` — SettingsModule + 4 controllers + 1 request
    - `app/Modules/Logistics/Shipments/` — ShipmentsModule + 1 controller + 2 requests + 2 use cases + 1 read model
  - **ModuleRegistry comme agrégateur** : charge les manifests depuis les classes Module, avec cache statique. Plus de tableaux inline.
  - **Infrastructure partagée inchangée** : `app/Core/Models/`, `app/Company/RBAC/`, `app/Company/Security/`, `app/Core/Fields/`, `app/Core/Jobdomains/` restent en place (shared, non module-specific).
  - 15 fichiers déplacés, namespaces mis à jour, `routes/company.php` mis à jour.
- **Conséquences** :
  - Isolation physique des modules — un module = un dossier
  - Définitions typées — les consumers accèdent aux propriétés typées
  - Agrégation propre via le registre
  - Zéro changement comportemental — 191 tests passent, API responses identiques
  - Les champs idle (type, scope, visibility) seront consommés quand les premiers modules cachés ou platform-only seront conçus

## ADR-063 : Atomic Deployment Architecture — Web Symlink Strategy (R4.2)

- **Date** : 2026-02-16
- **Contexte** : Le déploiement documenté dans ADR-018 et ADR-036 n'était jamais implémenté — `webhook.php` et `deploy-leezr.sh` n'existaient pas. L'ancien système utilisait `git pull` + `reset --hard` (mutant le code en place, risque de downtime). La branche `main` avait un seul commit initial, 44+ commits sur `dev` jamais déployés.
- **Décision** : Atomic release deployment avec symlink web.

  **Architecture serveur** (par site) :
  ```
  /var/www/clients/client1/{web2|web3}/
    releases/           ← releases timestampées (git clone --depth=1)
    shared/
      .env              ← config persistante + GITHUB_WEBHOOK_SECRET
      storage/          ← storage persistant (logs, cache, sessions, uploads)
    current             → releases/{latest}
    web                 → current/public    ← document root Apache (inchangé)
  ```

  **Pourquoi releases/** : chaque deploy est un dossier isolé. Pas de mutation in-place, pas de `git pull`, pas de fichiers orphelins d'anciennes versions.

  **Pourquoi current symlink** : bascule atomique (`ln -sfn` + `mv -Tf`). L'ancien code continue de servir les requêtes pendant que le nouveau build se prépare. Seul le switch du symlink est visible.

  **Pourquoi web symlink** : Apache pointe déjà vers `{base}/web`. Au lieu de modifier la config Apache, `web` devient un symlink vers `current/public`. Zéro changement Apache.

  **Pourquoi jamais git pull en prod** : `git pull` mute le répertoire en place → état intermédiaire visible (fichiers supprimés, vendor incomplet, assets absents). Le clone frais garantit un état 100% cohérent à chaque instant.

  **Rollback** : `ln -sfn releases/{old_timestamp} current` — instantané, pas de re-build.

  **Fichiers** :
  - `deploy.sh` (racine repo, commité) : clone → link shared → composer → migrate → seed → pnpm build → optimize → switch symlinks → cleanup (keep 3).
  - `public/webhook.php` (commité) : valide signature SHA256, mappe branche → chemin, dispatch `deploy.sh` en background via `nohup`.
  - Secret lu via `getenv('GITHUB_WEBHOOK_SECRET')` ou fallback parsing `shared/.env`. Jamais hardcodé.

  **Mapping** :
  - `dev` → `/var/www/clients/client1/web3` → `dev.leezr.com`
  - `main` → `/var/www/clients/client1/web2` → `leezr.com`

  **Supersède** : ADR-018 (deployment method) et ADR-036 (pipeline definition). Les règles de migration safety d'ADR-036 restent en vigueur.

- **Conséquences** :
  - Zéro downtime — l'ancien release sert les requêtes jusqu'au switch
  - Rollback instantané par symlink
  - 3 releases conservées, les anciennes sont nettoyées automatiquement
  - Storage persistant (logs, uploads) partagé entre releases
  - Pas de CI/CD externe — webhook GitHub direct
  - Le premier deploy est manuel (`bash deploy.sh dev {path}`), les suivants sont automatiques

---

### ADR-064 — Deployment Safety Hardening (R4.2.1)

- **Date** : 2026-02-16 (révisé 2026-02-17)
- **Statut** : Accepté (révisé — production gate retirée)
- **Contexte** :
  ADR-063 a mis en place le déploiement atomique (releases + symlinks). En conditions réelles, trois risques restent ouverts :
  1. **Double deploy** — deux webhooks simultanés (push rapide) lancent deux `deploy.sh` en parallèle → état incohérent
  2. **Release cassée activée** — le switch symlink se fait avant vérification → si les routes ou migrations sont cassées, le site est down
  3. **Pas de trace webhook** — seul `deploy.sh` loggue ; le trigger initial (qui a pushé, quel commit) n'est pas tracé

- **Décision** :

  **1. flock anti-double-deploy** (`deploy.sh`)
  ```bash
  LOCK_FILE="$SHARED_DIR/.deploy-${BRANCH}.lock"
  exec 200>"$LOCK_FILE"
  if ! flock -n 200; then
      echo "BLOCKED — another $BRANCH deploy is running."
      exit 1
  fi
  ```
  Verrou par branche dans `shared/` (pas `/tmp/` — évite conflit root/web user). Deux branches différentes peuvent déployer en parallèle (staging ≠ prod), mais deux deploys de la même branche sont sérialisés. Le lock est automatiquement libéré quand le processus se termine.

  **2. Health check pré-switch** (`deploy.sh`, étape 8/9)
  ```
  php artisan config:clear
  php artisan route:list > /dev/null
  php artisan migrate:status > /dev/null
  php artisan optimize   # re-cache après config:clear
  ```
  Si une de ces commandes échoue (`set -euo pipefail`), le script s'arrête. Le symlink n'est jamais basculé. La release reste dans `releases/` mais `current` pointe toujours l'ancienne version fonctionnelle.

  **3. Webhook trigger logging** (`public/webhook.php`)
  ```
  [2026-02-17 03:00:00] WEBHOOK TRIGGER: branch=dev pusher=djamelji commit=abc1234 (fix: button alignment)
  ```
  Chaque trigger est loggué dans `deploy.log` AVANT le dispatch de `deploy.sh`. Permet de corréler trigger → deploy dans un seul fichier.

  **~~4. Production manual gate~~ — RETIRÉE** (2026-02-17)
  La gate `--promote` a été retirée. Les deux branches (dev et main) font un auto-deploy complet (build + switch). En phase de développement actif, la friction du promote manuel n'apporte pas de valeur ajoutée. La gate pourra être réintroduite si nécessaire quand le projet aura du trafic réel.

- **Conséquences** :
  - Zéro double deploy possible (flock par branche)
  - Zéro release cassée activée (health check pré-switch, `set -euo pipefail`)
  - **Les deux branches sont 100% auto-deploy** (push → build → switch → live)
  - Traçabilité complète dans `deploy.log` (trigger + build + switch)
  - Rollback toujours instantané : `ln -sfn releases/{old} current`

- **Config serveur requise** :
  - `open_basedir` : inclure `releases/` et `shared/` dans les pools FPM
  - Ownership : `web{N}:client1` sur `releases/`, `shared/`, symlinks
  - Permissions : `g+w` + setgid sur `releases/` et `shared/`
  - ISPConfig : désactiver immutable flag (`+i`) sur les base paths

---

## ADR-065 : OverlayNav Scrim Hardening (R4.3)

- **Date** : 2026-02-17
- **Contexte** : Après login sur `leezr.test`, le site apparaissait en « mode tamisé » — un overlay noir semi-transparent (`.layout-overlay.visible`) bloquait tous les clicks. Le composant `VerticalNavLayout.vue` (Vuexy `@layouts`) gère un overlay scrim (z-index 1002, `rgb(0 0 0 / 60%)`, `inset: 0`) pour le menu mobile (viewport < 1280px). Trois fragilités identifiées :
  1. **Click-to-close = toggle** : `isLayoutOverlayVisible = !isLayoutOverlayVisible` — si l'état est désynchronisé, le toggle aggrave au lieu de fermer
  2. **Breakpoint guard partiel** : le watcher windowWidth ne reset que `isLayoutOverlayVisible`, pas `isOverlayNavActive` — dépendance implicite sur `syncRef` pour propager
  3. **Route watcher sur `route.name` uniquement** : les navigations avec même nom de route mais params différents ne fermaient pas l'overlay

  **Audit** : `isOverlayNavActive` et `isLayoutOverlayVisible` sont des `ref(false)` locaux — aucune persistance (pas de cookie, localStorage, Pinia). `syncRef` bidirectionnel avec `flush: 'sync'`. Le bug n'est PAS un problème runtime (ADR-047/048/059/060 tous respectés). R4.1 n'a touché aucun fichier frontend.

- **Décision** :
  1. **Click-to-close = always close** : `@click` sur `.layout-overlay` → `isOverlayNavActive = false; isLayoutOverlayVisible = false` (plus de toggle)
  2. **Breakpoint guard complet** : le watcher reset les DEUX refs quand le viewport passe en desktop (>= 1280px), sans condition préalable sur `isLayoutOverlayVisible`
  3. **Route watcher sur `route.fullPath`** : couvre les changements de params, query, hash — pas seulement le nom de route

  **Dérogation CLAUDE.md** : Ces modifications touchent `@layouts/components/VerticalNavLayout.vue` et `@layouts/components/VerticalNav.vue` — normalement interdits. Dérogation justifiée : fix de robustesse UI, non-cosmétique, sans changement de comportement visible. Les modifications sont défensives (force-close au lieu de toggle, reset complet au lieu de partiel).

- **Conséquences** :
  - Après login → jamais d'overlay bloquant
  - Après refresh → jamais d'overlay bloquant
  - Mobile (< 1280px) : hamburger ouvre, click scrim ferme (garantie), route change ferme
  - Desktop (>= 1280px) : scrim jamais visible, force-close sur changement de taille fenêtre
  - Aucune ADR runtime touchée (047a-d, 048, 059, 060 intacts)
  - 191 tests PHP passent, `pnpm build` clean

## ADR-066 : Platform Modularization Convergence (R4.4)

- **Date** : 2026-02-17
- **Contexte** : R4.1 a modularisé le scope company : `ModuleDefinition`, `ModuleManifest`, dossiers par module, RBAC et navigation pilotés par les modules. Le scope platform restait monolithique : 9 contrôleurs dans un seul dossier, 8 permissions statiques dans `PermissionCatalog`, navigation statique dans `platform.js`. Deux architectures coexistantes = dette de divergence.

- **Décision** :
  1. **8 modules platform** implémentant `ModuleDefinition` avec `scope: 'platform'`, `type: 'internal'` : Dashboard, Companies, Users, Roles, Modules, Jobdomains, Fields, Billing (stub hidden)
  2. **Contrôleurs déplacés** dans les dossiers modules (`app/Modules/Platform/{Domain}/Http/`) — 10 fichiers, namespaces mis à jour, anciens supprimés
  3. **`ModuleRegistry::forScope()`** filtre par scope. `sync()` préserve `is_enabled_globally` existant. `resolveBundles()` et `allBundleKeys()` scopés company-only
  4. **`PermissionCatalog` → `PlatformPermissionCatalog`** : renommé, agrège depuis les manifests modules (même pattern que `CompanyPermissionCatalog`). Méthode `sync()` ajoutée
  5. **`CompanyPermissionCatalog`** scopé à `forScope('company')` pour exclure les permissions platform
  6. **`ModuleCatalogReadModel::forCompany()`** filtre par scope company (les modules platform n'apparaissent pas dans le toggle company)
  7. **`PlatformModuleController`** filtre scope company pour index/toggle (modules platform non toggleables)
  8. **`PlatformAuthController`** enrichi : `/me` et `/login` retournent `platform_modules` (nav items des modules visibles)
  9. **`platformAuth` store** : nouvel état `_platformModuleNavItems` persisté en cookie
  10. **`usePlatformNav()` composable** : miroir de `useCompanyNav()`, permission-filtered, module-driven
  11. **`PlatformLayoutWithVerticalNav.vue`** utilise le composable, plus d'import statique
  12. **`navigation/platform.js` supprimé** : remplacé par le composable module-driven
  13. **Billing module** : `visibility: 'hidden'` — zéro consommateur runtime, prêt pour futur

- **Conséquences** :
  - Architecture unifiée : company et platform suivent le même pattern module-driven
  - Un seul registre (`ModuleRegistry`) avec `forScope()` pour filtrage
  - Permissions, navigation, bundles — tout piloté par les manifests modules
  - Zéro changement comportemental : mêmes routes, mêmes permissions, même UI
  - 191 tests passent, `pnpm build` clean
  - 10 nouveaux `platform_modules` rows en DB (sync idempotent)

## ADR-067 : Platform-Governed UI Theme System — Global Strict (R4.5)

- **Date** : 2026-02-17
- **Contexte** : Le projet utilise Vuexy avec un thème configurable frontend (Customizer), persisté en cookies locaux. Aucun contrôle backend, aucune gouvernance platform, aucun support multi-scope. Audit complet réalisé : 12 cookies thème, 2 localStorage, 3 phases d'initialisation indépendantes. Bug identifié : le loader pre-Vue lit `vuexy-initial-loader-*` mais `initCore.js` écrit dans `Leezr-initial-loader-*` → le loader ne récupère jamais les couleurs sauvegardées.

- **Décision** : **Global strict** — la Platform est l'unique source de vérité UI.

  **Phase 0** (fix immédiat) :
  1. Corriger `application.blade.php` : namespace `vuexy-` → `Leezr-` pour les localStorage keys du loader
  2. Corriger le titre HTML : `Vuexy - Vuejs Admin Dashboard Template` → `Leezr`
  3. Supprimer la ligne dupliquée `--initial-loader-bg`

  **Phase 1** (pipeline backend → frontend) :
  1. `app/Core/Theme/ThemePayload.php` — VO immutable (theme, skin, primaryColor, primaryDarkenColor, layout, navCollapsed, semiDark, navbarBlur, contentWidth)
  2. `app/Core/Theme/UIResolverService.php` — résout le thème par scope (`forPlatform()`, `forCompany()`). Phase 1 = defaults statiques identiques à `themeConfig.js`
  3. `PlatformAuthController` `/login` et `/me` → `ui_theme` dans la réponse JSON
  4. `AuthController` `/login`, `/register`, `/me` → `ui_theme` dans la réponse JSON
  5. `applyTheme(payload, vuetifyTheme?)` — fonction frontend qui écrit dans les stores Vuexy existants (theme, skin, layout via configStore/layoutStore) + cookies pour primary colors. Accepte optionnellement l'instance Vuetify pour mutation directe des couleurs
  6. Intégré dans `auth.js` (login, register, fetchMe) et `platformAuth.js` (login, fetchMe)

  **Phases futures** (non implémentées) :
  - Phase 2 : `platform_settings` table DB + page admin Theme
  - Phase 3 : company override conditionnel

- **Conséquences** :
  - Le backend livre le thème, le frontend l'applique via les mêmes stores existants
  - Zéro changement visuel en Phase 1 (defaults identiques)
  - Le loader pre-Vue affiche désormais les bonnes couleurs sauvegardées
  - `@core/`, `@layouts/`, `initCore()`, `initConfigStore()` — inchangés
  - Les watchers existants propagent theme/skin/layout automatiquement
  - Les couleurs primaires sont persistées en cookies pour le prochain boot
  - 191 tests passent, `pnpm build` clean

---

## ADR-068 : Platform Theme Settings — DB-backed, Global Strict (R4.6)

- **Date** : 2026-02-17
- **Contexte** : R4.5 (ADR-067) a créé le pipeline `UIResolverService` → `ThemePayload` → `applyTheme()`, mais `forPlatform()` retournait des defaults hardcodés. Aucun admin ne pouvait changer le thème global sans déployer du code. R4.6 ajoute la couche DB et une page admin pour que les administrateurs platform puissent gouverner le thème UI depuis l'interface. Mode Global strict : la platform décide tout, pas d'override company.

- **Décision** : **Single-row JSON column** dans `platform_settings`.

  **Architecture** :
  - `platform_settings` table avec colonne `theme` JSON nullable (singleton strict, max 1 row, `RuntimeException` si >1)
  - `PlatformSetting` model avec `instance()` — singleton access pattern
  - `ThemePayload::defaults()` — le VO possède ses propres defaults (source unique)
  - `UIResolverService::forPlatform()` — lit depuis DB, fallback sur `ThemePayload::defaults()`
  - `ThemeModule` — manifeste module platform (permission `manage_theme_settings`, nav item `tabler-palette`)
  - `ThemeController` — `GET /theme` (lecture) + `PUT /theme` (update hardened : validation, bool cast, layout guard horizontal, transaction)
  - Page frontend `/platform/theme` — formulaire inspiré de `TheCustomizer.vue`, CustomRadiosWithImage, Save + Reset to Defaults

  **Guards défensifs** :
  1. `(bool)` cast explicite sur tous les champs boolean
  2. Layout horizontal → force `nav_collapsed=false` et `semi_dark=false`
  3. Transaction DB wrapping read + update
  4. `$setting->fresh()->theme` retourne l'état DB réel après écriture

- **Conséquences** :
  - Les admins platform peuvent changer le thème global depuis `/platform/theme`
  - Effet au prochain login pour tous les utilisateurs
  - Le seeder utilise `ThemePayload::defaults()->toArray()` — zéro dépendance circulaire
  - `forCompany()` délègue à `forPlatform()` (Global strict)
  - Future Phase 3 : company override conditionnel sur cette base

---

## ADR-069 : R4.6 Correction — Vuetify Runtime Mutation + Live Preview + Semi-dark Inversion + Platform Horizontal Layout

- **Date** : 2026-02-17
- **Contexte** : R4.6 (ADR-068) avait trois problèmes. (1) `applyTheme()` écrivait les cookies primary color mais ne mutait jamais Vuetify runtime car le paramètre `vuetifyTheme` n'était jamais passé depuis les stores Pinia (pas de contexte d'injection). (2) La page `/platform/theme` ne rappelait pas `applyTheme()` après save — DB mise à jour mais UI inchangée. (3) Le layout platform était hardcodé sur `VerticalNavLayout`, le setting layout horizontal n'avait aucun effet. (4) Semi-dark ne fonctionnait qu'en mode light (comportement natif Vuexy), pas d'inversion en mode dark.

- **Décision** : **4 corrections, 0 fichier backend modifié.**

  **1. Export Vuetify instance** (`plugins/vuetify/index.js`) :
  - `export let vuetifyInstance = null`, assigné après `createVuetify()` avant `app.use()`
  - Disponible de façon synchrone avant tout appel à `applyTheme()`

  **2. Réécriture `applyTheme()`** (`composables/useApplyTheme.js`) :
  - Suppression du paramètre optionnel `vuetifyTheme` — signature : `applyTheme(payload)`
  - Import `vuetifyInstance` directement → mutation `vuetifyInstance.theme.themes.value.{light,dark}.colors.primary`
  - Ajout sync loader color : `useStorage(namespaceConfig('initial-loader-color'))` (même pattern que `@core/initCore.js`)
  - Aucun changement nécessaire dans les 4 call sites (`auth.js`, `platformAuth.js`)

  **3. Platform horizontal layout** :
  - Nouveau fichier `PlatformLayoutWithHorizontalNav.vue` — miroir du vertical avec `HorizontalNavLayout`, filtre des items `heading` (non supportés par HorizontalNav)
  - `platform.vue` — switch dynamique `Component :is="..."` sur `configStore.appContentLayoutNav` (même pattern que `default.vue`)

  **4. Semi-dark inversion** (`@core/composable/useSkins.js` — dérogation politique) :
  - Remplacement `? 'dark'` par `? (vuetifyTheme.global.name.value === 'dark' ? 'light' : 'dark')`
  - Light + semi-dark = navbar dark (inchangé) | Dark + semi-dark = navbar light (nouveau)

  **5. Page theme — live preview + UI compacte** :
  - `watch(form, deep)` appelle `applyTheme()` en temps réel (sauf `layout` qui cause un remount)
  - Layout appliqué uniquement au Save/Reset
  - Primary Color (col 6) + Theme Mode (col 6) sur une ligne
  - Skin / Layout / Content Width côte à côte avec `VDivider vertical` responsive
  - Color picker natif (`input[type=color]`) inline avec les swatches preset

- **Conséquences** :
  - Les couleurs primary s'appliquent immédiatement à toute l'UI sans double-reload
  - La page theme donne un feedback visuel instantané pendant l'édition
  - Le layout horizontal fonctionne sur le scope platform
  - Semi-dark est symétrique : toujours l'inverse du thème actif
  - Dérogation `@core/` documentée et limitée à 1 ligne dans `useSkins.js`

## ADR-070 : Platform Settings Module — Unified Settings Page (R4.7A)

- **Date** : 2026-02-18
- **Contexte** : Le module `platform.theme` (ADR-068) était un module isolé avec une page standalone `/platform/theme`. L'audit session governance a identifié la nécessité de paramètres session configurables (idle timeout, warning threshold, heartbeat interval, remember me). Plutôt que de créer un module séparé par type de setting, on unifie tous les settings platform dans un module unique avec une page tabulée.

- **Décision** : **Module `platform.settings` remplace `platform.theme`.** Page `/platform/settings/[tab]` avec 3 tabs : General (placeholder), Theme (existant), Sessions (nouveau).

  **1. DB** : colonne `session` JSON ajoutée à `platform_settings` (singleton). Payload : `idle_timeout`, `warning_threshold`, `heartbeat_interval`, `remember_me_enabled`, `remember_me_duration`.

  **2. Value Object** : `SessionSettingsPayload` (`app/Core/Settings/`) — même pattern que `ThemePayload`. `defaults()` + `fromSettings()` merge DB over defaults.

  **3. Module manifest** : `PlatformSettingsModule` (`app/Modules/Platform/Settings/`) remplace `ThemeModule`. Permissions : `manage_theme_settings` (inchangée) + `manage_session_settings` (nouvelle). Bundles : `settings.appearance` + `settings.sessions`.

  **4. Controller** : `SessionSettingsController` — show/update avec validation (guards : `warning_threshold < idle_timeout`, `heartbeat_interval < idle_timeout`).

  **5. Frontend** : page `[tab].vue` pattern URL-routed tabs (preset `account-settings`). Tab components avec préfixe `_` (exclus du routing par unplugin-vue-router). Store platform enrichi (session settings CRUD).

  **6. Nettoyage** : `ThemeModule.php` supprimé. `ThemeController` inchangé. Orphan `platform.theme` row supprimée en migration.

- **Conséquences** :
  - Les settings platform sont unifiés dans une seule page tabulée extensible
  - Les paramètres session sont configurables via l'UI (pas juste `.env`)
  - La permission `manage_theme_settings` est préservée (backward compat avec rôles existants)
  - La nouvelle permission `manage_session_settings` est auto-détectée par `PlatformPermissionCatalog`
  - Le `ThemeController` et les routes theme restent inchangés
  - R4.7B (backend engine) et R4.7C (frontend governance) consommeront ces settings DB

---

## ADR-071 : Deploy Fix — Stale Blade Views + OPcache Invalidation (R4.2.2)

- **Date** : 2026-02-18
- **Contexte** : Après le push R4.7A, le deploy webhook se déclenchait correctement (200 OK, `deploy_triggered`) et `deploy.sh` créait une nouvelle release avec le bon commit. Pourtant, la prod servait toujours l'ancien bundle Vite (`main-DqECAEzL.js` au lieu de `main-BGyzyRNS.js`). Le manifest Blade et les fichiers étaient corrects sur disque.

  **Cause racine** : les vues Blade compilées dans `shared/storage/framework/views/` hardcodaient le chemin absolu de l'ancienne release (ex: `releases/20260217_025814/resources/views/application.blade.php`). PHP-FPM (pool `web2`, **PHP 8.4** — pas 8.3) gardait ces chemins en OPcache (`revalidate_path=Off`). Résultat : PHP résolvait le manifest Vite de l'ancienne release malgré le switch symlink.

  **Problème secondaire** : une `RewriteRule ^(.*)$ public/$1 [L]` dans les Apache Directives ISPConfig — vestige de l'ancien setup (`web.old` = racine projet). Non bloquante grâce au fallback `.htaccess` Laravel, mais inutile depuis que `web → current/public`.

- **Décision** : Patch `deploy.sh` — ajouter 2 étapes avant le switch symlinks (étape 8/9) :

  ```bash
  php "$RELEASE_DIR/artisan" view:clear
  systemctl reload php8.4-fpm
  ```

  1. **`view:clear`** — purge les vues Blade compilées dans `shared/storage/framework/views/` pour forcer la recompilation avec les chemins de la nouvelle release.
  2. **`systemctl reload php8.4-fpm`** — vide l'OPcache et le realpath cache de PHP-FPM pour que les nouveaux chemins soient résolus immédiatement.

  **Note** : le pool PHP-FPM du site est géré par **PHP 8.4** (pas 8.3). ISPConfig configure le pool dans `/etc/php/8.4/fpm/pool.d/web2.conf`.

- **Conséquences** :
  - Chaque deploy purge les vues compilées stale — plus de mismatch manifest Vite
  - Le reload PHP-FPM est atomique (graceful restart, pas de downtime)
  - La `RewriteRule public/$1` dans ISPConfig reste à supprimer manuellement (cosmétique, non bloquante)
  - **Supersède partiellement** ADR-064 (complète le hardening deploy avec le fix OPcache)

---

## ADR-072 : Font Library System — Self-Hosted First Typography (Backend)

- **Date** : 2026-02-19
- **Contexte** : L'application utilise "Public Sans" hardcodé via Google Fonts (`webfontloader.js` plugin) et variable SCSS compile-time (`$body-font-family`). Aucun admin ne peut changer la police globale sans déployer du code. Vuetify ne supporte pas le changement de font-family via son API reactive — il faut un mécanisme CSS runtime pour override.

- **Décision** : **Font Library System** avec deux tables dédiées + colonne JSON `typography` sur `platform_settings`.

  **1. DB** : `platform_font_families` (name, slug, source enum local|google, is_enabled) + `platform_fonts` (family_id FK cascade, weight 100-900, style normal|italic, format woff2, file_path, sha256, unique constraint family+weight+style). Colonne JSON `typography` sur `platform_settings`.

  **2. Value Object** : `TypographyPayload` (`app/Core/Typography/`) — même pattern que `ThemePayload`. `defaults()` + `toArray()`. Champs : `active_source`, `active_family_id`, `google_fonts_enabled` (false par défaut), `google_active_family`, `google_weights`, `headings_family_id`, `body_family_id`.

  **3. Resolver** : `TypographyResolverService` — `forPlatform()` résout les font_faces avec URLs publiques pour les polices locales. `forCompany()` délègue (global strict). Guard : si `google_fonts_enabled=false`, force `active_source=local`.

  **4. Controller** : `TypographyController` (6 endpoints) — show, update settings, create family, upload woff2 (upsert variant), delete variant, delete family (409 si active).

  **5. Storage** : fichiers woff2 stockés dans `storage/app/public/fonts/{slug}/` via disque `public`. Symlink `storage:link` déjà en place.

  **6. Self-hosted first** : Google Fonts désactivé par défaut (RGPD-friendly). Toggle admin pour activer. Si OFF, source forcée à local.

- **Conséquences** :
  - Les admins platform peuvent uploader des polices woff2 et les activer globalement
  - Google Fonts optionnel — désactivé par défaut
  - Le défaut reste Public Sans (pas de changement visible sans action admin)
  - Permission réutilisée : `manage_theme_settings` (typographie = apparence)
  - Sous-lot backend uniquement — le frontend (UI + runtime) suit dans des sous-lots séparés
  - ApexCharts avec `fontFamily: 'Public Sans'` hardcodé ne changera pas (tradeoff accepté)

## ADR-073 : Font Library Frontend — Typography UI + Runtime CSS + Auth Integration

- **Date** : 2026-02-19
- **Contexte** : ADR-072 a livré le backend Font Library (tables, VO, controller). Il manque le frontend : UI d'administration, application runtime de la police via CSS custom property, et intégration auth pour que la config typographique soit livrée à chaque login/me.

- **Décision** : **3 sous-lots frontend** intégrés en un seul lot.

  **1. Frontend UI** : `_SettingsTypography.vue` — composant d'administration (liste de familles, upload woff2 par variante via expansion panel, activation famille, toggle Google Fonts, preview live). Intégré dans `_SettingsTheme.vue` (section Typography en bas de la carte Theme).

  **2. Runtime CSS** : `useApplyTypography.js` — composable qui injecte les `@font-face` ou Google Fonts `<link>` dans le `<head>`, et met à jour `--lzr-font-family` sur `documentElement`. SCSS variable `$font-family-custom` consomme `var(--lzr-font-family, "Public Sans")`. Early init dans `application.blade.php` via `localStorage` pour éviter le flash de font.

  **3. Auth integration** : `ui_typography` ajouté aux réponses `/me` et `/login` (platform + company). Les stores auth persistent la config. `applyTypography()` appelé en parallèle de `applyTheme()`.

- **Conséquences** :
  - Les admins platform contrôlent la police globale depuis Settings > Theme > Typography
  - Preview live sans save, persisté en `localStorage` (`lzr-typography`) pour early init
  - Changement de police = 0 flash grâce à l'early init dans le Blade template
  - SCSS `$font-family-custom` utilise le CSS custom property — Vuetify suit automatiquement
  - Google Fonts optionnel (toggle admin), désactivé par défaut (RGPD)

## ADR-074 : Session Governance Runtime — Server-Authoritative Idle Timeout (R4.8)

- **Date** : 2026-02-19
- **Contexte** : Les paramètres de session (idle_timeout, warning_threshold, heartbeat_interval) sont configurables via Platform Settings > Sessions et stockés en DB (ADR-070). Mais **aucun runtime** ne les utilise — aucun timeout, aucun warning, aucun heartbeat. Source de vérité : `sessions.last_activity` (colonne integer, table gérée par le driver `database` de Laravel). Formule TTL : `last_activity + idle_timeout - now()`.

- **Décision** : **Architecture 3 couches server-authoritative**.

  **Layer 1 — Backend Middleware** : `SessionGovernance` (app/Http/Middleware/) appliqué uniquement sur les groupes auth (`auth:platform`, `auth:sanctum`, `auth:sanctum` + `company.context`). 3 guards (driver check, sessionId null, logout exclusion). Lit `sessions.last_activity` directement depuis la DB (pas de cache — changement admin = effet immédiat). Si expiré → `invalidate()` + 401. Sinon → `$next()` puis header `X-Session-TTL` = `idle_timeout` en secondes. Routes heartbeat `POST /heartbeat` → 204 (le middleware gère le TTL header). `/me` et `/login` retournent `ui_session` config.

  **Layer 2 — Frontend Composable** : `useSessionGovernance()` — scope-aware (`platform` | `company`). Protection double-mount (`start()` appelle `stop()` si déjà actif). Cleanup strict (tous intervals + DOM listeners + CustomEvent + watcher). DOM activity tracking (passive, throttle 1s). Tick countdown (1s). Heartbeat via native `fetch` (pas ofetch, pour lire les headers). TTL resync depuis 3 sources : (1) heartbeat response header, (2) `CustomEvent('lzr:session-ttl')` dispatché par les interceptors API `onResponse`, (3) `BroadcastChannel` cross-tab via runtime store watcher.

  **Layer 3 — UI Dialog** : `SessionTimeoutWarning.vue` — pattern exact `ConfirmDialog` preset (persistent, countdown, "Stay Connected").

  **401 Interceptors** : purge stores + `runtime.teardown()` + `postBroadcast('session-expired')` + hard redirect (`window.location.href`, jamais `router.push`).

  **BroadcastChannel** : 2 nouveaux events (`session-extended`, `session-expired`) sur le canal `leezr-runtime` existant. `session-expired` reçu → teardown + purge auth cookies + redirect login.

- **Conséquences** :
  - Le serveur est l'autorité unique sur l'expiration de session
  - Changement admin de `idle_timeout` → effet immédiat (pas de cache)
  - Multi-onglet coordonné : heartbeat Tab A → broadcast → Tab B resync
  - Session expirée côté serveur → 401 → interceptor purge + broadcast + hard redirect (pas de zombie JS)
  - Heartbeat envoyé SEULEMENT si l'utilisateur est actif (économie réseau)
  - Navigation CRUD active resync le TTL via `onResponse` (heartbeat = fallback)
  - Hot reload Vite = pas de timers doublés (protection double-mount)
  - Login/logout routes exclues de la gouvernance (pas de boucle)
  - 16 fichiers (3 CREATE, 13 MODIFY)

---

## ADR-075 : Overlay fantôme — bfcache + chunk error hardening

- **Date** : 2026-02-17
- **Contexte** : Bug récurrent (3 occurrences) — overlay sombre bloquant toute interaction après login. Symptômes clés : persiste après refresh dans le même onglet, fonctionne dans un nouvel onglet, pastille bleue Chrome.
- **Diagnostic** :
  - **Cause 1 — bfcache** : Chrome restaure l'état JS complet (heap, DOM, overlays) depuis le back-forward cache. Un `F5` peut servir depuis bfcache au lieu d'un vrai reload. Nouvel onglet = load frais = pas de bfcache. Explique 100% des symptômes "persiste après refresh, nouvel onglet OK".
  - **Cause 2 — chunk error loop** : Après deploy, les anciens chunks sont 404. `handleChunkError()` reload une fois, puis montre un overlay (`z-index: 99999`) au 2ème échec en 10s. Le bouton "Rafraîchir" appelait `location.reload()` qui pouvait re-404 → boucle infinie overlay.
  - **Cause 3 — pas de cleanup post-mount** : Si Vue monte après une recovery chunk, l'overlay brut DOM n'était jamais supprimé.
- **Décision** :
  1. **bfcache guard** (`main.js`) : `window.addEventListener('pageshow', e => { if (e.persisted) location.reload() })` — force un vrai reload quand Chrome restaure depuis bfcache.
  2. **Chunk error hardening** (`main.js`) : au 2ème échec, `sessionStorage.removeItem('lzr:chunk-reload')` pour que le prochain refresh reparte propre. Bouton "Rafraîchir" navigue vers `location.pathname` (pas reload, évite cache HTTP stale).
  3. **Post-mount cleanup** (`main.js`) : après `app.mount('#app')`, supprime `#lzr-chunk-error` et tout `.layout-overlay.visible` résiduel.
  4. **Route afterEach guard** (`guards.js`) : après chaque navigation, supprime `#lzr-chunk-error` et réinitialise `.layout-overlay.visible` si desktop (≥ 1280px).
- **Conséquences** :
  - Le bfcache ne peut plus restaurer un état JS zombie — reload forcé garanti
  - Le chunk error overlay ne peut plus boucler — chaque refresh repart propre
  - Vue mount réussie = nettoyage automatique de tout overlay stale
  - Navigation = nettoyage automatique des overlays orphelins
  - `@layouts/` et `@core/` non modifiés (politique UI respectée)

---

## ADR-076 : CI/CD — Build en CI, deploy atomique, zéro build VPS

- **Date** : 2026-02-17
- **Contexte** : Le système webhook (deploy.sh + webhook.php) causait des échecs récurrents : secret webhook mal synchronisé, build pnpm sur VPS (slow + fragile), permissions ISPConfig, chunks 404 lors de deploy partiels. 5 incidents en 2 jours.
- **Décision** : Remplacer le webhook par GitHub Actions CI/CD.
  - **Build en CI** : GitHub Actions runner fait `composer install --no-dev` + `pnpm install` + `pnpm build`. Produit un artifact tar.gz (~50MB) contenant vendor/, public/build/, et tout le code backend. Zéro build sur le VPS.
  - **Deploy via SSH** : L'artifact est SCP sur le VPS, puis `deploy_release.sh` l'unpack dans `releases/`, lie shared/.env + storage, run migrations, optimize, et switch le symlink atomiquement (`ln -sfn` + `mv -Tf`).
  - **Rollback** : `rollback.sh` liste les releases et repointe le symlink. 1 commande, instantané.
  - **Health check** : Après deploy, curl `$DEPLOY_URL/up`. Si 5 échecs → échec du workflow + affichage des logs.
  - **Anti-concurrence** : flock par app_path + GitHub Actions `concurrency` group par branche.
  - **Deux environnements** : GitHub Environments `staging` (dev) et `production` (main) avec variables spécifiques (APP_PATH, DEPLOY_URL).
- **Fichiers** :
  - `.github/workflows/deploy.yml` — workflow CI/CD
  - `deploy/deploy_release.sh` — script de déploiement atomique
  - `deploy/rollback.sh` — script de rollback
  - `docs/deploy.md` — documentation complète
- **Ancien système** : `public/webhook.php` + `deploy.sh` (racine) sont dépréciés. Supprimer le webhook GitHub après validation du nouveau système.
- **Conséquences** :
  - Zéro build sur VPS — plus de problèmes pnpm/node
  - Chunks 404 impossible — public/build/ et code backend dans le même artifact
  - Deploy idempotent — relancer N fois = même résultat
  - Rollback instantané — symlink switch
  - Pas de deploy partiel — échec avant switch = ancien code reste live
  - Traçabilité complète — GitHub Actions logs + deploy.log
  - Secrets dans GitHub (pas sur le VPS, sauf .env)
  - Latence deploy : ~3-5 min (build CI ~2 min, transfer ~30s, deploy ~1 min)

---

## ADR-077 : Post-deploy Production Fixes — CORS, Auth Redirect, Version Mismatch Loop

- **Date** : 2026-02-18
- **Contexte** : Après le premier déploiement CI/CD (ADR-076), la plateforme était inutilisable en production et staging : page se rafraîchit à l'infini après login, navigation impossible, redirect en boucle vers login. Fonctionne en local.
- **Diagnostic** : Trois bugs combinés, tous liés à des différences entre l'environnement local et les serveurs VPS.
- **Bug 1 — Boucle infinie de reload (cause principale)** :
  - Le CI build bake `VITE_APP_VERSION = ${{ github.sha }}` (SHA complet, 40 chars) dans le JS frontend.
  - Le deploy script écrit `git rev-parse --short HEAD` (SHA court, 7 chars) dans `.build-version` → `APP_BUILD_VERSION` dans le `.env` VPS.
  - Le middleware `AddBuildVersion` envoie le header `x-build-version: abc1234` (7 chars).
  - L'interceptor `onResponse` compare `serverVersion` (7 chars) avec `clientVersion` (40 chars) → **mismatch permanent**.
  - Chaque réponse API set `sessionStorage['lzr:version-mismatch']`.
  - Chaque navigation : le router guard détecte le mismatch → `window.location.reload()`.
  - Après reload, premier API call → re-set mismatch → prochaine navigation → reload → **boucle infinie**.
  - **Localement** : `APP_BUILD_VERSION` absent → default `'dev'` → le check fait `return` → pas de boucle.
  - **Fix** : `.build-version` contient maintenant le SHA complet (`echo "${{ github.sha }}"` au lieu de `git rev-parse --short HEAD`). Les deux côtés (client et serveur) utilisent le même SHA 40 chars.
- **Bug 2 — CORS rejeté** :
  - `config/cors.php` avait un fallback hardcodé `'https://leezr.test'`.
  - Les `.env` VPS n'avaient pas `CORS_ALLOWED_ORIGINS` → le serveur envoyait `access-control-allow-origin: https://leezr.test` pour toutes les réponses.
  - Le navigateur sur `leezr.com` rejette les réponses API (CORS origin mismatch).
  - **Fix** : Le fallback CORS est maintenant `env('APP_URL')` au lieu du domaine local hardcodé. Chaque serveur utilise son propre `APP_URL` déjà configuré.
- **Bug 3 — 500 Route [login] not defined** :
  - Les requêtes non-JSON (curl brut) vers une route `auth:platform` ou `auth:sanctum` déclenchent le middleware `Authenticate` qui tente `route('login')`.
  - Aucune route nommée `login` n'existe (SPA API-only).
  - Le handler d'exceptions Laravel fallback sur `route('login')` même si le middleware override retourne null.
  - **Fix** : `$middleware->redirectGuestsTo('/login')` dans `bootstrap/app.php` — chemin URL au lieu de route nommée.
- **Fichiers modifiés** :
  - `.github/workflows/deploy.yml` — `.build-version` = SHA complet
  - `config/cors.php` — fallback `APP_URL` au lieu de hardcodé
  - `bootstrap/app.php` — `redirectGuestsTo('/login')`
  - `.env.production.example` — documentation CORS mise à jour
- **Conséquences** :
  - La boucle de reload infinie est éliminée — les versions client/serveur matchent
  - CORS fonctionne automatiquement sur tous les serveurs via `APP_URL`
  - Les requêtes non-JSON reçoivent un redirect 302 au lieu d'un 500
  - Aucune modification manuelle du `.env` VPS nécessaire
  - **Leçon** : tout env var qui existe côté client ET serveur doit utiliser la même source (ici `${{ github.sha }}`)

## ADR-078 : Audience Module + Maintenance Mode (R4.9)

- **Date** : 2026-02-20
- **Contexte** : Le SaaS Leezr a besoin (1) d'un système de souscription email réutilisable (mailing lists, subscribers, double opt-in) et (2) d'un mode maintenance qui bloque l'accès aux clients tout en laissant la plateforme admin accessible. La page de maintenance doit inclure un formulaire "Notify me" qui alimente le système de souscription.
- **Décision** :
  - **Audience** : Module `platform.audience` dans `app/Modules/Platform/Audience/`. 4 tables (mailing_lists, subscribers, mailing_list_subscriptions, audience_tokens). Services statiques : Subscribe (honeypot + double opt-in), Confirm (token SHA-256), Unsubscribe. Endpoints publics sous `/api/audience/*` avec throttle 10/min. Token sécurisé : raw token dans l'URL, hash SHA-256 en DB, expiration 48h.
  - **Maintenance** : Middleware `MaintenanceMode` sur le catch-all SPA uniquement (pas sur les routes API). Les routes exemptées : `platform/*`, `maintenance`, `audience/confirm`, `audience/unsubscribe`. Bypass par IP allowlist. Configuration stockée dans `platform_settings.maintenance` (JSON) via `MaintenanceSettingsPayload` (même pattern que `SessionSettingsPayload`).
  - **Page publique** : `/maintenance` = page Vue (layout: blank, public: true). Design minimal SaaS-grade : centré verticalement, fond thème (`--v-theme-background`), icône primaire, headline/subheadline/description + email subscribe. Dark mode natif. Aucun branding externe.
  - **Platform UI** : Onglet "Maintenance" dans Platform Settings. Toggle on/off, IP allowlist avec "Detect my IP", champs texte simples (headline, subheadline, description, CTA text, list slug) + live preview via `MaintenancePreview.vue`.
  - **Permissions** : `manage_maintenance` dans le module `platform.settings`, `manage_audience` dans le module `platform.audience`. Synchro via `PlatformPermissionCatalog::sync()`.
- **Refactoring R4.9.1** (2026-02-20) : Suppression du block editor (page_blocks, type-based rendering). Remplacé par 5 champs texte simples : `headline`, `subheadline`, `description`, `cta_text`, `list_slug`. Ajout de `MaintenancePreview.vue` (composant réutilisable, live preview dans settings). Page publique et preview partagent la même structure visuelle.
- **Fichiers créés** (22) :
  - 5 migrations (4 audience tables + maintenance JSON column)
  - 4 modèles Audience (MailingList, Subscriber, MailingListSubscription, AudienceToken)
  - 3 services (Subscribe, Confirm, Unsubscribe)
  - 1 controller public (AudienceController)
  - 1 module manifest (AudienceModule)
  - 1 payload VO (MaintenanceSettingsPayload — champs : enabled, allowlist_ips, headline, subheadline, description, cta_text, list_slug)
  - 1 middleware (MaintenanceMode)
  - 1 controller platform (MaintenanceSettingsController)
  - 3 pages Vue (maintenance, audience/confirm, audience/unsubscribe)
  - 1 composant settings (_SettingsMaintenance.vue)
  - 1 composant preview (MaintenancePreview.vue)
- **Fichiers modifiés** (11) :
  - ModuleRegistry, PlatformSettingsModule, PlatformSetting model, PlatformSeeder
  - routes/api.php, routes/web.php, routes/platform.php, bootstrap/app.php
  - platform.js store, [tab].vue settings page, 04-decisions.md
- **Conséquences** :
  - Le système Audience est réutilisable pour tout futur besoin de souscription (newsletter, waitlist, beta access)
  - La maintenance bloque uniquement le SPA client, pas les API (qui suivent leur auth normale 401/200)
  - La plateforme admin reste 100% accessible pendant la maintenance
  - Les emails de confirmation ne sont pas encore envoyés (infrastructure email à venir) — le message utilisateur l'indique clairement
  - Aucun CMS complexity — champs texte simples, preview live, design thème-aware

## ADR-079 : Public Theme Endpoint — Auth Pages Theme Alignment

- **Date** : 2026-02-20
- **Contexte** : Les pages d'authentification (`/login`, `/platform/login`, `/forgot-password`, `/reset-password`, `/register`) utilisent les tokens Vuetify (`text-primary`, `bg-surface`) mais chargent la couleur primaire par défaut (`#7367F0`) au lieu de la couleur configurée par l'admin dans Platform Settings. Même problème résolu pour `/maintenance` en ADR-078.
- **Décision** :
  - Endpoint public `GET /api/public/theme` → retourne `primary_color`, `primary_darken_color`, `typography` depuis `UIResolverService::forPlatform()` + `TypographyResolverService::forPlatform()`. Throttle 30/min, pas d'auth.
  - Composable `usePublicTheme()` → fetch une fois par session SPA (flag module-level), applique via `applyTheme()` + `applyTypography()`. Silent fail.
  - Ajouté à 7 pages auth + `maintenance.vue` (remplace le fetch inline de thème/typo).
- **Fichiers** : `PublicThemeController.php` (CREATE), `usePublicTheme.js` (CREATE), `routes/api.php` (MODIFY), 7 pages auth (MODIFY +1 ligne chacune), `maintenance.vue` (MODIFY simplifié).
- **Conséquences** :
  - Changer la couleur primaire dans Theme Settings → toutes les pages publiques (auth + maintenance) reflètent immédiatement le changement
  - Typography (ex: Poppins) s'applique sur toutes les pages publiques
  - Dark mode fonctionne nativement (tokens Vuetify)
  - Aucun changement de layout ou de logique d'auth

## ADR-080 : Public Landing Page at `/`

- **Date** : 2026-02-20
- **Contexte** : Le projet n'a pas de page d'accueil publique. La route `/` charge directement le dashboard authentifié. Besoin d'un point d'entrée marketing SaaS professionnel, thème-aware, responsive, dark mode.
- **Décision** :
  - Renommer `pages/index.vue` (dashboard) → `pages/dashboard.vue` (route `/dashboard`)
  - Créer nouveau `pages/index.vue` comme landing page publique (layout: blank, public: true)
  - 6 sections : Navbar sticky, Hero (gradient primaire + illustration), Features (6 cards), How It Works (3 steps), Stats (3 cards), CTA final + Footer
  - Utilise `usePublicTheme()` pour synchroniser couleur primaire + typographie depuis le serveur
  - 100% Vuetify components + tokens thème (`--v-theme-primary`, `bg-surface`, etc.)
  - Post-login redirect mis à jour : `/` → `/dashboard` dans login.vue, register.vue, safeRedirect.js, guards.js
- **Conséquences** :
  - `/` = page marketing publique, accessible à tous
  - `/dashboard` = dashboard authentifié (ancien `/`)
  - Changement de couleur primaire dans Theme Settings → landing page reflète immédiatement
  - Dark mode natif sur la landing page

## ADR-081 : Vite Hot File Protection (public/hot)

- **Date** : 2026-02-20
- **Contexte** : En production, le Blade `@vite()` chargeait les assets depuis le dev server Vite (`:5173`) au lieu de `public/build/manifest.json`. Cause : le fichier `public/hot` (créé par `pnpm dev`) persistait dans le release. Conséquence : site complètement cassé (404 sur tous les JS/CSS).
- **Décision** : Protection multi-couche :
  1. **AppServiceProvider** : `@unlink(public_path('hot'))` au boot si `APP_ENV !== local`
  2. **CI (deploy.yml)** : Validation `manifest.json` existe + `rm -f public/hot` avant artifact + `--exclude='public/hot'` dans le tar
  3. **deploy_release.sh** : `rm -f public/hot` après décompression de l'artifact
  4. **deploy.sh** (legacy) : `rm -f public/hot` après build
  5. **`.gitignore`** : `/public/hot` déjà exclu
- **Conséquences** :
  - Même si `public/hot` fuit par un vecteur imprévu, le runtime Laravel le supprime au premier boot
  - Le CI valide que le build a produit `manifest.json` — échoue si le build frontend a raté
  - Aucun impact sur le développement local (le fichier n'est supprimé qu'en non-local)

## ADR-082 : Application Versioning (APP_VERSION)

- **Date** : 2026-02-20
- **Contexte** : Besoin de traçabilité complète des builds dans la plateforme. `APP_BUILD_VERSION` est un SHA git utilisé pour le handshake frontend/backend (ADR-045c), pas adapté pour un affichage humain.
- **Décision** :
  - Le CI génère `.app-meta` contenant 4 variables : `APP_VERSION`, `APP_BUILD_NUMBER`, `APP_BUILD_DATE`, `APP_COMMIT_HASH`
  - `config/app.php` expose : `version`, `build_number`, `build_date`, `commit_hash`
  - `deploy_release.sh` lit `.app-meta` et injecte chaque clé dans `.env`
  - `PlatformAuthController` expose `app_meta` (objet) dans `/me` et `/login`
  - `UptimeService` lit `/proc/uptime` (Linux) pour le uptime serveur
  - `_SettingsGeneral.vue` affiche : Application, Version, Environment, Build #, Commit, Build Date, Uptime
  - Items sans valeur (null) sont masqués automatiquement (local = seulement Application + Version + Environment)
  - Version format : `1.0.{github.run_number}`, auto-incrémenté
  - Aucun appel git à runtime — tout vient du CI
- **Conséquences** :
  - Chaque deploy a un numéro de version unique, un build number, une date, et un commit hash
  - `APP_BUILD_VERSION` (SHA) reste séparé pour le version mismatch check
  - Localement, seuls Application/Version/Environment sont visibles (pas de metadata CI)
  - Le uptime est calculé à runtime depuis `/proc/uptime` (Linux only, null sur macOS)

## ADR-083 : BrandLogo — composant de marque réutilisable

- **Date** : 2026-02-20
- **Contexte** : Le branding "leezr." (texte + point coloré en primary) était dupliqué dans 3 endroits (landing page, maintenance page, settings) avec des styles différents. Besoin de cohérence et de réutilisabilité.
- **Décision** :
  - Créer `resources/js/components/BrandLogo.vue` — composant unique pour le branding
  - Props : `size` (`sm` / `md` / `lg`) contrôlant fontSize et taille du point
  - Couleurs via tokens Vuetify uniquement : `--v-theme-on-surface` (texte) + `--v-theme-primary` (point)
  - Zéro CSS custom, zéro hex hardcodé — suit automatiquement le thème et le dark mode
  - Auto-importé via `unplugin-vue-components` (dir `resources/js/components/`)
  - Utilisé dans : `_SettingsGeneral.vue` (lg), `index.vue` navbar (md)
  - `_SettingsGeneral.vue` : brand card centrée au-dessus des settings, avec version en `text-medium-emphasis`
  - Suppression du doublon `.brand-text` / `.brand-dot` CSS dans la landing page
- **Conséquences** :
  - Un seul composant pour toute l'identité visuelle textuelle
  - Changement de couleur primaire → branding mis à jour partout instantanément
  - Maintenance page garde son propre CSS (inline scoped, contexte différent)

## ADR-084 : Dynamic Application Name (platform_settings.general)

- **Date** : 2026-02-20
- **Contexte** : Le nom de l'application ("Leezr") était hardcodé partout dans le frontend. Besoin de le rendre éditable depuis Platform Settings pour le white-labeling futur.
- **Décision** :
  - Nouvelle colonne JSON `general` dans `platform_settings` (migration) avec clé `app_name`
  - `GeneralSettingsController` (show/update) sous `manage_theme_settings` permission
  - `app_name` exposé dans : `/me` (via `app_meta`), `/api/public/theme`, `/api/audience/maintenance-page`
  - Composable `useAppName` : ref réactive globale (`ref('Leezr')`) + `setAppName()` setter
  - Alimenté par : `usePublicTheme`, `platformAuth` store, maintenance API
  - `BrandLogo.vue` lit `useAppName()` — toujours dynamique
  - `_SettingsGeneral.vue` : champ éditable + save immédiat + mise à jour live du brand
  - Landing page (`index.vue`) : copyright + "Why businesses choose X" dynamiques
  - Maintenance page + MaintenancePreview : brand text dynamique
  - Seuls 2 fallbacks `'Leezr'` restent : `useAppName.js` (init avant API) et form init dans settings
- **Conséquences** :
  - Changement du nom dans General Settings → propagation instantanée partout (navbar, landing, maintenance, footer)
  - Pages publiques (non auth) reçoivent le nom via `/api/public/theme` — pas besoin d'être connecté
  - White-labeling possible sans modifier le code

## ADR-085 : BrandLogo dans toutes les navbars + Settings General redesign

- **Date** : 2026-02-20
- **Contexte** : L'ancien logo SVG + titre statique "Leezr" restait dans les navbars verticales et horizontales après l'ajout de `BrandLogo`. La page General Settings utilisait un formulaire classique au lieu d'un inline edit, et les métadonnées système étaient dans une VList séparée.
- **Décision** :
  - `themeConfig.js` : `logo` remplacé par `h(BrandLogo, { size: 'md' })`, `title` vidé — le SVG original n'est plus utilisé
  - Navbars horizontales (Platform + Default) : `VNodeRenderer + themeConfig.app.title` remplacés par `<BrandLogo size="md" />`
  - Navbars verticales : CSS global pour centrer le logo (`.app-title-wrapper { flex: 1; justify-content: center; margin-inline-end: 0 }`) et réduire la taille en mode collapsed (`font-size: 14px !important` via `.layout-vertical-nav-collapsed .layout-vertical-nav:not(.hovered)`)
  - `BrandLogo.vue` : ajout classes CSS `brand-logo`, `brand-text`, `brand-dot` pour ciblage CSS contextuel
  - `_SettingsGeneral.vue` : refonte en une seule VCard horizontale — brand (inline-editable) à gauche avec version + environnement, puis 4 stats (Build, Commit, Build Date, Uptime) séparées par des `VDivider vertical`. Responsive : 2 stats par ligne en mobile.
- **Conséquences** :
  - Le brand `leezr.` apparaît dans toutes les navbars (vertical, horizontal, platform, company)
  - Le logo s'adapte au collapsed sidebar (texte complet réduit à 14px, transition fluide 0.25s)
  - General Settings : vue compacte et professionnelle en une seule carte
  - L'ancien SVG logo (`@images/logo.svg`) n'est plus importé dans `themeConfig.js`

## ADR-086 : Role-Based Views in Shipment Module — Dual Entry Points

- **Date** : 2026-02-18
- **Contexte** : Le module Shipments affiche la même UI à tous les utilisateurs. Un Driver voit la même page `/shipments` qu'un Manager. BMAD P6 prescrit des pages séparées par rôle, pas des `v-if` dans la même page. Le système RBAC est mature (CompanyRole, permissions, bundles, CompanyAccess).
- **Décision** :
  - Architecture dual entry points : `/shipments` (management: view, create, assign) et `/my-deliveries` (driver: voir ses livraisons assignées, mettre à jour le statut)
  - Migration : `assigned_to_user_id` nullable FK sur `shipments` + index compound `(company_id, assigned_to_user_id, status)`
  - 2 nouvelles permissions : `shipments.assign` (assigner des expéditions) et `shipments.view_own` (voir ses livraisons assignées)
  - 1 nouveau bundle : `shipments.delivery` = `[shipments.view_own, shipments.manage_status]`
  - Bundle `shipments.operations` mis à jour : inclut désormais `shipments.assign`
  - Driver role dans JobdomainRegistry : remplace les permissions directes par le bundle `shipments.delivery`
  - Tout le nouveau code vit sous `app/Modules/Logistics/Shipments/` (MyDeliveryController, MyDeliveryReadModel, AssignShipment use case)
  - `app/Core/Models/Shipment.php` reste persistence-only (fillable + relationship, pas de logique métier)
  - Routes management protégées par `shipments.view`, routes driver par `shipments.view_own` — séparation stricte par middleware
- **Conséquences** :
  - Le Driver ne voit que "My Deliveries" dans la nav, pas "Shipments"
  - Le Manager/Dispatcher peut assigner des expéditions à des membres
  - Les routes existantes `GET /shipments` reçoivent maintenant le middleware `shipments.view` (resserrement de sécurité)
  - Pattern réutilisable pour d'autres modules avec des vues par rôle (ex: futurs modules fleet, dispatch)

---

## ADR-087 : Frontend Modular Architecture — Core Store Purification (P1-P2-P3)

- **Date** : 2026-02-21
- **Contexte** : `core/stores/` contenait 3 monolithes CRUD métier (`company.js` 350+ lignes, `platform.js` 459 lignes, `jobdomain.js` 82 lignes) mélangés avec l'infrastructure (auth, platformAuth, module). Ces stores violaient la règle de portabilité modulaire : un module supprimé ne devait pas nécessiter de toucher core/. De plus, 4 violations architecturales affectaient les layouts et la navigation (routes Vuexy fantômes, hardcoded route sets, imports directs de stores dans les layouts, shortcuts statiques).
- **Décision** :
  - **Phase P4** (fixes structurels) :
    - `NavSearchBar.vue` : remplacé les routes demo Vuexy (dashboards-crm, analytics...) par les routes platform réelles
    - `guards.js` : supprimé le Set `STRUCTURE_ROUTES` hardcodé, remplacé par `to.meta.surface === 'structure'`
    - `DefaultLayoutWithHorizontalNav.vue` : remplacé l'import direct `useModuleStore` + logique manuelle par le composable `useCompanyNav()` (aligné avec le layout vertical)
    - `NavbarShortcuts.vue` : remplacé les 6 shortcuts hardcodés par une dérivation dynamique depuis `usePlatformNav()`
  - **Phase P1** (extract company.js) :
    - `core/stores/company.js` éclaté en 2 stores modulaires :
      - `modules/company/members/members.store.js` (Pinia ID: `companyMembers`) — members CRUD, field activations/definitions
      - `modules/company/settings/settings.store.js` (Pinia ID: `companySettings`) — company info, roles, permissions
    - 6 pages company migrées, `company.js` supprimé de core
  - **Phase P2** (extract platform.js) :
    - `core/stores/platform.js` (459 lignes) éclaté en 6 stores modulaires :
      - `modules/platform-admin/companies/companies.store.js` (Pinia ID: `platformCompanies`)
      - `modules/platform-admin/users/users.store.js` (Pinia ID: `platformUsers`)
      - `modules/platform-admin/roles/roles.store.js` (Pinia ID: `platformRoles`)
      - `modules/platform-admin/fields/fields.store.js` (Pinia ID: `platformFields`)
      - `modules/platform-admin/jobdomains/jobdomains.store.js` (Pinia ID: `platformJobdomains`)
      - `modules/platform-admin/settings/settings.store.js` (Pinia ID: `platformSettings`) — modules, theme, session, typography, maintenance
    - 14 pages platform migrées, `platform.js` supprimé de core
  - **Phase P3** (extract jobdomain.js) :
    - `core/stores/jobdomain.js` relocalisé vers `modules/company/jobdomain/jobdomain.store.js` (company-scoped : utilise `$api`, endpoint `/company/jobdomain`)
    - 4 importers migrés (2 pages company, runtime.js, devtools), `jobdomain.js` supprimé de core
  - **Résultat final** : `core/stores/` ne contient plus que l'infrastructure :
    - `auth.js` — session auth company
    - `platformAuth.js` — session auth platform
    - `module.js` — registre des modules activés
- **Conséquences** :
  - `core/` est désormais infrastructure-only — aucun CRUD métier
  - Tout module métier peut être supprimé sans toucher core/
  - Convention établie : `modules/<scope>/<domain>/<domain>.store.js` avec `*.store.js` naming
  - Les layouts et navigation utilisent exclusivement des composables (`useCompanyNav`, `usePlatformNav`) — plus d'imports de stores métier dans les layouts
  - Les guards utilisent `to.meta.surface` au lieu de Sets hardcodés — extensible sans modification de code
  - 30+ fichiers modifiés, 3 stores monolithiques supprimés, 9 stores modulaires créés, 0 régressions build

---

## ADR-088 : Frontend Modular Governance Standard

- **Date** : 2026-02-21
- **Contexte** : Suite aux refactors P1–P3 (ADR-087), `core/` est désormais infrastructure-only et tout le CRUD métier vit dans `modules/`. Cependant, sans règles formelles, le risque de régression architecturale est élevé : un développeur pressé pourrait ajouter un store métier dans `core/`, hardcoder des routes dans un layout, ou créer des dépendances croisées entre modules. Ce ADR formalise les règles de gouvernance permanentes pour garantir l'isolation modulaire, la portabilité des modules, et la préparation marketplace.
- **Décision** : Les règles suivantes sont permanentes et non-négociables.

  **R1 — `core/` est infrastructure-only**
  - Contenu autorisé : auth, session, module registry, runtime utilities, broadcast, cache, state machine
  - Contenu interdit : CRUD métier, feature stores, state spécifique à un module
  - Fichiers autorisés dans `core/stores/` : `auth.js`, `platformAuth.js`, `module.js` — aucun autre
  - Tout ajout de store dans `core/` est une violation architecturale

  **R2 — Tout le métier vit dans `modules/`**
  - Stores Pinia (`*.store.js`)
  - Composables métier (feature composables)
  - Composants UI spécifiques au domaine (optionnel)
  - Logique runtime spécifique au module (optionnel)
  - Structure standard : `modules/<scope>/<domain>/<domain>.store.js`
  - Scopes existants : `company/`, `platform-admin/`, `logistics-shipments/`

  **R3 — Direction des dépendances (unidirectionnelle)**
  - `pages/ → modules/ → core/` — jamais l'inverse
  - `modules/` ne doit PAS importer d'autres modules sauf si explicitement documenté dans un ADR
  - `core/` ne doit JAMAIS importer `modules/` (exception : `runtime.js` pour l'hydration, documentée dans ADR-087)
  - Les layouts ne doivent contenir aucune logique métier — uniquement des composables de navigation (`useCompanyNav`, `usePlatformNav`)

  **R4 — Convention de nommage des stores**
  - Tous les stores modulaires utilisent le suffixe `*.store.js`
  - Pas de nom générique (`store.js`) dans `core/`
  - Pas de store métier dans `core/`
  - Pinia ID : `<scope><Domain>` (ex: `companyMembers`, `platformUsers`, `platformSettings`)
  - Export : `use<Scope><Domain>Store` (ex: `useMembersStore`, `usePlatformCompaniesStore`)

  **R5 — Isolation des modules**
  - Un module doit pouvoir être supprimé sans modifier `core/`
  - Un module ne doit pas dépendre d'effets de bord cachés d'un autre module
  - L'accès cross-module nécessite une frontière API explicite (composable partagé ou event bus)
  - Chaque module est autonome : son store, ses pages, ses composables

  **R6 — Gouvernance de la navigation**
  - La navigation doit être module-driven (déclarée par les modules via `navItems` dans leur `ModuleClass`)
  - Aucune route métier hardcodée dans les layouts, shortcuts, ou search bars
  - Aucun résidu demo Vuexy dans la navigation
  - La visibilité des items doit être basée sur les permissions ou l'activation des modules
  - Les guards utilisent `to.meta.surface` et `to.meta.module` — pas de Sets hardcodés

  **R7 — Séparation des scopes**
  - Les modules `company/` et `platform-admin/` sont des bounded contexts séparés
  - Ils ne partagent pas d'état directement (pas d'import croisé entre scopes)
  - Les préoccupations partagées vivent dans `core/` (auth, runtime, module registry)
  - API clients séparés : `$api` (company scope) vs `$platformApi` (platform scope)

- **Conséquences** :
  - Architecture isolée et portable — chaque module est auto-suffisant
  - Discipline architecturale accrue — plus de raccourcis vers `core/`
  - Légèrement plus de boilerplate (un store par domaine au lieu d'un monolithe)
  - Structure marketplace-ready — un module peut être activé/désactivé sans impact sur le reste
  - Les revues de code doivent vérifier les frontières modulaires

- **Enforcement** :
  - Tout store métier ajouté dans `core/` est une régression architecturale et doit être refusé
  - Tout import cross-module doit être justifié dans un ADR dédié
  - Les nouveaux modules doivent suivre la structure `modules/<scope>/<domain>/<domain>.store.js`
  - Les layouts et guards ne doivent jamais référencer directement un store métier

---

## ADR-089 : Module Entitlement System — Plan + Jobdomain + Dependencies

- **Date** : 2026-02-21
- **Contexte** : Le système de modules (ADR-021/062) a une infrastructure mature : `ModuleManifest` VO, `ModuleRegistry` agrégateur, `PlatformModule`/`CompanyModule` couches DB, `ModuleGate`, et middleware `CompanyAccess`. Les modules déclarent `type: 'core'|'addon'` dans leur manifest, mais **ce champ était idle** — aucun code ne l'appliquait. Problèmes : (1) `CompanyModuleController::enable()` ne vérifie que `isEnabledGlobally()` — tout admin peut activer n'importe quel module, (2) les modules core peuvent être désactivés, (3) pas de gating par plan, (4) pas de résolution de dépendances, (5) pas de compatibilité jobdomain, (6) pas de visibilité entitlement côté frontend.

- **Décision** :

  **1. Plan Abstraction — `PlanRegistry`**
  - Registre statique déclaratif (comme `JobdomainRegistry`) avec niveaux numériques : `starter (0)`, `pro (10)`, `business (20)`
  - Code-defined, pas en DB — le billing est différé (ADR-011)
  - Migration : `companies.plan_key` (string, default 'starter')
  - `PlanRegistry::meetsRequirement(companyPlan, requiredPlan)` — comparaison de niveaux

  **2. Entitlement = Computed (pas de table)**
  - `EntitlementResolver::check(Company, moduleKey)` — résolution en 4 gates :
    1. **Core gate** — `type === 'core'` → toujours entitled, ne peut pas être désactivé
    2. **Plan gate** — `minPlan` défini ET plan company insuffisant → rejeté
    3. **Compat gate** — `compatibleJobdomains` défini ET jobdomain company pas dans la liste → rejeté
    4. **Source gate** — module dans `default_modules` du jobdomain → entitled via jobdomain
  - Retourne `{entitled, source, reason}` — pas de table d'entitlements

  **3. Dependencies — `DependencyResolver`**
  - `ModuleManifest` enrichi avec `requires[]` (module keys), `minPlan` (nullable), `compatibleJobdomains` (nullable)
  - `DependencyResolver::canActivate()` — vérifie que tous les modules requis sont actifs
  - `DependencyResolver::canDeactivate()` — vérifie qu'aucun module actif ne dépend de celui-ci

  **4. Controller Wiring**
  - `enable()` : vérifie `isEnabledGlobally` → entitlement → dependencies → activation
  - `disable()` : vérifie core protection → dependencies → désactivation
  - Messages d'erreur explicites : 'plan_required', 'incompatible_jobdomain', 'not_available'

  **5. API Enrichment — `ModuleCatalogReadModel`**
  - Ajoute `type`, `is_entitled`, `entitlement_source`, `entitlement_reason`, `requires`, `min_plan`

  **6. Frontend — modules.vue entitlement-aware**
  - Core → badge "Core", toggle toujours on, désactivé
  - Entitled → toggle actif, badge "Included"
  - Non-entitled → grayed out, badge "Requires Pro" ou "Not available"

- **Conséquences** :
  - Les modules core ne peuvent plus être désactivés
  - Les modules addon nécessitent un entitlement (jobdomain ou futur achat)
  - Le gating par plan fonctionne dès maintenant (même sans billing)
  - Zéro nouvelle table — entitlement calculé dynamiquement
  - Prêt pour marketplace : `minPlan`, `compatibleJobdomains`, `requires` déjà dans le contrat
  - Les modules existants (Members, Settings, Shipments) ne changent pas — aucun `minPlan` défini

## ADR-090 : Platform Plan Management — Minimal Wiring

- **Date** : 2026-02-21
- **Contexte** : ADR-089 a introduit `PlanRegistry` et `companies.plan_key` pour le gating des modules par plan. Mais aucune interface admin ne permettait de voir ou modifier le plan d'une company. Les admins plateforme devaient intervenir en base de données.
- **Décision** : Câbler le `PlanRegistry` existant à l'UI admin plateforme avec un périmètre minimal :
  1. `GET /api/platform/plans` — expose les définitions du `PlanRegistry` (read-only)
  2. `PUT /api/platform/companies/{id}/plan` — modifie `plan_key` avec validation `Rule::in(PlanRegistry::keys())`
  3. Colonne "Plan" dans la page companies avec `VSelect` inline pour changement direct
  4. Pas de billing, pas de Stripe, pas de changement d'entitlements — le plan modifie `plan_key` et l'`EntitlementResolver` le lit en temps réel
- **Conséquences** : Les admins plateforme peuvent assigner un plan à toute company via l'UI. Le changement est immédiat — les modules gated par `minPlan` deviennent accessibles ou inaccessibles instantanément. Prêt pour le futur billing (ADR-011) qui automatisera les changements de plan.

## ADR-091 : Billing Abstraction Layer — Interface Only

- **Date** : 2026-02-21
- **Contexte** : ADR-089/090 ont câblé `PlanRegistry` et l'assignation de plan via l'UI admin. L'étape suivante est de préparer le raccordement à un service de billing externe (Stripe), tout en respectant ADR-011 qui diffère explicitement le billing réel. Objectif : poser la couture (seam) architecturale pour que le jour où Stripe est activé, on swap un driver — le reste de l'app ne change pas.
- **Décision** : Introduire une **couche d'abstraction billing** minimale, sans aucune implémentation réelle :
  1. **`BillingProvider`** — interface/contrat avec 5 méthodes : `ensureCustomer`, `changePlan`, `cancelSubscription`, `billingPortalUrl`, `handleWebhook`
  2. **`NullBillingProvider`** — driver par défaut, `changePlan()` écrit `plan_key` en DB directement (comportement actuel), le reste est no-op
  3. **`StripeBillingProvider`** — stub, chaque méthode throw `RuntimeException('Stripe billing not implemented — see ADR-011')`, isole le futur SDK Stripe dans un seul fichier
  4. **`BillingManager`** — étend `Illuminate\Support\Manager`, résout le driver via `config('billing.driver')` (default `'null'`)
  5. **`config/billing.php`** — driver + clés Stripe isolées (`BILLING_DRIVER`, `STRIPE_KEY`, `STRIPE_SECRET`, `STRIPE_WEBHOOK_SECRET`)
  6. **Binding** — `BillingProvider` → `BillingManager::driver()` dans `AppServiceProvider`
  7. **Webhook route** — `POST /api/webhooks/billing` (public, throttled), délègue au `BillingProvider::handleWebhook()`
- **Conséquences** :
  - Aucune dépendance Stripe ajoutée, aucune table de subscription, aucun checkout, aucune UI billing
  - Le code existant (`PlanRegistry`, `EntitlementResolver`, `CompanyController::updatePlan`) n'est pas modifié
  - Pour activer Stripe : installer `stripe/stripe-php`, implémenter `StripeBillingProvider`, setter `BILLING_DRIVER=stripe`
  - Le `NullBillingProvider` reste le driver de production jusqu'à ce que le billing soit prêt

## ADR-092 : Platform Company Profile (V1, NO billing)

- **Date** : 2026-02-21
- **Contexte** : Les admins plateforme peuvent lister les companies et changer plan/status inline, mais il n'existe pas de page de détail. De plus, la logique enable/disable de modules est dupliquée inline dans `CompanyModuleController` côté company, sans possibilité de réutilisation côté plateforme.
- **Décision** :
  1. **Service extraction** — Extraire la logique enable/disable dans `CompanyModuleService` (Core) pour partage entre company-side et platform-side controllers. Le service retourne un résultat structuré `{success, status, data}`, les controllers restent thin wrappers (logging + JsonResponse).
  2. **Profile endpoint** — `GET /api/platform/companies/{id}` retourne company (with jobdomains + memberships_count), plan (from PlanRegistry), et modules (from ModuleCatalogReadModel).
  3. **Platform module toggle** — `PUT /api/platform/companies/{id}/modules/{key}/enable|disable` via un nouveau `CompanyModuleController` plateforme qui utilise le service partagé.
  4. **Frontend** — Page profil company avec 2 onglets (Overview + Modules). Overview : info read-only, plan VSelect, suspend/reactivate. Modules : liste avec VSwitch (disabled pour core + non-entitled).
  5. **Directory pattern** — `companies.vue` migré vers `companies/index.vue` (cohérence avec `jobdomains/`, `users/`), bouton "View" ajouté dans la liste.
- **Conséquences** :
  - Zéro duplication de logique module — un seul service, deux controllers
  - `ModuleCatalogReadModel::forCompany()` confirmé context-independent (pas de `auth()`, pas de `request()`)
  - Les admins plateforme peuvent voir et gérer les modules de chaque company
  - Pas de billing, pas de Stripe — `plan_key` reste un simple champ DB
  - Prêt pour extension future (onglets supplémentaires : members, activity, etc.)

## ADR-093 : Module Catalog UX (Clickable + Configurable)

- **Date** : 2026-02-21
- **Contexte** : Les modules existent en tant qu'entrées de liste, mais il n'y a pas de page de profil module ni de page de configuration côté company. Les noms de modules ne sont pas cliquables dans les onglets Modules des jobdomains et companies. L'index platform/modules n'offre ni filtres ni colonnes enrichies.
- **Décision** :
  1. **Platform Module Profile** — `GET /api/platform/modules/{key}` retourne manifest complet, platformModule, dependents (reverse lookup), companies utilisant le module, jobdomains compatibles, et jobdomains incluant le module par défaut. Page frontend avec 4 onglets : Overview, Compatibility, Dependencies, Companies.
  2. **Enhanced Platform Modules Listing** — Index enrichi avec colonnes Type, Min Plan, Jobdomains. Filtres par type (core/addon), plan minimum (none/pro/business), et statut global (enabled/disabled). Lignes cliquables vers le profil module. Toggle VSwitch isolé avec `@click.stop`.
  3. **Company Module Settings** — `GET/PUT /api/company/modules/{key}/settings` exploitant la colonne `config_json` existante dans `company_modules`. Page company `/modules/{key}` avec éditeur JSON (AppTextarea monospace), validation JSON côté client, save/reset.
  4. **Clickable Module Names** — Dans l'onglet Modules des jobdomains (`platform/jobdomains/[id].vue`) et du profil company (`platform/companies/[id].vue`), les noms de modules sont des RouterLink vers `platform-modules-key`.
  5. **Directory pattern** — `platform/modules.vue` migré vers `platform/modules/index.vue`, `company/modules.vue` migré vers `company/modules/index.vue` (cohérence avec le pattern `[key].vue`).
- **Conséquences** :
  - Chaque module a une page de profil navigable depuis n'importe quel listing
  - Le graph de dépendances est visible (requires + dependents) avec liens inter-modules
  - Les admins voient quelles companies utilisent un module donné
  - Les companies peuvent configurer leurs modules actifs via JSON settings
  - `config_json` (colonne existante) est exploitée — aucune migration nécessaire
  - Les filtres sur l'index permettent une navigation rapide dans un catalogue croissant

## ADR-094 : Module Product Editor (Structured Pricing + Editable Platform Config)

- **Date** : 2026-02-22
- **Contexte** : La page profil module platform (`/platform/modules/{key}`) est en lecture seule — toutes les données proviennent du manifest (code). Les admins plateforme n'ont aucun moyen de configurer les métadonnées commerciales (pricing, listing, schema settings) sans modifier le code. Un éditeur JSON brut ne suffit pas pour opérer un catalogue de centaines de modules.
- **Décision** :
  1. **Extend `platform_modules`** — 7 colonnes ajoutées : `is_listed` (bool), `is_sellable` (bool), `pricing_model` (string nullable), `pricing_metric` (string nullable), `pricing_params` (JSON), `settings_schema` (JSON), `notes` (text). String columns + `Rule::in()` validation (pas de DB enum). `ModuleRegistry::sync()` ne touche que key/name/description — colonnes commerciales safe.
  2. **`PUT /api/platform/modules/{key}/config`** — endpoint de mise à jour avec validation field-scoped via `Validator::make()` pour `pricing_params` selon `pricing_model` (flat→price_monthly, plan_flat→starter/pro/business, per_seat→included+overage, usage→unit_price, tiered→tiers array). Retourne 422 avec erreurs par champ.
  3. **`GET /api/platform/modules/{key}`** — enrichi avec clé `platform_config` séparant manifest read-only (tech) et config DB editable (commercial).
  4. **Module Product Editor** — Page redessinée en 2 colonnes (md=8 + md=4), inspirée du preset `ecommerce/product/add`. Colonne gauche : Module Summary (read-only), Pricing Editor (formulaire structuré avec champs conditionnels par pricing_model : number inputs, table plan, repeater tiers), Preview box, Expert mode (JSON toggle), Companies panel (lazy-loaded, VExpansionPanel). Colonne droite : Commercial (switches Listed/Sellable + Notes), Organize (compatibility, included by, dependencies — read-only chips).
  5. **Dirty state** — Save button disabled quand clean, `variant="elevated"` quand dirty. Snapshot JSON pour détection.
  6. **Seed** — DevSeeder enrichi avec pricing configs pour 3 modules logistique : tracking (flat/$29), fleet (per_seat/included+overage), analytics (plan_flat/$49-29-19).
- **Contraintes** : Pas de billing, pas de Stripe, pas de changement EntitlementResolver. Manifests = tech metadata, DB = commercial metadata.
- **Fichiers** : 1 CREATE (migration), 7 MODIFY (PlatformModule, ModuleController, routes/platform, DevSeeder, settings.store.js, [key].vue, 04-decisions.md)
- **Conséquences** :
  - Les admins plateforme configurent le pricing et le listing sans toucher au code ni au JSON brut
  - Le formulaire structuré adapte ses champs au pricing_model sélectionné (flat, plan_flat, per_seat, usage, tiered)
  - La preview affiche le pricing calculé par plan — feedback immédiat sans billing
  - L'expert mode offre un fallback JSON pour cas avancés
  - `sync()` et colonnes commerciales coexistent sans conflit
  - Prêt pour futur billing engine qui consommera `pricing_model`/`pricing_params`

## ADR-095 : Pricing UX Clarification + Governance Completion

- **Date** : 2026-02-22
- **Contexte** : ADR-094 a livré un éditeur structuré fonctionnel mais utilisant du jargon technique (`plan_flat`, `pricing_metric`, `per_seat`) incompréhensible pour un ops non-dev. Aucune distinction explicite entre module inclus dans l'abonnement, add-on payant, ou interne. Les prix affichés ne clarifient pas qu'ils sont additionnels au forfait de base.
- **Décision** :
  1. **`pricing_mode` field** — Nouvelle colonne `pricing_mode` (string nullable) dans `platform_modules`. Trois valeurs : `included` (inclus dans l'abonnement), `addon` (coût mensuel additionnel), `internal` (non commercial). Sélecteur "Commercial Mode" en haut de l'éditeur pricing avec textes d'aide contextuels.
  2. **Labels user-friendly** — Tous les labels techniques remplacés : `flat` → "Fixed price (same for all plans)", `plan_flat` → "Price varies by plan", `per_seat` → "Per active user", `usage` → "Usage-based", `tiered` → "Tiered pricing". "Pricing Model" renommé "Pricing Structure", "Pricing Metric" renommé "Pricing Unit".
  3. **Prix additionnels explicites** — Tous les prix en mode addon préfixés par "+" dans la preview (`+$29/mo`). Labels précisent "Additional monthly price" pour éviter toute confusion avec le prix de base du plan.
  4. **Pricing Unit intelligent** — Sélecteur affiché uniquement pour usage/tiered (les seuls modèles où la métrique est configurable). Pour flat/plan_flat → auto `none`, pour per_seat → auto `users`. Items avec descriptions humaines ("Per shipment", "Per SMS sent", etc.).
  5. **Consistency enforcement** — Backend : si `pricing_mode ≠ addon`, pricing_model/metric/params mis à null. Frontend : watcher sur pricing_mode qui nettoie les champs pricing. Watcher sur pricing_model qui auto-corrige la métrique.
  6. **Revenue Impact Preview** — Preview contextuelle par mode : `included` → VAlert info "Included in subscription plan — no additional charge", `internal` → VAlert warning "does not generate additional revenue", `addon` → VAlert success avec prix par plan préfixés "+".
  7. **Seed** — DevSeeder enrichi avec `pricing_mode: 'addon'` pour les 3 modules logistique.
- **Contraintes** : Pas de billing, pas de Stripe, pas de changement EntitlementResolver. Pas de nouveau modèle ni de nouvelle table — une seule colonne ajoutée.
- **Fichiers** : 1 CREATE (migration pricing_mode), 4 MODIFY (PlatformModule, ModuleController, DevSeeder, [key].vue, 04-decisions.md)
- **Conséquences** :
  - Un ops non-dev comprend immédiatement si un module génère du revenu additionnel ou non
  - Les 3 modes commerciaux sont explicites et gouvernent la visibilité des champs pricing
  - La preview affiche l'impact financier réel avec notation "+$" sans ambiguïté
  - Le backend garantit la cohérence (pas de pricing orphelin si mode ≠ addon)
  - Prêt pour futur billing engine qui filtrera sur `pricing_mode = 'addon'`

## ADR-098 : Module Packaging Architecture (Self-Contained + Exportable Modules)

- **Date** : 2026-02-22
- **Contexte** : Le système de modules utilise une liste hardcodée de 16 classes dans `ModuleRegistry::$modules`. Les migrations sont centralisées dans `database/migrations/`, les routes inline dans `routes/company.php` et `routes/platform.php`, aucun fichier metadata, aucun asset icône. À l'échelle de centaines de modules, ce modèle ne tient pas.
- **Décision** :
  1. **Autodiscovery robuste** — `ModuleRegistry` remplace la liste hardcodée par un scan récursif de `app/Modules/` via `RecursiveDirectoryIterator`. Cherche toutes les classes `*Module.php`, vérifie `class_exists()` + `is_subclass_of(ModuleDefinition::class)` + non abstraite. Cache en `static::$discovered`. Nouvelles méthodes : `modulePath(string $key)` (chemin disque du module) et `moduleClass(string $key)` (FQCN).
  2. **ModuleManifest — icon support** — Deux nouveaux paramètres : `iconType` (`'tabler'|'image'`, default `'tabler'`) et `iconRef` (string, default `'tabler-puzzle'`). Si `iconType='image'`, le fichier SVG doit être dans `{module}/resources/{iconRef}`.
  3. **Export/Import CLI** — `php artisan module:export {key}` produit `storage/modules/{key}.json` contenant : `manifest` (read-only), `permissions`, `bundles`, `platform_module` (toute la config DB éditable : pricing, overrides, listing), `metadata` (version, exported_at), et `icon_data` (base64 SVG si image). `php artisan module:import {path}` : valide le JSON, vérifie que le module PHP existe, sync le registre, applique `platform_module` via `update()`, écrit le SVG si icon image. DB sync only — ne crée pas de code PHP.
  4. **Override columns** — 4 colonnes ajoutées à `platform_modules` : `display_name_override`, `description_override`, `min_plan_override`, `sort_order_override`. Exportées/importées avec la config commerciale.
  5. **Module-local migrations + routes** — Infrastructure dans `AppServiceProvider::loadModuleAssets()` : charge automatiquement `{module}/database/migrations/` et `{module}/routes/{company,platform}.php` s'ils existent. Pattern pour nouveaux modules uniquement — l'existant reste centralisé.
  6. **Convention module.json** — Fichier optionnel `module.json` dans le dossier module pour metadata (version, author). L'export lit la version depuis ce fichier s'il existe.
  7. **Convention de structure** — Chaque module dans `app/Modules/{Domain}/{ModuleName}/` avec : `{Name}Module.php` (requis), `module.json` (optionnel), `Http/`, `Services/`, `ReadModels/`, `UseCases/`, `database/migrations/`, `resources/`, `routes/`, `README.md`.
- **Contraintes** : Pas de changement EntitlementResolver. Pas de changement billing. Pattern new-only pour migrations/routes. Import = DB sync only.
- **Fichiers** : 3 CREATE (ModuleExportCommand, ModuleImportCommand, module.json example), 4 MODIFY (ModuleManifest, ModuleRegistry, PlatformModule + migration, AppServiceProvider)
- **Conséquences** :
  - Ajouter un module = créer une classe dans `app/Modules/` — plus besoin de toucher `ModuleRegistry`
  - Un module est exportable/importable comme un package JSON autonome
  - La config commerciale (pricing, overrides, listing) voyage avec le module
  - Les nouveaux modules peuvent colocaliser migrations et routes
  - Le registre ne dépend jamais de la DB pour la structure — manifests = source de vérité technique
  - Prêt pour marketplace future (import depuis catalogue externe)

## ADR-097 : Module Visual Identity + Governance Completion (Overrides + Sidebar + Icons + Pricing Preview)

- **Date** : 2026-02-22
- **Contexte** : Le système de modules ne possède pas d'identité visuelle. Les modules sont affichés avec l'icône générique `tabler-puzzle` partout. Les champs d'override (display_name_override, description_override, min_plan_override, sort_order_override) créés par ADR-098 ne sont pas exposés dans l'UI d'édition. La page profil module ([key].vue) n'affiche pas les permissions et bundles en détail. Le pricing preview n'a pas de contexte visuel (pas d'icône par pricing_model, pas de couleur sémantique par pricing_mode).
- **Décision** :
  1. **Colonnes icon DB** — Ajout de `icon_type` (varchar nullable) et `icon_name` (varchar nullable) à `platform_modules`. Override chain : DB → manifest → `'tabler-puzzle'` fallback. PlatformModule fillable mis à jour.
  2. **Override merge API** — Toutes les API (ModuleController index/show, ModuleCatalogReadModel, JobdomainController show) appliquent `override ?? manifest` pour name, description, min_plan, sort_order. Tri par sort_order effectif. `show()` retourne `manifest_defaults` séparé + `permissions`/`bundles` complets dans `module`.
  3. **updateConfig enrichi** — Accepte les champs override (display_name_override, description_override, min_plan_override, sort_order_override) + icon (icon_type, icon_name) avec validation.
  4. **UI — Module Identity** — Carte "Module Summary" renommée "Module Identity". 4 champs éditables avec persistent-hint montrant le manifest default. Champs techniques (type, surface, key) restent disabled. Section icon (type select + name text).
  5. **UI — Sidebar Permissions + Bundles** — Deux cartes read-only dans la colonne droite : Permissions (chips par permission.label) et Capability Bundles (chips colorés warning si is_admin, info sinon, avec hint text).
  6. **UI — Icons dans les listings** — VAvatar 32px avec icône dynamique (`item.icon_name`) devant le nom du module dans : platform/modules/index.vue, company/modules/index.vue, et VAvatar 48px dans le header profil [key].vue.
  7. **UI — Pricing preview visuel** — Icône contextuelle par pricing_model (flat=currency-dollar, plan_flat=layers-linked, per_seat=users, usage=activity, tiered=chart-bar). Couleur sémantique par pricing_mode (included=primary, addon=success, internal=warning). Chip pricing_mode avec préfixe "+" pour addon dans le titre Pricing.
  8. **DevSeeder cohérent** — seedModuleConfigs() enrichi pour les 3 modules logistique avec display_name_override, description_override, icon_type='tabler', icon_name spécifique (tabler-map-pin, tabler-truck, tabler-chart-bar).
  9. **Dirty state étendu** — currentPayload inclut les 6 nouveaux champs (4 overrides + 2 icons). Save payload et re-hydration mis à jour. Pas de régression sur le comportement existant.
- **Contraintes** : Pas de changement EntitlementResolver. Pas de billing. Metadata technique (type, scope, surface, key, requires) reste read-only.
- **Fichiers** : 1 CREATE (migration icon columns), 6 MODIFY (PlatformModule, ModuleController, ModuleCatalogReadModel, JobdomainController, DevSeeder, platform/modules/[key].vue, platform/modules/index.vue, company/modules/index.vue, 04-decisions.md)
- **Conséquences** :
  - Chaque module a une identité visuelle propre (icône + nom commercial distinct du code)
  - Un ops peut personnaliser nom, description, min_plan, sort_order et icône sans toucher au code
  - Les permissions et bundles sont visibles directement dans le profil module (pas besoin de regarder le code)
  - Le pricing preview donne un feedback visuel immédiat du mode commercial et de la structure de prix
  - L'override chain (DB → manifest → fallback) est cohérent sur toute la stack (API + frontend)

---

## ADR-099 : Bugfix — Overlay bloqué après navigation (écran grisé)

- **Date** : 2026-02-22
- **Contexte** : Le `.layout-overlay` (scrim 60% noir + `pointer-events: auto`) de `@layouts/components/VerticalNavLayout.vue` peut rester bloqué en état visible, rendant l'application inutilisable (écran grisé, clics bloqués). Symptôme : refresh KO, nouvel onglet OK. Trois bugs cumulatifs identifiés par audit :
  1. Le nettoyage `afterEach` ne s'exécutait que sur desktop (`window.innerWidth >= 1280`) — aucun nettoyage sur mobile/tablette
  2. Le nettoyage DOM (`classList.remove('visible')`) est inefficace car Vue réinjecte la classe au prochain render tick (les refs réactives `isOverlayNavActive`/`isLayoutOverlayVisible` restent `true`)
  3. Aucun timeout failsafe — une fois bloqué, l'overlay reste permanent
- **Décision** :
  1. **afterEach universel** — Suppression de la restriction `>= 1280px`. Le nettoyage s'applique à TOUS les breakpoints. Le watcher `VerticalNav` gère déjà la fermeture intentionnelle au changement de route, le afterEach est un filet de sécurité.
  2. **`.click()` au lieu de `classList.remove()`** — Appel de `el.click()` sur `.layout-overlay.visible` déclenche le handler Vue natif `@click="() => { isOverlayNavActive = false; isLayoutOverlayVisible = false }"`, réinitialisant correctement les deux refs réactives.
  3. **Failsafe 10s** — `setInterval(2s)` dans `DefaultLayoutWithVerticalNav.vue` détecte un overlay visible depuis >10 secondes consécutives et le dismiss automatiquement via `.click()`.
- **Contraintes** : `@core/` et `@layouts/` NON modifiés (politique UI Vuexy). Pas de changements backend. Pas de fichiers créés.
- **Fichiers** : 3 MODIFY (`plugins/1.router/guards.js`, `main.js`, `layouts/components/DefaultLayoutWithVerticalNav.vue`)
- **Conséquences** :
  - Un overlay bloqué est désormais impossible : nettoyé au changement de route (afterEach) + auto-dismissé après 10s (failsafe)
  - La technique `.click()` contourne la limitation de ne pas pouvoir modifier `@layouts/` en déléguant au handler Vue existant
  - Coût CPU négligeable (un `querySelector` toutes les 2s)

---

## ADR-100 : Commercial Layer Completion (Plans + Pricing + UX)

- **Date** : 2026-02-22
- **Contexte** : Le système de plans (`PlanRegistry` avec starter/pro/business), le billing abstrait (`BillingProvider` + `NullBillingProvider`), et le module entitlement (`EntitlementResolver`) existent mais la **couche de présentation commerciale** manque : pas de pricing public, pas de choix de plan à l'inscription, pas de page plan côté company, pas de plan_key exposé dans `myCompanies()`.
- **Décision** :
  1. **PlanRegistry enrichi** — Ajout de `price_monthly`, `price_yearly`, `is_popular`, `feature_labels`, `limits` à chaque plan. Méthode `publicCatalog()` pour API publique.
  2. **API publique pricing** — `GET /api/public/plans` (plans + jobdomains actifs) et `GET /api/public/plans/preview` (modules disponibles pour un couple jobdomain+plan, miroir des 4 gates d'EntitlementResolver sans Company).
  3. **Registration wizard 3 étapes** — Jobdomain → Plan → Account. `register.vue` transformé en stepper Vuexy. `RegisterRequest` accepte `jobdomain_key` + `plan_key` optionnels. `AuthController::register()` utilise `JobdomainGate::assignToCompany()` si jobdomain fourni.
  4. **plan_key dans myCompanies()** — Chaque company retournée inclut `plan_key`.
  5. **Page /company/plan** — Plan actuel + comparaison des 3 plans + CTA upgrade/downgrade via `BillingProvider::changePlan()`. Surface `structure` (management only).
  6. **Endpoint PUT /api/company/plan** — `CompanyPlanController` valide le `plan_key` et délègue à `BillingProvider` (NullBillingProvider écrit directement en DB).
  7. **Dashboard widget** — `_PlanBadgeWidget.vue` affiche le plan actuel + lien upgrade.
  8. **Page /platform/plans** — Vue lecture seule des plans pour les admins platform.
  9. **Navigation** — Item "Plan" ajouté au menu vertical (surface: structure).
- **Contraintes** : EntitlementResolver NON modifié. Billing engine NON modifié. `@core/` et `@layouts/` NON modifiés. Presets Vuexy respectés (AppPricing, AccountSettingsBillingAndPlans, register-multi-steps, CustomRadiosWithIcon).
- **Fichiers** : 6 CREATE, 10 MODIFY
  - Backend : `PlanRegistry.php` (MODIFY), `PublicPlanController.php` (CREATE), `api.php` (MODIFY), `AuthController.php` (MODIFY), `RegisterRequest.php` (MODIFY), `CompanyPlanController.php` (CREATE), `company.php` (MODIFY)
  - Frontend : `usePublicPlans.js` (CREATE), `register.vue` (MODIFY), `auth.js` (MODIFY), `plan.vue` (CREATE), `platform/plans.vue` (CREATE), `_PlanBadgeWidget.vue` (CREATE), `_DashboardManagement.vue` (MODIFY), `navigation/vertical/index.js` (MODIFY)
- **Conséquences** :
  - Tout visiteur peut voir les plans et pricing sans authentification
  - L'inscription capture jobdomain + plan dès le départ (wizard guidé)
  - Les admins company voient leur plan et peuvent changer via BillingProvider
  - Le plan_key est disponible côté frontend pour tout affichage conditionnel futur
  - Prêt pour Stripe : il suffit de brancher `StripeBillingProvider` (ADR-011)

---

## ADR-101 : Plan Domain Refactor + Payment Gateway Architecture

- **Date** : 2026-02-21
- **Contexte** : Les plans étaient codés en dur dans `PlanRegistry::definitions()`. Pas de lifecycle de paiement : `NullBillingProvider::changePlan()` écrit directement `plan_key` en DB sans audit, sans état pending, sans abstraction checkout.
- **Décision** :
  1. **Plans DB-driven** — Table `plans` avec prix en cents. `PlanRegistry` refactoré en cache DB (pattern ModuleRegistry). `seedDefaults()` pour le seeding, `definitions()` lit depuis la DB. Shape de retour **strictement identique** (prix en dollars).
  2. **Platform Plan CRUD** — `PlanCrudController` (index/store/update/toggleActive) sous `manage_companies`. Remplace l'ancien `PlanController` read-only.
  3. **PaymentGatewayProvider** — Nouveau contrat vendor-agnostic (`createCheckout`, `handleCallback`, `cancelSubscription`, `key`). Coexiste avec `BillingProvider` existant.
  4. **PaymentGatewayManager** — Extends `Manager` (même pattern que `BillingManager`). Driver par défaut lu depuis `platform_settings.billing`. Pas de hardcoding stripe — discovery dynamique via `module.json`.
  5. **NullPaymentGateway** — Driver par défaut. `createCheckout()` crée une `Subscription(pending)` et retourne `CheckoutResult(mode: 'internal', message: 'Contact admin')`. Garde contre les duplicates pending.
  6. **ChangePlanService** — Service Core pour orchestrer le checkout (controllers thin).
  7. **Tables billing** — `subscriptions`, `payments`, `invoices` vendor-agnostic avec FK cascade delete. Index sur `(company_id, status)`.
  8. **POST /api/company/billing/checkout** — Nouveau endpoint dédié. PUT /company/plan reste pour les changements admin via BillingProvider.
  9. **Platform Billing Governance** — `BillingConfigController` : providers, config (dans platform_settings.billing), subscriptions (ReadModel), approve (DB transaction + enforce one active), reject.
  10. **BillingModule** — Visibilité changée de `hidden` à `visible`. Page `/platform/billing` avec sélecteur provider + table subscriptions.
  11. **Company UX** — Plan.vue utilise checkout endpoint. Affiche "Pending Approval" si subscription pending existe. Désactive upgrade buttons pendant pending.
  12. **Module packaging Stripe** — `StripePaymentModule` stub (scope: platform, type: addon, visibility: hidden). `module.json` avec `provides_payment_driver: stripe`. Routes placeholder. Pas de SDK Stripe.
- **Contraintes** : EntitlementResolver NON modifié. `companies.plan_key` inchangé. Aucun SDK Stripe. Pas de breaking API contract (checkout est additif).
- **Fichiers** : 21 CREATE, 13 MODIFY, 1 DELETE
- **Conséquences** :
  - Les plans sont modifiables par les admins platform (CRUD complet)
  - Le flow d'upgrade company passe par un checkout avec subscription pending
  - Les admins platform approuvent/rejettent les subscriptions
  - La config payment gateway est en DB (modifiable via UI)
  - Le système découvre dynamiquement les providers de paiement via module.json
  - Prêt pour Stripe SDK integration (ADR-102)

## ADR-102 : Platform Commercial Governance Refactor

- **Date** : 2026-02-23
- **Contexte** : ADR-101 a structuré le domaine technique (Plans DB, Subscription lifecycle, PaymentGateway abstraction). Cependant la surface Platform était trop technique pour des employés non techniques : prix en cents, driver/JSON config exposés, Plans absent du menu navigation.
- **Décision** :
  1. **Plan-centric governance** — Page `/platform/plans` refaite en grille VCard marketing (prix en dollars, badges, features). Plus de table CRUD technique.
  2. **Page dédiée par plan** — `/platform/plans/{key}` avec 4 sections : infos commerciales, features marketing (liste éditable), limits techniques (panneau repliable), companies sur ce plan.
  3. **Prix en dollars** — Le contrôleur accepte les prix en dollars et convertit en cents côté serveur. Aucun cent visible dans l'UI Platform.
  4. **PlansModule** — Nouveau module platform avec nav item "Plans" (sortOrder: 15, permission: manage_companies). Plans maintenant visible dans la navigation.
  5. **Page Payments** — `/platform/payments` remplace `/platform/billing`. Trois sections : modules de paiement (cards visuelles), politiques commerciales (toggles), gouvernance abonnements.
  6. **Payment Policies** — Nouvelles règles commerciales stockées dans `platform_settings.billing.policies` : paiement requis, approbation admin, billing annuel, devise, TVA.
  7. **BillingModule renommé** — Nav item "Billing" → "Payments", route → platform-payments, sortOrder 70 → 60.
  8. **PlanDetailReadModel** — ReadModel pour les companies d'un plan avec subscription eager-loaded.
- **Contraintes** : Aucune modification de PaymentGatewayManager, ChangePlanService, Subscription lifecycle, EntitlementResolver, structure DB. Surface uniquement.
- **Fichiers** : 3 CREATE, 7 MODIFY, 1 DELETE, 1 REWRITE = 12 fichiers uniques
- **Conséquences** :
  - Platform lisible par un employé administratif non technique
  - Gouvernance commerciale centrée produit (une page par plan)
  - Paiements modulaires en surface (modules visuels, pas de driver technique)
  - Aucune exposition technique brute (cents, JSON, driver keys)
  - Navigation Platform complète : Dashboard, Companies, Plans, Payments, ...

## ADR-103 : Internationalization & World Layer

- **Date** : 2026-02-23
- **Contexte** : L'application ciblait uniquement le marché US avec des chaînes hardcodées en anglais, des prix en `$` et des dates au format US. Pour activer le marché français (et futur multi-marché), une couche d'internationalisation complète est nécessaire.
- **Décision** :
  1. **Layer 1 — Clean English Baseline** : Normalisation de toutes les chaînes métier via `vue-i18n` (`useI18n()` + `t()`). ~986 clés structurées dans `en.json`. Aucune chaîne hardcodée restante dans les pages métier.
  2. **Formateurs centralisés** : `formatMoney(cents, {currency, locale})` dans `utils/money.js` et `formatDate()`/`formatDateTime()` dans `utils/datetime.js`, tous basés sur `Intl.NumberFormat` et `Intl.DateTimeFormat`.
  3. **World Settings** : Nouvelle colonne JSON `world` dans `platform_settings` (country, currency, locale, timezone, dial_code). `WorldSettingsPayload` immutable avec defaults US.
  4. **WorldSettingsController** — CRUD Platform (permission `manage_theme_settings`) + `PublicWorldController` (public, sans auth).
  5. **World Store** — Pinia store global `useWorldStore()` chargé au boot (router guard + `usePublicTheme`). Les formateurs lisent les defaults depuis ce store.
  6. **Platform Settings → World tab** — Nouveau tab dans `/platform/settings/world` avec selects pour country, currency, locale, timezone, dial code. Save synchronise le world store global.
  7. **French locale** — `fr.json` complet (986 clés), traduction professionnelle formelle. Le système i18n auto-enregistre tout fichier `locales/*.json` via `import.meta.glob`.
- **Fichiers** :
  - Backend : 1 migration, `WorldSettingsPayload.php`, `WorldSettingsController.php`, `PublicWorldController.php`, model + routes modifiés
  - Frontend : `world.js` (store), `_SettingsWorld.vue`, `money.js` + `datetime.js` (formateurs connectés au world store), `[tab].vue` (world tab ajouté), `fr.json`, `en.json` (~986 clés)
  - ~40 pages/composants métier modifiés pour `t()` dans Layer 1
- **Conséquences** :
  - Toutes les chaînes métier passent par `t()` — ajout d'une langue = un fichier JSON
  - Les formateurs respectent automatiquement la locale et devise du platform (world store)
  - Changement de locale depuis Platform Settings → impact immédiat sur tous les utilisateurs
  - Prêt pour le marché français : switch locale `fr-FR`, currency `EUR`, timezone `Europe/Paris`

## ADR-104 : International Market Engine

- **Date** : 2026-02-23
- **Contexte** : ADR-103 introduisait un simple tab "World" avec 5 champs dropdown stockés en JSON column (`platform_settings.world`). Insuffisant pour un SaaS multi-marché : pas de CRUD, pas de statuts juridiques, pas de langues par marché, pas de traductions dynamiques, pas de taux de change. ADR-104 remplace ce tab par un véritable **domaine Markets** — un moteur d'internationalisation complet et administrable.
- **Décision** :
  1. **8 nouvelles tables** : `markets` (CRUD par pays), `legal_statuses` (statuts juridiques par marché avec taux TVA), `languages` (globales), `market_language` (pivot), `translation_bundles` (traductions DB par locale/namespace), `translation_overrides` (surcharges par marché), `fx_rates` (taux de change), + colonnes `market_key`/`legal_status_key` ajoutées à `companies`
  2. **6 Eloquent models** : `Market`, `LegalStatus`, `Language`, `TranslationBundle`, `TranslationOverride`, `FxRate` dans `app/Core/Markets/`
  3. **MarketRegistry** : Pattern Registry (comme `PlanRegistry`) — `seedDefaults()` fournit le marché FR avec 8 statuts juridiques (SAS, SASU, SARL, EURL, SA, SNC, SCI, Auto-entrepreneur) + langues en/fr. `sync()` idempotent, préserve les décisions admin.
  4. **MarketResolver** : Résolution de marché pour une company (market_key → market) ou default (is_default → premier actif → fallback US). `WorldSettingsPayload` évolué pour lire depuis `MarketResolver::resolveDefault()` — l'API `/api/public/world` reste backward-compatible.
  5. **TranslationRepository** : Fusion traductions DB bundles + surcharges par marché. `diff()` pour preview import.
  6. **API publique** : `GET /api/public/markets`, `GET /api/public/markets/{key}`, `GET /api/public/i18n/{locale}/{namespace?}` (avec `?market=` optionnel)
  7. **Platform admin** : Module `platform.markets` (permission `manage_markets`) — CRUD marchés, statuts juridiques imbriqués, langues, traductions (import/export JSON), taux de change. Pages Vue : `/platform/markets` (index + detail 4 tabs), `/platform/languages`.
  8. **Company UX** : Sélecteurs marché + statut juridique dans company settings. `world.js` store enrichi avec `applyMarket()`.
  9. **FX Scheduler** : `FxRateFetchJob` (ShouldQueue) avec source stub (rates hardcodés). Planifié toutes les 6 heures via `routes/console.php`. UI dans la page markets index.
  10. **World tab supprimé** : Le tab "World" de Platform Settings est remplacé par les pages Markets dédiées.
- **Fichiers** :
  - Migrations : 8 fichiers (`create_markets`, `create_legal_statuses`, `create_languages`, `create_market_language`, `create_translation_bundles`, `create_translation_overrides`, `create_fx_rates`, `add_market_columns_to_companies`)
  - Models : `Market`, `LegalStatus`, `Language`, `TranslationBundle`, `TranslationOverride`, `FxRate`
  - Services : `MarketRegistry`, `MarketResolver`, `TranslationRepository`, `MarketDetailReadModel`
  - Controllers : `PublicMarketController`, `PublicI18nController`, `MarketCrudController`, `LegalStatusController`, `LanguageController`, `TranslationController`, `FxRateController`, `CompanyMarketController`
  - Module : `MarketsModule` (navItems, permission manage_markets)
  - Job : `FxRateFetchJob`
  - Frontend : `markets.store.js`, `markets/index.vue`, `markets/[key].vue`, `languages.vue`, company `settings.vue` + `settings.store.js` + `world.js` modifiés
  - Supprimé : `_SettingsWorld.vue`, world actions dans `settings.store.js`
  - i18n : clés markets/legalStatuses/languages/translations/fxRates en en.json + fr.json
- **Conséquences** :
  - Multi-marché natif : créer un marché = ajouter un pays avec sa devise, locale, timezone, statuts juridiques
  - Chaque company est rattachée à un marché → formatMoney/formatDate utilisent les paramètres du marché
  - Traductions dynamiques en DB avec surcharges par marché (terminologie locale)
  - Taux de change maintenus automatiquement (extensible vers API réelle)
  - World tab remplacé par un système complet et administrable
  - Backward-compatible : `/api/public/world` retourne les données du marché par défaut

---

## ADR-105 : Market Flags (SVG) + Export/Import JSON Metadata

- **Date** : 2026-02-24
- **Contexte** : Les marchés n'avaient aucune représentation visuelle (drapeau). L'export JSON ne contenait pas de métadonnées de version. L'import ne filtrait pas les clés internes, risquant de créer des marchés fantômes à partir de clés comme `_meta`.
- **Décision** :
  1. **2 nouvelles colonnes** sur `markets` : `flag_code` (VARCHAR 2, nullable — ISO 3166-1 alpha-2) et `flag_svg` (LONGTEXT, nullable — markup SVG sanitisé).
  2. **SvgSanitizer** (`app/Core/Markets/SvgSanitizer.php`) : Service de sanitisation SVG par allowlist de tags et d'attributs via DOMDocument. Supprime `<script>`, `<foreignObject>`, attributs `on*`, href `javascript:`/`data:`. Tout SVG est sanitisé avant stockage en DB.
  3. **Seed defaults** : FR (tricolore) et GB (Union Jack) fournis en SVG inline dans `MarketRegistry::seedDefaults()`, sanitisés au passage par `SvgSanitizer::sanitize()`.
  4. **Sync protecteur** : `MarketRegistry::sync()` ne remplace pas les flags existants (préserve les éditions admin). Seuls les champs vides sont peuplés depuis les seed defaults.
  5. **Import smart** : `importFromArray()` n'inclut `flag_code`/`flag_svg` que si non-vides dans le payload d'import (pas d'écrasement par valeur nulle). Les clés commençant par `_` sont filtrées avant traitement.
  6. **Export avec `_meta`** : L'export JSON inclut un objet `_meta: { version, exported_at }` pour traçabilité. Le preview et l'apply d'import filtrent `_meta` côté backend.
  7. **UI table** : Colonne drapeau en première position dans `_TabMarkets.vue` — SVG inline (32×22px) ou icône fallback `tabler-flag`.
  8. **UI détail** : Section "Flag" dans le tab General de `[key].vue` — champ code ISO, textarea SVG avec preview live (64×44px), bouton reset pour recharger la valeur serveur.
  9. **Validation** : `flag_code` (nullable, string, size:2), `flag_svg` (nullable, string, max:50000) sur store et update.
- **Fichiers** :
  - Créés : `database/migrations/2026_02_24_100001_add_flag_columns_to_markets.php`, `app/Core/Markets/SvgSanitizer.php`
  - Modifiés : `Market.php` ($fillable), `MarketRegistry.php` (seed + sync + import), `MarketCrudController.php` (validation + export + sanitize), `_TabMarkets.vue`, `[key].vue`, `en.json`, `fr.json`
- **Conséquences** :
  - Chaque marché peut afficher un drapeau SVG inline — zéro dépendance externe, zéro requête réseau
  - Le SVG est toujours sanitisé avant stockage → safe pour `v-html`
  - L'export JSON est versionné et auto-documenté
  - L'import est robuste : pas de marchés fantômes, pas d'écrasement involontaire des drapeaux

---

## ADR-106 : Séparation UI Modules Entreprise / Modules Plateforme

- **Date** : 2026-02-24
- **Contexte** : La page `/platform/modules` affichait uniquement les modules company-scope (core.members, core.settings, logistics_*) sous le titre "Modules plateforme", créant une confusion architecturale. Les 14 modules platform-scope (platform.dashboard, platform.users, platform.plans, etc.) étaient invisibles dans l'UI — aucune vue d'ensemble des capacités internes de la plateforme.
- **Décision** :
  1. **Aucune modification d'architecture** : pas de changement de scope dans les manifests, pas de nouvelle colonne DB, pas de modification du ModuleRegistry.
  2. **Controller `index()`** : Retourne désormais `{ company: [...], platform: [...] }` au lieu de `{ modules: [...] }`. Les modules company restent identiques (override merge, toggleable). Les modules platform sont construits en lecture seule depuis les manifests (key, name, description, type, visibility, surface, sortOrder, icon, permissions, capabilities).
  3. **UI à onglets** : La page `/platform/modules` utilise `VTabs` (pill style) avec deux onglets :
     - **"Modules entreprise"** : comportement identique à l'existant (filtres type/plan/status, toggle global, click → page détail, chips jobdomains)
     - **"Modules plateforme"** : table lecture seule avec nom+description, key, badge "Internal", surface, chips permissions. Pas de toggle, pas de pricing, pas de navigation vers détail. Les modules `visibility: hidden` sont exclus.
  4. **Store** : Nouveau state `_platformModules` + getter `platformModules`. `fetchModules()` lit `data.company` et `data.platform`.
  5. **i18n** : 3 clés ajoutées (en + fr) : `companyModules`, `platformTab`, `platformSubtitle`.
- **Fichiers** :
  - Modifiés : `ModuleController.php` (index), `settings.store.js` (state + getter + fetch), `modules/index.vue` (VTabs layout), `en.json`, `fr.json`
- **Conséquences** :
  - Visibilité complète : l'admin voit les deux scopes de modules séparément
  - Clarté structurelle : les modules entreprise sont gouvernables, les modules plateforme sont informationnels
  - Pas de mélange de scopes — la séparation est explicite dans l'UI
  - Zéro impact sur les endpoints existants (show, toggle, updateConfig restent company-scope only)

## ADR-107 : Backend Activation Contract — Module Route Gating

- **Date** : 2026-02-24
- **Contexte** : Les routes company-scope `core.settings` et `core.members` n'étaient pas protégées par le middleware `company.access:use-module,{key}`. Un utilisateur dont le module était désactivé pouvait toujours accéder aux endpoints. L'audit LOT 1 a identifié ce manque.
- **Décision** :
  1. **Toute route company-module** est wrappée dans un `Route::middleware('company.access:use-module,{moduleKey}')->group(...)` dans `routes/company.php`.
  2. **Test automatisé** : `CompanyModuleRoutesAreGatedTest` scanne toutes les routes Laravel, vérifie que chaque controller sous `App\Modules\` en scope company porte le middleware `company.access:use-module,{key}`. Toute nouvelle route non-gatée fait échouer la CI.
  3. **Trait de test** : `tests/Support/ActivatesCompanyModules.php` — appelle `ModuleRegistry::sync()` + crée les `CompanyModule` records pour tous les modules company-scope. Appliqué à tous les tests existants qui touchent des routes module-gatées.
  4. **Routes non-gatées** (par design) : Company plan (ADR-100), Billing checkout (ADR-101), Modules management, Company roles, User profile — ces routes ne sont pas liées à un module spécifique.
- **Fichiers** :
  - Modifiés : `routes/company.php`, 9 fichiers de tests existants, `tests/Feature/ExampleTest.php`
  - Créés : `tests/Feature/CompanyModuleRoutesAreGatedTest.php`, `tests/Support/ActivatesCompanyModules.php`
- **Conséquences** :
  - Contrat contractuel : impossible d'ajouter une route company-module sans la gater — le test l'attrape
  - Les tests existants ont été migrés vers le trait `ActivatesCompanyModules`
  - 193 → 206 tests (ajout des tests module-dependency en LOT 2)

## ADR-108 : Enforcement des dépendances `requires` entre modules

- **Date** : 2026-02-24
- **Contexte** : Le champ `ModuleManifest::requires` existait mais aucun module ne l'utilisait et aucun test ne validait son enforcement. Les modules logistics (tracking, fleet, analytics) n'étaient pas protégés par un lien de dépendance vers `logistics_shipments`.
- **Décision** :
  1. **Dépendances déclarées** : `logistics_tracking`, `logistics_fleet`, `logistics_analytics` requièrent tous `logistics_shipments` via `requires: ['logistics_shipments']`.
  2. **Activation bloquée** : `DependencyResolver::canActivate()` vérifie que tous les modules requis sont actifs via `ModuleGate::isActive()`. `CompanyModuleService::enable()` retourne 422 avec `missing: [...]` si non satisfait.
  3. **Désactivation bloquée** : `DependencyResolver::canDeactivate()` scanne tous les modules company-scope pour trouver ceux qui déclarent le module comme requirement ET qui sont actifs. `CompanyModuleService::disable()` retourne 422 avec `dependents: [...]`.
  4. **Zéro cascade automatique** : La désactivation d'un module requis est refusée — l'admin doit désactiver les dépendants d'abord explicitement.
  5. **13 tests** dans `ModuleDependencyTest.php` couvrant : resolver direct, service-level, HTTP-level, multi-dependents, no-cascade.
- **Fichiers** :
  - Modifiés : `TrackingModule.php`, `FleetModule.php`, `AnalyticsModule.php` (ajout `requires`)
  - Créé : `tests/Feature/ModuleDependencyTest.php`
  - Déjà existants (non modifiés) : `DependencyResolver.php`, `CompanyModuleService.php`
- **Conséquences** :
  - Pattern établi : tout futur module avec dépendances utilise `requires: [...]` dans son manifest
  - Enforcement bidirectionnel : activation ET désactivation sont contraintes
  - Messages d'erreur explicites avec les clés de modules concernés

## ADR-109 : Cross-scope Module Pattern — 2 modules distincts, couplage via requires

- **Date** : 2026-02-24
- **Contexte** : Certains domaines fonctionnels (billing, plans, fields) ont besoin de capacités à la fois côté plateforme (configuration admin) et côté entreprise (utilisation). La question se pose : un module peut-il être multi-scope ? Comment modéliser cette dualité ?
- **Décision** :
  1. **Un module = un scope unique.** `ModuleManifest::scope` est soit `'company'` soit `'platform'`, jamais les deux. Un manifest ne peut pas déclarer deux scopes.
  2. **Un domaine cross-scope = 2 modules distincts** avec des clés explicites. Convention de nommage :
     - `{domain}.platform` pour le module plateforme (ex: `billing.platform`)
     - `{domain}` ou `{domain}.company` pour le module entreprise (ex: `billing` ou `billing.company`)
  3. **Couplage via `requires`** : Le module entreprise déclare `requires: ['{domain}.platform']` si sa configuration admin est gérée par le module plateforme. Le module plateforme ne déclare PAS de dépendance inverse — il est autonome.
  4. **Pas de manifest multi-scope** : Interdiction de créer un manifest avec `scope: 'both'` ou un tableau de scopes. La dualité est gérée par deux fichiers PHP séparés dans deux répertoires distincts.
  5. **Structure fichiers** :
     ```
     app/Modules/
     ├── Platform/Billing/         # scope=platform (config admin)
     │   └── BillingModule.php     # key='platform.billing'
     └── Company/Billing/          # scope=company (utilisation)
         └── BillingModule.php     # key='billing', requires=['platform.billing']
     ```
  6. **Navigation** : Chaque module déclare ses propres `navItems` et `routeNames` dans ses `Capabilities`. Pas de navItems partagés entre scopes.
  7. **Activation** : Le module plateforme est toujours activé via `PlatformModule.is_enabled_globally`. Le module company est activé per-company via `CompanyModule`. L'enforcement `requires` (ADR-108) garantit que le module plateforme est actif avant que le module company puisse être activé.
- **Modules existants concernés** :
  - `platform.billing` (platform scope) — existe déjà
  - `platform.fields` (platform scope) — existe déjà
  - `platform.plans` (platform scope) — existe déjà
  - Aucun module company-scope cross-scope n'existe encore. Ce pattern sera appliqué quand le besoin émergera (ex: `billing.company` pour la gestion des abonnements côté entreprise).
- **Conséquences** :
  - Clarté architecturale : un module = un scope, un manifest = un fichier
  - Pas de confusion entre capacités admin et capacités utilisateur
  - Le `requires` explicite documente le couplage au lieu de le rendre implicite
  - Scalable : chaque nouveau domaine cross-scope suit le même pattern sans exception

## ADR-110 : Platform Navigation Manifest-Driven

- **Date** : 2026-02-24
- **Statut** : Implemented
- **Contexte** : La navigation plateforme devait être alignée avec le pattern company (manifest-driven via composable). Besoin de valider contractuellement que chaque module plateforme déclare ses navItems et routeNames.
- **Décision** :
  1. **Composable `usePlatformNav()`** — déjà existant, lit `platformAuth.platformModuleNavItems` alimenté par l'API `/api/platform/me`. Filtre par permission, place Dashboard en premier, ajoute heading "Management".
  2. **Backend `platformModuleNavItems()`** — collecte les navItems de tous les modules platform avec `visibility !== 'hidden'`. Retourné dans le payload login/me.
  3. **Contrat structurel** — `PlatformModuleNavContractTest.php` (5 tests) :
     - Tout module visible avec routeNames doit déclarer navItems (sauf modules shared-page documentés)
     - Tout module avec navItems doit déclarer routeNames
     - Les routes référencées dans navItems doivent être dans routeNames
     - Pas de navItem keys dupliqués entre modules
     - L'API retourne tous les items attendus
  4. **Pattern "shared page"** — TranslationsModule (`platform.translations`) partage la page International avec MarketsModule (`platform.markets`). Il déclare `routeNames: ['platform-international-tab']` mais pas de navItem propre — la nav est portée par Markets. Ce pattern est explicitement documenté dans la liste `$sharedPageModules` du test.
  5. **Modules stub** (ex: `platform.audience`) — navItems vides = pas de navigation. Quand la page sera créée, le test attrapera l'incohérence.
- **Fichiers** :
  - Créé : `tests/Feature/PlatformModuleNavContractTest.php`
  - Existants (non modifiés) : `usePlatformNav.js`, `PlatformAuthController.php`, tous les modules platform
- **Conséquences** :
  - Navigation 100% manifest-driven pour les deux scopes (company + platform)
  - Le CI attrape tout nouveau module qui oublie de déclarer ses navItems
  - Le pattern shared-page est explicitement documenté et whitlisté

## ADR-111 : Structural Cleanup — LOT 5

- **Date** : 2026-02-24
- **Statut** : Implemented
- **Contexte** : L'audit architectural a identifié 4 incohérences structurelles : ThemeController orphelin, collision sortOrder, permission manquante, routeNames désalignés.
- **Décision** :
  1. **ThemeController orphan résolu** — Le controller `ThemeController` vivait dans `app/Modules/Platform/Theme/Http/` sans ModuleDefinition associée. Il fait partie des Settings (utilise `manage_theme_settings`). Déplacé vers `app/Modules/Platform/Settings/Http/ThemeController.php`, import route mis à jour, répertoire orphelin `Platform/Theme/` supprimé.
  2. **sortOrder collision fixée** — `platform.fields` (60) et `platform.billing` (60) avaient le même sortOrder. Billing changé à 65 pour dé-dupliquer. Test `ModuleManifestIntegrityTest::test_no_sort_order_collisions_within_same_scope` ajouté pour prévenir toute récidive.
  3. **Permission `manage_plans` créée** — `PlansModule` utilisait `manage_companies` par emprunt. Ajout d'une permission dédiée `manage_plans`, bundle `plans.catalog`, extraction des routes plans dans leur propre groupe middleware `platform.permission:manage_plans`.
  4. **routeNames alignés** — 5 modules platform avaient des pages détail (`[id].vue`, `[key].vue`) non déclarées dans `routeNames`. Ajouté :
     - `platform-companies-id` à CompaniesModule
     - `platform-plans-key` à PlansModule
     - `platform-jobdomains-id` à JobdomainsModule
     - `platform-users-id` à UsersModule
     - `platform-modules-key` à ModulesModule
  5. **Test d'intégrité manifests** — `ModuleManifestIntegrityTest` (4 tests) : pas de collision sortOrder, permissions navItem référencent des permissions déclarées, requires référencent des modules existants, naming convention respectée.
- **Fichiers** :
  - Créés : `app/Modules/Platform/Settings/Http/ThemeController.php`, `tests/Feature/ModuleManifestIntegrityTest.php`
  - Modifiés : `routes/platform.php`, `BillingModule.php`, `PlansModule.php`, `CompaniesModule.php`, `JobdomainsModule.php`, `UsersModule.php`, `ModulesModule.php`, `PlatformSettingsModule.php`
  - Supprimés : `app/Modules/Platform/Theme/` (répertoire orphelin)
- **Conséquences** :
  - Zéro controller orphelin — chaque controller appartient à un module déclaré
  - Zéro collision sortOrder — le CI l'attrape
  - Permission autonome par module — séparation propre des responsabilités
  - routeNames complets — le frontend peut contractuellement vérifier l'alignement page↔module

## ADR-112 : Frontend/Backend Module Alignment — LOT 6

- **Date** : 2026-02-24
- **Statut** : Implemented
- **Contexte** : Le frontend et le backend avaient des incohérences dans la protection des routes modules. Les pages `members/` et `settings.vue` étaient gatées côté backend (`company.access:use-module`) mais pas côté frontend (pas de `meta.module`). Les pages `plans/` utilisaient `permission: 'manage_companies'` au lieu de `manage_plans`.
- **Décision** :
  1. **`meta.module` sur toutes les pages company module-gated** :
     - `members/index.vue`, `members/[id].vue` → `meta.module: 'core.members'`
     - `settings.vue` → `meta.module: 'core.settings'`
     - `jobdomain.vue` → `meta.module: 'core.settings'`
     - Les pages shipments/deliveries avaient déjà `meta.module: 'logistics_shipments'` ✅
  2. **Permission `manage_plans` alignée frontend↔backend** :
     - `plans/index.vue`, `plans/[key].vue` → `permission: 'manage_plans'` (était `manage_companies`)
     - Routes backend `/api/platform/plans/*` → groupe `platform.permission:manage_plans`
  3. **routeNames complétés** pour company modules :
     - `core.members` : ajout `company-members-id` (page détail)
     - `core.settings` : ajout `company-jobdomain` (page industrie sous gate settings)
  4. **Test de vérification `PageModuleAlignmentTest`** (3 tests) :
     - Chaque page company sous un module déclare `meta.module` correspondant au manifest
     - Chaque permission frontend platform est déclarée dans un module manifest
     - Chaque routeName de module a une page Vue correspondante
  5. **Guard flow (existant, non modifié)** :
     - Company : `to.meta.module` → `moduleStore.isActive(key)` → redirect `/dashboard` si inactif
     - Platform : `to.meta.permission` → `platformAuth.hasPermission(key)` → redirect `/platform` si refusé
- **Fichiers** :
  - Modifiés : `members/index.vue`, `members/[id].vue`, `settings.vue`, `jobdomain.vue`, `plans/index.vue`, `plans/[key].vue`, `MembersModule.php`, `SettingsModule.php`
  - Créé : `tests/Feature/PageModuleAlignmentTest.php`
- **Conséquences** :
  - Alignement contractuel : backend ET frontend refusent les routes de modules inactifs
  - Permission coherence : chaque page utilise la permission de son module, pas celle d'un autre
  - Le CI attrape tout nouveau désalignement page↔module
  - Zéro TODO, zéro dette technique dans l'alignement frontend/backend

---

## ADR-113 : Unified Module Engine — Scope Admin + Middleware Universel

- **Date** : 2026-02-24
- **Statut** : Implemented
- **Contexte** : Le moteur modulaire traitait `scope: 'platform'` et `scope: 'company'` de façon asymétrique. Les modules platform n'avaient pas de gate d'activation (`module.active:{key}`), pas de `ModuleGate`, et leurs routes contournaient le moteur modulaire. Les modules company utilisaient `ModuleGate`, `CompanyModuleService`, et le middleware `company.access:use-module,{key}`. Ce double chemin créait de la dérive et de la dette technique.
- **Décision** :
  1. **Scope obligatoire** : `scope` est obligatoire dans `ModuleManifest` (pas de valeur par défaut). La valeur `'platform'` est renommée en `'admin'`. Le boot refuse tout scope invalide.
  2. **Activation unifiée** : `ModuleGate::isActiveForScope()` gère les deux scopes. Les modules admin nécessitent uniquement `is_enabled_globally`. Les modules company nécessitent le contexte company + activation. `AdminModuleService` gère le toggle des modules admin. Les modules `type: 'core'` sont toujours actifs pour toute company (seul `is_enabled_globally` est vérifié).
  3. **Middleware universel** : Toutes les routes de modules utilisent `module.active:{key}`. Les routes admin le reçoivent en plus de `platform.permission:{key}`. Routes exemptées : auth, /me, /logout, /heartbeat, routes publiques.
  4. **Zéro route hors module** : Tous les controllers sont sous `App\Modules\`. Infrastructure (auth, public, system, webhooks) sous `App\Modules\Infrastructure\*` sans `ModuleDefinition`. Deux nouveaux modules company : `core.modules` (catalog browse) et `core.billing` (plan & billing).
  5. **Alignement frontend total** : Toutes les pages platform déclarent `meta.module`. Le router guard bloque si le module est désactivé globalement. `PlatformAuthController` retourne `disabled_modules` dans login/me. La navigation company est manifest-driven (Roles, Industry, Plan, Modules supprimés du nav statique).
  - Fichiers clés : `ModuleManifest`, `ModuleGate`, `ModuleRegistry`, `EnsureModuleActive`, `AdminModuleService`, `PlatformAuthController`, `guards.js`, `platformAuth.js`
  - Les clés de module gardent leur préfixe (`platform.*`, `core.*`, `payments.*`) — seule la valeur du champ `scope` change.
- **Conséquences** :
  - Un seul chemin d'activation, de middleware et de routing pour admin et company — zéro exception
  - Le CI vérifie l'alignement page↔module pour les deux scopes (`PageModuleAlignmentTest`)
  - Le CI vérifie que toutes les routes de modules ont le middleware d'activation (`GlobalModuleRouteCoverageTest`)
  - Le CI vérifie que tous les controllers sont sous `App\Modules\` (`NoOrphanRouteTest`)
  - Zéro dette technique dans le moteur modulaire

---

## ADR-114 : Navigation 100% Manifest-Driven

- **Date** : 2026-02-25
- **Statut** : Implemented — Legacy fallback removed (ADR-114 convergence)
- **Contexte** : Le moteur modulaire supporte `scope: admin | company`, activation unifiée (ModuleGate), permissions déclaratives, filtrage plan/jobdomain, et navItems déclarés dans les manifests. Cependant la navigation n'est pas totalement pilotée par les modules, les groupes sont implicites, le horizontal peut devenir instable (wrap multi-lignes), et les différences de jobdomain ne doivent pas générer des menus hardcodés. Objectif : rendre la navigation entièrement dérivée des manifests modules, dynamique, cohérente et scalable (500+ modules), sans hardcoding.
- **Décision** : La navigation devient une **projection dynamique des manifests modules**. Le menu est un moteur. Les modules déclarent les items. Le moteur assemble selon le contexte. Aucune liste fixe de groupes. Aucun menu par jobdomain.

### Modèle NavItem Standard

Chaque module peut déclarer :

```php
[
    'key' => 'shipments',
    'title' => 'Shipments',
    'to' => ['name' => 'company-shipments'],
    'icon' => 'tabler-truck',

    // Structure
    'group' => 'operations',
    'parent' => null,

    // Filtres
    'jobdomains' => ['delivery', 'logistics'], // optionnel
    'tags' => ['operations', 'tracking'],      // recommandé long terme
    'plans' => ['pro'],                        // optionnel
]
```

### Pipeline de Construction

1. Collecte de tous les navItems déclarés
2. Filtrage : module actif, permissions, plan, jobdomain
3. Construction arbre : `group` → sections, `parent` → hiérarchie
4. Pruning : suppression groupes vides, suppression parents sans enfants (si non cliquable)
5. Retour menu final

### Règles Structurelles

- **Groupe** : existe uniquement si ≥ 1 item visible l'utilise. Aucune déclaration globale de groupes.
- **Parent** : affiché si il possède une route (`to`) OU si il possède ≥ 1 enfant visible. Sinon supprimé.

### Compatibilité Jobdomain (Scalable)

- **Basique** : chaque item déclare explicitement ses jobdomains.
- **Recommandé (scalable)** : les modules déclarent des `tags`. Chaque jobdomain déclare les tags acceptés. Exemple : `delivery` accepte `['operations','tracking','fleet','core']`, `food` accepte `['core','members','pos']`. Avantage : ajouter un jobdomain ne nécessite pas de modifier tous les modules.

### Navigation Horizontale

- Horizontal affiche uniquement les top-level (groupes/parents)
- Les enfants sont en dropdown
- En cas d'overflow → bouton "Plus"
- Interdiction du wrap sur deux lignes

### Backend Contract

- Endpoints : `GET /api/company/nav`, `GET /api/platform/nav`
- Le frontend ne contient plus aucune navigation hardcodée.

### Tests Obligatoires

- Aucun parent orphelin
- Aucun groupe vide
- Aucune clé dupliquée
- Aucun cycle parent
- Même moteur admin et company
- Un jobdomain sans modules operations ne reçoit jamais le groupe operations

- **Conséquences** :
  - Navigation entièrement modulaire
  - Compatible 500+ modules
  - Ajout module = auto-intégration dans le menu
  - Aucun hardcoding par jobdomain
  - Même moteur pour admin et company

- **Non objectifs** : pas d'édition libre du menu, pas de groupes dynamiques hors manifests, pas de duplication UI.

---

## ADR-115 : Intelligent Module Activation & Dependency Engine

- **Date** : 2026-02-25
- **Statut** : Implemented
- **Contexte** : Le système de modules gère les dépendances (`requires`) de façon basique : rejet si un module requis n'est pas actif, rejet si un module dont d'autres dépendent est désactivé. Pas de cascade, pas de nettoyage des orphelins, pas de traçabilité de la raison d'activation, pas de détection de cycles, pas d'invariant pricing/requires.
- **Décision** : Remplacement de `CompanyModuleService` par un `ModuleActivationEngine` intelligent + table `company_module_activation_reasons` comme source de vérité.

### Architecture

**Source de vérité** : `company_module_activation_reasons` (multi-parent tracking)
- Raisons : `direct`, `plan`, `bundle`, `required`
- `source_module_key` nullable : quel module a causé cette activation (pour `required`)
- `company_modules.is_enabled_for_company` devient un **cache dérivé** synchronisé par l'engine

**ModuleActivationEngine** (remplace `CompanyModuleService`) :
- `enable()` : cascade-active les modules requis avec raison `required`
- `disable()` : retire la raison `direct`, puis nettoie les orphelins itérativement
- Orphelin = module avec 0 raisons restantes → désactivé + ses dépendants requis re-évalués
- `collectTransitiveRequires()` : DFS pour résoudre les dépendances transitives
- `hasAnyReason()`, `reasonsFor()` : introspection

**DependencyGraphValidator** (validation statique) :
- Détection de cycles (DFS avec coloration WHITE/GRAY/BLACK)
- Invariant pricing : module requis ne peut pas avoir `pricing_mode='addon'`
- Exécutable au seed/boot pour détecter les erreurs de manifeste

**ModuleGate** : inchangé, lit toujours `company_modules.is_enabled_for_company` (cache rapide)
**DependencyResolver** : conservé pour validation statique (`canActivate`, `canDeactivate`)
**CompanyModuleService** : délégation simple vers `ModuleActivationEngine` (rétro-compatibilité)

### Fichiers

| Action | Fichier |
|--------|---------|
| CREATE | `database/migrations/2026_02_25_100001_create_company_module_activation_reasons_table.php` |
| CREATE | `database/migrations/2026_02_25_100002_backfill_activation_reasons_from_company_modules.php` |
| CREATE | `app/Core/Modules/CompanyModuleActivationReason.php` |
| CREATE | `app/Core/Modules/ModuleActivationEngine.php` |
| CREATE | `app/Core/Modules/DependencyGraphValidator.php` |
| CREATE | `tests/Unit/ModuleActivationEngineTest.php` (18 tests) |
| CREATE | `tests/Unit/DependencyGraphValidatorTest.php` (7 tests) |
| MODIFY | `app/Core/Modules/CompanyModuleService.php` → thin delegate |
| MODIFY | `app/Core/Modules/ModuleCatalogReadModel.php` → include activation_reasons |
| MODIFY | `app/Modules/Core/Modules/Http/CompanyModuleController.php` → use engine |
| MODIFY | `app/Modules/Platform/Companies/Http/CompanyModuleController.php` → use engine |
| MODIFY | `app/Core/Jobdomains/JobdomainGate.php` → create activation_reasons |
| REWRITE | `tests/Feature/ModuleDependencyTest.php` → 19 tests for new behavior |

### Comportements clés

| Avant (CompanyModuleService) | Après (ModuleActivationEngine) |
|-----|------|
| Enable rejeté si requires manquant | Enable cascade-active les requires |
| Disable rejeté si dépendants actifs | Disable retire raison direct, cleanup orphelins |
| 1 source (company_modules.is_enabled_for_company) | 2 sources : activation_reasons (vérité) + company_modules (cache) |
| Pas de traçabilité raison | Raisons : direct, plan, bundle, required |
| Pas de détection cycles | DFS avec coloration (RuntimeException) |
| Pas d'invariant pricing/requires | Required module ≠ addon pricing |

- **Conséquences** :
  - Activation intelligente avec cascade automatique
  - Désactivation sécurisée avec nettoyage des orphelins
  - Traçabilité complète de chaque activation
  - Cycles détectés au boot
  - Invariant pricing protège la facturation
  - 309 tests green, pnpm build clean

---

## ADR-116 : Module Pricing Policy + Quote Calculator

- **Date** : 2026-02-25
- **Statut** : Implemented
- **Contexte** : Le moteur modulaire (ADR-115) gère l'activation et les dépendances, mais ne contient pas de logique de tarification. Les modules ont un `pricing_mode` (`addon`, `included`, `internal`) et un `pricing_model` (`flat`, `plan_flat`), mais aucune couche ne valide les invariants prix/dépendances ni ne calcule de devis. Risque : un module requis par un autre pourrait être facturé en addon, créant une incohérence facturation/activation.
- **Décision** :
  1. **DependencyGraph** — Requêtes read-only sur le graphe de dépendances des manifests modules.
     - `requires(key)` : dépendances directes
     - `requiresClosure(key)` : fermeture transitive (DFS)
     - `requiredBy(key)` : reverse lookup
     - `buildFullGraph()` : adjacency list complète
     - Résultats toujours triés alphabétiquement pour déterminisme.
  2. **ModulePricingPolicy** — Validation des invariants tarification au boot et lors des mises à jour.
     - Règle 1 : Un module requis par au moins un autre ne peut pas être addon-priced
     - Règle 2 : Les modules `type: 'core'` ne peuvent pas être addon-priced
     - Règle 3 : Les modules `type: 'internal'` doivent avoir `pricing_mode: 'internal'`
     - Règle 4 : Aucune dépendance transitive ne peut être facturable indépendamment
     - Lève `RuntimeException` à la première violation.
  3. **ModuleQuoteCalculator** — Calcul déterministe de devis.
     - Pipeline : validation → vérification entitlements → expansion dépendances transitives → calcul montants
     - Seuls les modules sélectionnés sont facturés (addon-priced). Les modules requis sont `included` (non facturés).
     - Montants en centimes (entiers, pas de floats).
     - Modèles supportés : `flat` (prix fixe mensuel), `plan_flat` (prix dépendant du plan).
     - Même input → même output garanti (tri alphabétique des clés avant traitement).
  4. **Quote DTOs** — Objets immuables : `Quote` (total, currency, lines, included), `QuoteLine`, `QuoteIncluded`.
  5. **Endpoint** — `GET /api/modules/quote?keys[]=m1&keys[]=m2` via `ModuleQuoteController`.
     - Requiert auth + contexte company.
     - Retourne 422 pour module invalide, désactivé, ou non-éligible.
- **Fichiers** :
  - `app/Core/Modules/DependencyGraph.php` (108 lignes)
  - `app/Core/Modules/Pricing/ModulePricingPolicy.php` (245 lignes)
  - `app/Core/Modules/Pricing/ModuleQuoteCalculator.php` (167 lignes)
  - `app/Core/Modules/Pricing/Quote.php`, `QuoteLine.php`, `QuoteIncluded.php`
  - `app/Modules/Core/Modules/Http/ModuleQuoteController.php`
  - `tests/Unit/ModulePricingPolicyTest.php` (13 tests)
  - `tests/Unit/DependencyGraphTest.php` (13 tests)
  - `tests/Unit/ModuleQuoteCalculatorTest.php` (15 tests)
  - `tests/Feature/ModuleQuoteEndpointTest.php` (10 tests)
- **Conséquences** :
  - 51 tests couvrent pricing, graphe et endpoint
  - Invariants prix/dépendances protégés au boot — impossible de créer une incohérence facturation
  - Devis déterministe et sérialisable (centimes, tri alphabétique)
  - Extension future (per_seat, usage, tiered) préparée mais retourne 0 actuellement

---

> **Note** : ADR 117–123 n'ont jamais été utilisés. La numérotation passe de 116 à 124.

---

## ADR-124 : Payment Module Registry + Gateway Orchestration + Webhook Idempotency

- **Date** : 2026-02-25
- **Statut** : Implemented
- **Commit** : `1a9104b`
- **Contexte** : La plateforme doit supporter plusieurs prestataires de paiement (internal, Stripe, PayPal) avec des méthodes de paiement différentes selon le marché, le plan et l'intervalle de facturation. Le système doit être extensible (ajout de providers via module.json), gouvernable (admin platform installe/active/configure), et idempotent (webhooks ne doivent jamais être traités deux fois).
- **Décision** :
  1. **PaymentRegistry** — Registre statique de manifests paiement, booté dans `AppServiceProvider::boot()`.
     - Découvre les providers depuis les `module.json` (clé `payment_module`)
     - Provider `internal` (approbation manuelle) toujours disponible
     - Chaque manifest déclare : `providerKey`, `name`, `supportedMethods`, `requiresCredentials`, `credentialFields`
  2. **PaymentOrchestrator** — Moteur de résolution contextuelle.
     - Résout les méthodes de paiement disponibles pour un contexte (market + plan + interval)
     - Score de spécificité : +1 par dimension non-null. Spécificité > priorité.
     - Déduplique par `method_key` (garde le score le plus élevé)
     - `resolveMethodsForContext()` : filtre les providers inactifs/non-installés (utilisé par company)
     - `previewMethodsForContext()` : sans filtre provider (utilisé par admin pour prévisualisation)
  3. **Governance admin** — `PaymentModuleController` :
     - Install, activate, deactivate, update credentials, health check
     - Credentials stockés chiffrés en DB (`encrypted` cast)
     - Health check appelle l'adapter du provider
  4. **Rules admin** — `PaymentMethodRuleController` :
     - CRUD sur `platform_payment_method_rules`
     - Contrainte unique : `(method_key, provider_key, market_key, plan_key, interval)`
     - Preview endpoint pour simuler la résolution
  5. **Company billing** — `CompanyBillingController` :
     - `payment-methods` : résout via orchestrator pour le contexte de la company
     - `invoices`, `payments`, `portal-url` : stubs (retournent `[]`/`null` — intégration future)
     - `subscription` : retourne l'abonnement actif
  6. **Webhook idempotency** — `PaymentWebhookController` + `WebhookEvent` :
     - `POST /api/webhooks/payments/{providerKey}` — throttle 120 req/min, pas d'auth
     - Insert dans `webhook_events` avec contrainte unique `(provider_key, event_id)`
     - Si doublon → `200 { duplicate: true }` sans retraitement
     - Si nouveau → adapter.handleWebhookEvent() → status `processed` ou `failed`
  7. **Adapter pattern** — `PaymentProviderAdapter` interface :
     - `availableMethods()`, `healthCheck()`, `handleWebhookEvent()`
     - `InternalPaymentAdapter` : approbation manuelle, toujours healthy
     - `StripePaymentAdapter` : stub (SDK non installé, `RuntimeException`)
- **Tables** :
  - `platform_payment_modules` — Providers installés/actifs (credentials chiffrés)
  - `platform_payment_method_rules` — Règles de méthodes par contexte
  - `company_payment_customers` — Mapping company → customer ID provider
  - `company_payment_profiles` — Méthodes de paiement sauvegardées
  - `webhook_events` — Idempotency tracking
- **Fichiers clés** :
  - `app/Core/Billing/PaymentRegistry.php`, `PaymentModuleManifest.php`, `PaymentOrchestrator.php`
  - `app/Core/Billing/PlatformPaymentModule.php`, `PlatformPaymentMethodRule.php`
  - `app/Core/Billing/CompanyPaymentCustomer.php`, `CompanyPaymentProfile.php`, `WebhookEvent.php`
  - `app/Core/Billing/Contracts/PaymentProviderAdapter.php`
  - `app/Core/Billing/Adapters/InternalPaymentAdapter.php`, `StripePaymentAdapter.php`
  - `app/Core/Billing/ReadModels/PlatformPaymentGovernanceReadService.php`, `CompanyBillingReadService.php`
  - `app/Modules/Infrastructure/Webhooks/Http/PaymentWebhookController.php`
  - `app/Modules/Platform/Billing/Http/PaymentModuleController.php`, `PaymentMethodRuleController.php`
  - `app/Modules/Core/Billing/Http/CompanyBillingController.php`
  - `database/seeders/PaymentModuleSeeder.php`
  - `resources/js/modules/platform-admin/billing/billing.store.js`
- **Tests** :
  - `tests/Feature/WebhookIdempotencyTest.php` (4 tests)
  - `tests/Feature/PlatformPaymentModulesApiTest.php` (9 tests)
  - `tests/Feature/PlatformPaymentRulesApiTest.php` (8 tests)
  - `tests/Feature/CompanyBillingApiTest.php` (6 tests)
  - `tests/Unit/PaymentRuleEvaluationTest.php` (9 tests)
- **Conséquences** :
  - 36 tests couvrent registry, orchestration, governance, idempotency
  - Extensible : ajouter un provider = ajouter un module avec `payment_module` dans `module.json`
  - Spécificité > priorité : les règles les plus précises gagnent toujours
  - Idempotency garantie par contrainte DB — aucun doublon de traitement
  - Stub Stripe prêt pour intégration (adapter pattern en place)

---

## BMAD-UI-001 : Layout obligatoire par surface

- **Date** : 2026-02-25
- **Contexte** : Après ADR-124, risque récurrent d'oublier la déclaration de layout dans `definePage()`, ce qui casse la navigation (sidebar absente, guards ignorés).
- **Décision** : Toute page Vue auto-routée (`resources/js/pages/`) DOIT respecter les règles suivantes :

  | Surface | Fichier | `definePage()` meta obligatoire |
  |---------|---------|-------------------------------|
  | Platform | `pages/platform/**/*.vue` | `layout: 'platform'`, `platform: true` |
  | Platform (auth) | `pages/platform/login.vue`, etc. | `layout: 'blank'`, `platform: true` |
  | Company | `pages/company/**/*.vue` | Pas de `layout:` (default = company layout) |
  | Auth (public) | `pages/login.vue`, etc. | `layout: 'blank'`, `public: true` |

  **Exceptions** :
  - Les sous-composants `_Component.vue` ne sont PAS des pages — ils ne déclarent PAS `definePage()`.
  - Les layouts disponibles sont : `default` (company), `platform`, `blank`. Il n'y a PAS de `layouts/company.vue`.

  **Guards dépendants** :
  - `meta.platform: true` → scope platform (auth platform, permissions RBAC)
  - Absence de `meta.platform` → scope company (auth utilisateur, module gates)
  - `meta.layout: 'platform'` → sidebar/navbar platform
  - `meta.layout` absent → layout default (sidebar/navbar company)

- **Conséquences** :
  - Un test automatisé (`PageLayoutMetaTest`) vérifie cette règle sur toutes les pages
  - Toute page platform sans `layout: 'platform'` + `platform: true` sera détectée en CI
  - Cette règle est permanente et s'applique à toute nouvelle page créée

---

## ADR-125 : Realtime Event Infrastructure — SSE Invalidation Engine

- **Date** : 2026-02-25
- **Contexte** : Quand un admin modifie les permissions d'un rôle, les utilisateurs ayant ce rôle ne voient pas le changement en temps réel. Cause : le frontend cache `features:nav` et `auth:companies` dans sessionStorage (TTL 5 min). Le backend est 100% temps réel (prouvé par `CompanyNavRealtimeTest`). Palliatif initial : polling 30s + visibilitychange. Insuffisant : latence, trafic réseau, pas de scaling.
- **Décision** : Introduire une infrastructure SSE (Server-Sent Events) d'invalidation company-scoped.

  **Architecture** :
  1. `TopicRegistry` — registre fermé de 5 topics avec les clés de cache invalidées
  2. `RealtimePublisher` — interface (`publish(RealtimeEvent): void`) avec 2 adapters : `SseRealtimePublisher` (Redis sorted set), `NullRealtimePublisher` (no-op)
  3. `RealtimeStreamController` — endpoint SSE (`GET /api/realtime/stream`) avec polling Redis 1s + heartbeat 30s
  4. `RealtimeClient.js` — frontend EventSource avec reconnect (3 tentatives, backoff exponentiel), debounce 2s, fallback polling

  **Topics Phase 1** :

  | Topic | Invalidates | Déclencheurs |
  |-------|-------------|-------------|
  | `rbac.changed` | `features:nav`, `auth:companies` | Role CRUD |
  | `modules.changed` | `features:nav`, `features:modules` | Module enable/disable/settings |
  | `plan.changed` | `features:nav`, `features:modules`, `auth:companies` | Plan change |
  | `jobdomain.changed` | `features:nav`, `features:modules`, `tenant:jobdomain` | Jobdomain assign |
  | `members.changed` | `auth:companies` | Member add/remove/role change |

  **Invariants** :
  - Publish uniquement APRÈS le commit DB (jamais dans la transaction)
  - Aucune logique métier dans le publisher
  - Topics = enum fermé (TopicRegistry), pas de topic ad hoc
  - Invalidation uniquement, pas de state transfer
  - Multi-tenant isolation : Redis key = `{prefix}:company:{companyId}`
  - Rollback : `REALTIME_DRIVER=null` → NullPublisher + polling fallback

- **Status** : Accepted — Phase 1 Implemented
- **Conséquences** :
  - 9 fichiers créés, 8 modifiés, 0 migrations DB
  - 11 publish points dans 5 controllers (Roles ×3, Modules ×3, Plan ×1, Members ×3, Jobdomain ×1)
  - Latence : ~1-3s (vs 30s polling) quand SSE actif
  - Fallback automatique vers polling 30s si SSE échoue
  - 503 tests, 1494 assertions — all green, pnpm build clean

---

## ADR-126 : EventEnvelope + Realtime Backbone v1

- **Date** : 2026-02-25
- **Status** : Implemented
- **Contexte** : ADR-125 Phase 1 livre un backbone invalidation-only. La decision strategique B confirme l'evolution vers un backbone temps reel complet (domain events, notifications, audit, security). Le VO actuel `RealtimeEvent(topic, companyId, payload, timestamp)` ne supporte ni categories, ni targeting user, ni versioning.
- **Décision** : Remplacer `RealtimeEvent` par `EventEnvelope` (id ULID, topic, category, version, company_id, user_id?, payload, invalidates[], timestamp). Introduire 5 categories : `invalidation` | `domain` | `notification` | `audit` | `security`. TopicRegistry v2 multi-categorie avec targeting et versioning. Frontend ChannelRouter dispatche par categorie vers handlers dedies (InvalidationHandler inchange, DomainEventBus, NotificationStore, AuditLiveStore, SecurityAlertHandler).

  **Backward compat** : `version=2 + category=invalidation` produit un SSE identique a Phase 1. Zero breaking change.

  **Depends on** : ADR-125 (Phase 1 done)
  **Blocks** : ADR-127, ADR-129, ADR-130

- **Conséquences** :
  - Backend : nouveau VO, TopicRegistry v2, SseRealtimePublisher adapte
  - Frontend : ChannelRouter + handlers par categorie
  - Rollback : revert vers `RealtimeEvent` VO. ChannelRouter ignore les categories inconnues (forward-compat)

---

## ADR-127 : Subscription Protocol + Connection Governance

- **Date** : 2026-02-25
- **Status** : Implemented
- **Contexte** : Phase 1 envoie tous les events a tous les clients. Pas de filtre, pas de rate limit, pas de gouvernance des connexions. Un user peut ouvrir N streams simultanes (tab-hoarding = epuisement pool PHP-FPM).
- **Décision** : Le client specifie `?categories=invalidation,domain` ou `?topics=rbac.changed` au connect SSE. Le serveur filtre les events AVANT envoi. Rate limits : 1 stream/user/company (middleware Redis), max N streams/company (configurable, default 100), anti-abuse throttle (5 connect/min/user). Connection lifecycle tracking via Redis (INCR/DECR sur connect/disconnect). Endpoints platform de gouvernance : `GET /api/platform/realtime/status|metrics|connections`, `POST /api/platform/realtime/flush|kill-switch` (permission `manage_system`).

  **Backward compat** : pas de query param = recevoir tout (comportement Phase 1 preserve).

  **Depends on** : ADR-126 (needs categories to filter)

- **Conséquences** :
  - Backend : filter logic dans RealtimeStreamController, rate limit middleware, 5 endpoints admin
  - Frontend : RealtimeClient envoie preferences category/topic
  - Platform : dashboard de gouvernance (cards, tables, kill-switch)
  - Rollback : retirer filter logic → tous events envoyes. Retirer rate limit middleware.

---

## ADR-128 : Octane Adoption for Realtime Scale

- **Date** : 2026-02-25
- **Status** : Phase 1 implemented — PubSubTransport + dual-write
- **Contexte** : Phase 1-2 utilise PHP-FPM : 1 SSE connection = 1 worker bloque pendant 5 min. Avec `pm.max_children=30`, max ~25 SSE connections simultanees. Bottleneck structurel au-dela de 50 users simultanes.
- **Décision** : Adopter Laravel Octane (Swoole ou FrankenPHP) pour le endpoint SSE. Remplacer la boucle `usleep(1s)` + sorted set polling par Redis SUBSCRIBE coroutine. Etape intermediaire : pool PHP-FPM dedie pour le SSE (routing Nginx). Octane uniquement quand le seuil de >100 SSE connections simultanees est atteint.

  **Transport abstraction (implemented)** :
  - `StreamTransport` interface: `poll(redisKey, lastTimestamp)` + `sleep()`
  - `PollingTransport`: FPM-compatible impl (usleep 1s + zrangebyscore)
  - `PubSubTransport`: fast-poll FPM-compatible impl (usleep 100ms + zrangebyscore)
  - `RealtimeStreamController` depends on `StreamTransport` (injected)
  - Bound in `AppServiceProvider` via `config('realtime.transport')`:
    - `polling` (default) → `PollingTransport` (1s sleep, ~1s latency)
    - `pubsub` → `PubSubTransport` (100ms sleep, ~100ms latency)

  **Dual-write (implemented)** :
  - `SseRealtimePublisher::publish()` now does ZADD + PUBLISH
  - PUBLISH channel: `{prefix}:pubsub:company:{id}` or `{prefix}:pubsub:platform`
  - Fire-and-forget: if no subscriber listens, message is lost (sorted set = durable source)
  - Under Octane, PubSubTransport can drain SUBSCRIBE into SplQueue (future phase)

  **Config** : `REALTIME_TRANSPORT=polling|pubsub` in `.env` (config `realtime.transport`)

  **Delivery latency tracking** : RealtimeStreamController records `(now - event.score) * 1000` ms per delivered event via MetricsCollector.

  **Architecture** :
  ```
  Controller (mutation)
       │
       ▼
  SseRealtimePublisher::publish(envelope)
       │
       ├─ ZADD  {prefix}:company:{id}          ← durable sorted set (source of truth)
       ├─ ZREMRANGEBYSCORE (GC > 2min)
       ├─ EXPIRE (safety TTL 5min)
       ├─ HINCRBY metrics counter
       └─ PUBLISH {prefix}:pubsub:company:{id} ← fire-and-forget notification
       │
       ▼
  RealtimeStreamController (SSE loop)
       │
       ├─ StreamTransport::poll(key, lastTs)    ← ZRANGEBYSCORE (identical for both transports)
       ├─ StreamTransport::sleep()              ← 1s (polling) or 100ms (pubsub)
       ├─ sendEvent(sseType, data)
       └─ MetricsCollector::recordDeliveryLatency(now - event.score)
  ```

  **Fichiers Phase 1** :
  | Fichier | Role |
  |---------|------|
  | `app/Core/Realtime/Contracts/StreamTransport.php` | Interface: `poll()` + `sleep()` |
  | `app/Core/Realtime/Transports/PollingTransport.php` | usleep(1s) + ZRANGEBYSCORE |
  | `app/Core/Realtime/Transports/PubSubTransport.php` | usleep(100ms) + ZRANGEBYSCORE |
  | `app/Core/Realtime/Adapters/SseRealtimePublisher.php` | ZADD + PUBLISH dual-write |
  | `app/Modules/Infrastructure/Realtime/Http/RealtimeStreamController.php` | SSE stream + delivery latency |
  | `app/Modules/Platform/Realtime/Http/RealtimeGovernanceController.php` | `/status` expose transport actif |
  | `config/realtime.php` | `transport` = `polling` ou `pubsub` |
  | `app/Providers/AppServiceProvider.php` | Binding conditionnel StreamTransport |
  | `resources/js/pages/platform/security/_SecurityRealtime.vue` | Card Transport dans monitoring |
  | `tests/Unit/PubSubTransportTest.php` | 6 tests (poll, sleep, graceful, channels) |

  **Contrainte FPM** : Predis `subscribe()` est bloquant — impossible de poll + subscribe en parallele sans coroutines. Le PubSubTransport sous FPM compense par un poll 10x plus frequent. Le vrai Redis SUBSCRIBE sera pour Octane (phase ulterieure, SplQueue + coroutine drain).

  **Tradeoff CPU** : `pubsub` transport fait 10x plus de ZRANGEBYSCORE/s par connection SSE. Acceptable pour < 100 connections. Au-dela, passer a Octane + vrai SUBSCRIBE.

  **Migration path** : PHP-FPM standard (< 50) → PHP-FPM pool dedie (50-200) → Octane + Redis PubSub (200-1000) → Octane horizontal + Redis Cluster (> 1000)

  **Depends on** : ADR-126 (format envelope stable avant changement infra)
  **Trigger** : > 100 SSE connections simultanees observees

- **Conséquences** :
  - Infrastructure : nouveau modele de deploiement
  - Backend : RealtimeStreamController uses StreamTransport abstraction (ready for swap)
  - `REALTIME_TRANSPORT=pubsub` gives ~100ms latency under FPM at higher CPU cost
  - Platform governance `/status` endpoint exposes active transport
  - Delivery latency mesuree et visible dans `/platform/api/realtime/metrics`
  - Transparent pour le frontend (meme protocole SSE)
  - Rollback : `REALTIME_TRANSPORT=polling` (defaut) — pas de perte de donnees

---

## ADR-129 : Security Events + Alerting Pipeline

- **Date** : 2026-02-25
- **Status** : Implemented
- **Contexte** : Le SaaS n'a aucun mecanisme de detection de comportement suspect interne (login brute force, mass role changes, permission flips, tab flooding). Besoin d'observation + alerte sans bloquer les actions.
- **Décision** : Ajouter `category=security` au backbone. SecurityDetector service avec compteurs Redis sliding window (~0.1ms overhead). Table `security_alerts` (ULID, alert_type, severity, evidence, status lifecycle). 8 alert types initiaux : `suspicious.login_attempts`, `mass.role_changes`, `abnormal.module_toggling`, `excessive.stream_connections`, `rapid.permission_flips`, `bulk.member_removal`, `unauthorized.access_pattern`, `session.anomaly`. Pipeline d'escalade : realtime push → platform notification → (future email/SMS).

  **Regle critique** : le SecurityDetector ne bloque JAMAIS une action. Il observe et alerte. Le blocking est une decision humaine.

  **Depends on** : ADR-126 (`category=security` dans envelope)

- **Conséquences** :
  - Backend : SecurityDetector, SecurityAlert model, endpoints, registre ferme d'alert types
  - Frontend : SecurityAlertHandler (platform only), Platform Security Dashboard
  - DB : 1 table `security_alerts`
  - Rollback : desactiver SecurityDetector (config flag). Purement additif.

---

## ADR-130 : Audit System Architecture

- **Date** : 2026-02-25
- **Status** : Implemented
- **Contexte** : Aucun audit trail permanent. Les mutations sont tracees uniquement dans les logs applicatifs (ephemeres). Besoin de compliance, tracabilite, et observabilite pour platform et company admins.
- **Décision** : Double audit log : `platform_audit_logs` (actions platform-admin, permanent, append-only) + `company_audit_logs` (actions company-scoped, tenant-isole, retention configurable par plan). AuditLogger service ecrit en DB synchrone AVANT le publish realtime. La DB est la source de verite, le realtime est un complement live. Audit logs = append-only, immutable (aucun UPDATE, aucun DELETE). Rollback = compensating actions (nouvelle mutation tracee), jamais de DB rewind.

  **Anti-patterns interdits** : auto-rollback sur anomalie, DELETE/UPDATE/TRUNCATE sur audit_logs, mutation automatique non controlee.

  **Depends on** : ADR-126 (`category=audit` dans envelope)

- **Conséquences** :
  - Backend : AuditLogger service, 2 tables, endpoints platform + company avec filtres
  - Frontend : AuditLiveStore (platform), futur company audit panel
  - DB : 2 tables (`platform_audit_logs`, `company_audit_logs`)
  - Rollback : desactiver audit publish (config flag). Tables restent (regulatory).

---

## ADR-131 : Merge Realtime into Security & Auto Kill Switch

- **Date** : 2026-02-26
- **Status** : Implemented
- **Contexte** : La page `/platform/realtime` est un dashboard ops manuel pour surveiller le backbone SSE. La page `/platform/security` gere les alertes de securite. Les deux servent l'observabilite admin. Le kill switch manuel est un vestige — les protections automatiques (limites connexions, SecurityDetector) sont deja en place. On fusionne et on automatise.
- **Décision** : Fusionner `platform.realtime` dans `platform.security` → une seule page "Security & Monitoring" a onglets (Alerts | Monitoring). Automatiser le kill switch : `EventFloodDetector` surveille le volume global d'events via Redis INCR. Si > threshold (config: `event_flood_threshold`, default 1000) dans window (config: `event_flood_window`, default 300s) → kill switch auto active + alerte security critique + audit log. Guard : un seul alert par fenetre (Redis flag). `RealtimeModule` supprime, routes realtime absorbees sous `module.active:platform.security` avec permission `manage_realtime`.

  **Depends on** : ADR-127 (Realtime Governance), ADR-129 (Security Alerting)

- **Conséquences** :
  - Backend : `EventFloodDetector`, `AlertTypeRegistry` +1 type, routes consolidees, migration cleanup
  - Frontend : security store etendu, `_SecurityRealtime` sub-component, onglets dans security page
  - La route `/platform/realtime` n'existe plus (404)
  - Rollback : re-creer `RealtimeModule`, re-separer les routes, supprimer `EventFloodDetector`

---

## ADR-132 : Unified Permission Architecture (Scope-Aware)

- **Date** : 2026-02-26
- **Status** : Ready
- **Depends on** : ADR-113 (Unified Module Engine)

### Contexte

Platform et Company ont deux systèmes de permissions parallèles construits sur le même `ModuleManifest` mais divergents en schéma DB, API endpoint shape, et rendu frontend.

**Company scope** (état actuel — complet) :
- `company_permissions` table : `key, label, module_key, is_admin`
- `CompanyPermissionCatalog::sync()` écrit `module_key` + `is_admin` en DB
- `CompanyRoleController::permissionCatalog()` enrichit avec hints, bundles, module_active, icons
- Frontend `roles.vue` : Simple mode (capability bundles par module) + Advanced mode (permissions individuelles par module) + inactive module handling
- 20 permissions across 7 core + 1 business module, 13 bundles

**Platform scope** (état actuel — lacunaire) :
- `platform_permissions` table : `key, label` seulement (PAS de `module_key`, PAS de `is_admin`)
- `PlatformPermissionCatalog::sync()` n'écrit PAS `module_key` en DB (pourtant il le calcule dans `all()`)
- `PermissionController::index()` retourne un flat array `[{id, key, label}]` sans enrichissement
- Frontend `roles.vue` : VSelect flat avec chips, zéro groupement par module
- 20 permissions across 14 admin-scope modules, **17 bundles déjà déclarés dans les manifests mais jamais exposés**

**Constat critique** : Les platform modules **déclarent déjà** `bundles` et `permissions` avec structure complète dans leurs `ModuleManifest`. Le `PlatformPermissionCatalog::all()` **calcule déjà** le `module_key`. Toute l'information existe — elle est simplement perdue lors du sync DB et jamais exposée par l'API.

### Décision

**Option A retenue** : Normaliser les deux tables existantes (pas de merge en une seule table).

Justification : Platform et Company ont des pivots différents (`platform_role_permission` vs `company_role_permission`), des modèles de rôles différents (pas de `company_id` côté platform), et des guards d'auth différents (`auth:platform` vs `auth:sanctum`). Merger introduirait un scope column + polymorphic pivots sans gain fonctionnel. L'unification se fait au niveau du **service catalog** et du **contrat API**.

#### B1. Schema Normalization — `platform_permissions` table

**Migration** : Ajouter `module_key VARCHAR(50) INDEXED` + `is_admin BOOLEAN DEFAULT false` + `hint VARCHAR(255) NULLABLE`.

Backfill : `PlatformPermissionCatalog::sync()` remplit automatiquement (les données viennent de `ModuleManifest`).

Résultat : les deux tables ont un schéma symétrique :
```
company_permissions:  id, key, label, module_key, is_admin, timestamps
platform_permissions: id, key, label, module_key, is_admin, timestamps
```

> Note: `hint` reste dans le manifest uniquement (pas en DB). Le catalog endpoint le lit à la volée depuis `ModuleManifest`, comme le fait déjà `CompanyRoleController::permissionCatalog()`.

#### B2. `PlatformPermissionCatalog::sync()` — Normalisation

Aligner sur `CompanyPermissionCatalog::sync()` :
```php
PlatformPermission::updateOrCreate(
    ['key' => $permission['key']],
    [
        'label' => $permission['label'],
        'module_key' => $permission['module_key'],  // AJOUT
        'is_admin' => $permission['is_admin'] ?? false,  // AJOUT
    ],
);
```

`PlatformPermissionCatalog::all()` retourne déjà `module_key`. Ajouter `is_admin` (les manifests platform n'en déclarent pas encore → default false, futur-proof).

#### B3. Unified Catalog Contract — Platform `PermissionController`

Remplacer le flat `GET /permissions` par un `permissionCatalog()` endpoint miroir de `CompanyRoleController::permissionCatalog()`.

**Contrat API unifié** (identique pour les deux scopes) :

```json
{
  "permissions": [
    {
      "id": 1,
      "key": "manage_companies",
      "label": "Manage Companies",
      "module_key": "platform.companies",
      "is_admin": false,
      "module_name": "Companies",
      "module_description": "Manage companies and their users",
      "hint": "",
      "module_active": true
    }
  ],
  "modules": [
    {
      "module_key": "platform.companies",
      "module_name": "Companies",
      "module_description": "Manage companies and their users",
      "module_icon": "tabler-building",
      "module_active": true,
      "is_core": false,
      "capabilities": [
        {
          "key": "companies.supervision",
          "label": "Company Supervision",
          "hint": "Manage companies and view their users.",
          "is_admin": false,
          "permissions": ["manage_companies", "view_company_users"],
          "permission_ids": [1, 2]
        }
      ]
    }
  ]
}
```

**Différence scope** :
- Company : `module_active` dépend de `ModuleGate::isActive($company, $key)` (per-company activation)
- Platform : `module_active` = toujours `true` (admin modules always active, pas de per-tenant activation)

#### B4. Shared Catalog Service — `PermissionCatalogBuilder`

Extraire la logique d'enrichissement (actuellement inline dans `CompanyRoleController::permissionCatalog()`) en un service réutilisable :

```
App\Core\RBAC\PermissionCatalogBuilder
```

Méthode publique :
```php
public static function build(string $scope, ?Company $company = null): array
// $scope = 'company' | 'admin'
// $company = required for 'company' scope (module activation check)
// Returns: ['permissions' => [...], 'modules' => [...]]
```

Les deux controllers (`CompanyRoleController::permissionCatalog()` et platform `PermissionController::index()`) délèguent à ce builder. Zéro duplication de logique.

#### B5. `PlatformPermission` Model — Normalisation

```php
protected $fillable = ['key', 'label', 'module_key', 'is_admin'];

protected function casts(): array {
    return ['is_admin' => 'boolean'];
}
```

Aligné sur `CompanyPermission`.

#### B6. Platform Roles Store — Normalisation

Ajouter `_permissionModules` state + `permissionModules` getter (symétrique au company settings store) :

```javascript
state: () => ({
  _roles: [],
  _permissionCatalog: [],
  _permissionModules: [],  // AJOUT
}),

async fetchPermissionCatalog() {
  const data = await $platformApi('/permissions')
  this._permissionCatalog = data.permissions
  this._permissionModules = data.modules || []  // AJOUT
}
```

#### B7. Shared `_PermissionMatrix.vue` Sub-Component

Extraire la logique de rendu permissions de `company/roles.vue` (lignes 72-913, ~840 lignes de template) en un sous-composant partagé :

```
resources/js/pages/shared/_PermissionMatrix.vue
```

**Props** :
| Prop | Type | Description |
|------|------|-------------|
| `permissionCatalog` | `Array` | Enriched permissions flat array |
| `permissionModules` | `Array` | Modules with capabilities/bundles |
| `selectedPermissions` | `Array<number>` | Selected permission IDs (v-model) |
| `isAdministrative` | `Boolean` | Management vs Operational toggle state |
| `scope` | `'company'\|'platform'` | Scope flag |

**Events** :
| Event | Payload | Description |
|-------|---------|-------------|
| `update:selectedPermissions` | `Array<number>` | Emitted on permission toggle |

**Internal state** :
- `isAdvancedMode` : Simple/Advanced toggle (local)
- Computed : `capabilityModules`, `coreModules`, `businessModules`, `unbundledModules`, `permissionGroups`
- Methods : `getCapabilityState()`, `toggleCapability()`, `togglePermission()`, `isPermissionChecked()`

**Scope-specific behavior** :
- `scope='company'` : Shows inactive module permissions section (modules can be deactivated per-company)
- `scope='platform'` : All modules always active → inactive section never rendered
- `scope='company'` : Splits modules into core (prefixed `core.`) vs business
- `scope='platform'` : All modules are admin-scope → no core/business split needed, but CAN reuse the visual grouping. GroupBy manifest `surface` (structure vs operations) or simply list all.

**i18n** : The component uses the same i18n fallback pattern (`permissionCatalog.modules.{key}.name` → API label). No scope-specific i18n.

Both `company/roles.vue` and `platform/roles.vue` import `_PermissionMatrix` and pass their store data as props.

#### B8. Platform Role Level — `is_administrative` concept

Company roles have `is_administrative` boolean (Management vs Operational). Platform roles do NOT have this concept currently.

**Decision** : Do NOT add `is_administrative` to platform roles.

Justification : Platform has `super_admin` (structural bypass) + custom roles. There's no equivalent "admin permission gating" need — all platform permissions are administrative by nature. Adding the toggle would create UX confusion. The `_PermissionMatrix` component receives `isAdministrative` as prop — platform always passes `true` (all permissions visible, no stripping).

#### B9. Permission Key Mapping — Backfill

`PlatformPermissionCatalog::all()` already returns `module_key` for every permission. The migration backfill is trivially computed by re-running `sync()`.

Explicit mapping (for traceability) :

| Permission Key | module_key |
|---------------|-----------|
| `manage_companies` | `platform.companies` |
| `view_company_users` | `platform.companies` |
| `manage_plans` | `platform.plans` |
| `manage_markets` | `platform.markets` |
| `manage_translations` | `platform.translations` |
| `manage_platform_users` | `platform.users` |
| `manage_platform_user_credentials` | `platform.users` |
| `manage_roles` | `platform.roles` |
| `manage_modules` | `platform.modules` |
| `manage_field_definitions` | `platform.fields` |
| `manage_jobdomains` | `platform.jobdomains` |
| `manage_theme_settings` | `platform.settings` |
| `manage_session_settings` | `platform.settings` |
| `manage_maintenance` | `platform.settings` |
| `manage_billing` | `platform.billing` |
| `view_billing` | `platform.billing` |
| `view_audit_logs` | `platform.audit` |
| `manage_security_alerts` | `platform.security` |
| `manage_realtime` | `platform.security` |
| `manage_audience` | `platform.audience` |

20 permissions across 14 modules. 17 bundles already declared in manifests.

### Implementation Plan — Strict Dependency Order

```
Phase 1: Backend Schema (no frontend changes, no API break)
  ├─ 1a. Migration: add module_key + is_admin to platform_permissions
  ├─ 1b. PlatformPermission model: add fillable + casts
  ├─ 1c. PlatformPermissionCatalog::sync(): write module_key + is_admin
  ├─ 1d. Run migration + re-sync (backfill)
  └─ Tests: verify all 20 permissions have module_key after sync

Phase 2: Shared Catalog Service (still no API break)
  ├─ 2a. Create PermissionCatalogBuilder service
  ├─ 2b. Refactor CompanyRoleController::permissionCatalog() to use builder
  └─ Tests: company catalog endpoint returns identical response

Phase 3: Platform Catalog Endpoint (API break — coordinated)
  ├─ 3a. Refactor PermissionController::index() to use PermissionCatalogBuilder
  ├─ 3b. Response now returns {permissions: [...enriched], modules: [...with bundles]}
  └─ Tests: platform catalog endpoint returns correct structure

Phase 4: Frontend — Store + Shared Component
  ├─ 4a. Platform roles store: add _permissionModules state
  ├─ 4b. Extract _PermissionMatrix.vue from company/roles.vue
  ├─ 4c. Rewire company/roles.vue to use _PermissionMatrix
  ├─ 4d. Rewire platform/roles.vue to use _PermissionMatrix
  └─ Tests: both pages render module-grouped permissions

Phase 5: Cleanup
  ├─ 5a. i18n: add platform module/bundle/permission keys
  └─ 5b. Verify: identical UX on both scopes
```

### Conséquences

- **Platform roles page** : gains module grouping, bundles, simple/advanced modes, hints, icons
- **Code reduction** : ~840 lines of permission template extracted from company/roles.vue into shared component
- **Zero company-side regression** : Same enriched API contract, same rendering
- **Schema symmetry** : Both permission tables have same columns
- **Catalog service** : Single PermissionCatalogBuilder — no more inline enrichment
- **No new tables** : Just 2 columns added to existing table + migration
- **No new models** : Existing models updated
- **`super_admin` bypass** : Unchanged — bypasses at `PlatformUser::hasPermission()` level, not at UI level

## ADR-133 : RBAC Capability Model — Bundle-First Enforcement

- **Date** : 2026-02-27
- **Status** : Implemented
- **Depends on** : ADR-054 (Capability Abstraction Layer), ADR-132 (Unified Permission Architecture)

### Contexte

3 modules platform exposent des permissions sans capability bundles :
- `platform.audience` — `manage_audience` (aucun bundle)
- `platform.markets` — `manage_markets` (aucun bundle)
- `platform.translations` — `manage_translations` (aucun bundle)

Les 17 autres modules avec permissions ont une couverture bundle à 100%. Les 3 fautifs provoquent un rendu incohérent dans `_PermissionMatrix.vue` (section séparée "unbundled" au lieu de s'intégrer aux autres modules).

### Décision

**Règle architecturale** : tout module déclarant des `permissions` DOIT déclarer au moins un `bundle` couvrant l'ensemble de ses permissions. Aucun module à permissions plates n'est autorisé.

**Correctifs appliqués** :
- `platform.markets` : ajout bundle `markets.governance` → `[manage_markets]`
- `platform.audience` : ajout bundle `audience.management` → `[manage_audience]`
- `platform.translations` : ajout bundle `translations.management` → `[manage_translations]`

**Validation** : `ModuleManifestIntegrityTest::test_modules_with_permissions_must_have_bundles()`
- Échoue si un module avec permissions n'a pas de bundles
- Échoue si une permission n'est couverte par aucun bundle
- Empêche toute dérive future

### Conséquences

- Tous les modules (company + platform) passent par le rendu capability dans `_PermissionMatrix`
- Les futurs modules DOIVENT déclarer des bundles ou le test CI échoue
- Si un module n'a qu'un seul groupe logique de permissions, un bundle synthétique suffit (ex: `audience.management`)
- Aucune modification du `PermissionCatalogBuilder` — la correction est à la source (manifests)
- Aucune modification des clés de permissions existantes

### Risques et Rollback

**Breaking changes** :
- Phase 3 changes the `GET /permissions` response shape. The only consumer is `platform/roles.vue` (same deploy).
- No external API consumers.

**Feature flags** : None needed. All changes are deployed together (same PR). If partial rollback required:
- Phases 1-2 are invisible (no API break, no UI change)
- Phase 3-4 must ship together (API + frontend coordinated)

**Rollback** :
- Revert Phase 4 frontend changes → platform roles falls back to flat VSelect
- Revert Phase 3 → PermissionController returns flat array
- Phases 1-2 are inert (extra columns, unused service) — can remain

**Regression cases** (test plan) :
- Backend:
  - `PlatformPermissionCatalog::sync()` writes `module_key` for all 20 permissions
  - `PermissionCatalogBuilder::build('admin')` returns 14 modules with 17 bundles
  - `PermissionCatalogBuilder::build('company', $company)` returns same shape as current `permissionCatalog()`
  - Platform role CRUD still works (store, update with permissions, super_admin protection)
  - Company role CRUD unchanged (invariant: `syncPermissionsSafe`, admin permission stripping)
- Frontend:
  - Company roles page renders identically (regression test with screenshots)
  - Platform roles page renders module-grouped permissions in drawer
  - Simple/Advanced toggle works on both scopes
  - `super_admin` role still shows "all permissions" bypass indicator
  - Capability toggle (check/uncheck/indeterminate) works on platform scope

## ADR-134 : Jobdomain Immutability — Verrouillage après assignation

- **Date** : 2026-02-27
- **Status** : Implemented
- **Depends on** : ADR-009 (Jobdomain = profil déclaratif), ADR-025 (Company = exactement 1 jobdomain)

### Contexte

Le jobdomain (`company_jobdomain` pivot) pouvait être changé après l'assignation initiale via `PUT /api/company/jobdomain`. Ce changement provoque un drift structurel dans 5 sous-systèmes :

1. **Module activation drift** — les modules activés par l'ancien jobdomain restent actifs mais sont potentiellement incompatibles
2. **Role bundle mismatch** — les rôles seedés par l'ancien profil ne correspondent plus aux bundles du nouveau
3. **Field preset incoherence** — les `FieldActivation` de l'ancien profil persistent (pas de cleanup)
4. **Permission orphaning** — les permissions rattachées aux anciens modules restent assignées aux rôles
5. **Billing entitlement incoherence** — les entitlements calculés via `EntitlementResolver` deviennent incohérents

`JobdomainGate::assignToCompany()` est idempotent pour l'ajout (updateOrCreate) mais ne supprime jamais les artefacts du profil précédent. Il n'existe pas de moteur de migration inter-jobdomain.

### Décision

**Le jobdomain est immuable une fois assigné.** Guard applicatif (pas DB) :

- `CompanyJobdomainController::update()` : si `$company->jobdomain !== null` → retourne `422` avec message explicite
- L'assignation initiale (company sans jobdomain) reste fonctionnelle
- Le flux d'inscription (`POST /api/register` avec `jobdomain_key`) n'est pas impacté
- Aucune modification de `JobdomainGate`, `EntitlementResolver`, ou `ModuleActivationEngine`

**Frontend** : la page `/company/jobdomain` passe en lecture seule si un jobdomain est déjà assigné :
- Bandeau warning avec icône `tabler-lock`
- Boutons "Select" masqués
- Message : "Votre profil sectoriel est verrouillé"

### Conséquences

- Élimine les 5 catégories de drift structurel
- Pas de migration DB nécessaire (guard applicatif uniquement)
- Les seeders et tests continuent de fonctionner (assignation initiale)
- Le test `test_cannot_change_jobdomain_once_assigned` valide l'invariant
- Le test `test_can_assign_jobdomain_when_none_set` valide que l'assignation initiale fonctionne

### Risques et Rollback

**Reversibilité** : supprimer le guard dans `CompanyJobdomainController::update()` + retirer `isLocked` du template Vue. Changement de 2 fichiers, aucune migration à reverter.

**Phase future** : si un override platform super_admin est nécessaire, ajouter un endpoint dédié `POST /api/platform/companies/{id}/force-jobdomain` avec audit log renforcé et cascade de cleanup. Hors scope de cette ADR.

---

## ADR-135 : Billing Engine v1 — Wallet-First, Policy-Driven, DB-First

- **Date** : 2026-02-27
- **Contexte** : Le système de billing existant ne supporte que les changements de plan via NullPaymentGateway (souscriptions en pending → approbation admin). Pas de factures réelles, pas de wallet, pas de credit notes, pas de prorata, pas de dunning. Les politiques de facturation sont stockées en JSON blob dans `platform_settings.billing`.

### Décision

Implémenter un moteur de facturation complet en 8 lots (LOT 1 = fondations) avec 3 piliers structurels :

#### Pilier 1 : Wallet/Ledger obligatoire

- `company_wallets` — un wallet par company, `cached_balance` (cache de performance)
- `company_wallet_transactions` — ledger append-only immutable (source de vérité)
- Flux **wallet-first** : `invoice.total → wallet credit → remaining → provider charge`
- Locking : `SELECT FOR UPDATE` sur le wallet avant tout débit
- Invariant : `cached_balance = SUM(credits) - SUM(debits)`, recomputé sous verrou

#### Pilier 2 : BillingPolicy entity dédiée

- `platform_billing_policies` — table singleton avec 20 colonnes typées
- Remplace le JSON blob `platform_settings.billing`
- Paramètres : `wallet_first`, `upgrade_timing`, `downgrade_timing`, `proration_strategy`, `grace_period_days`, `max_retry_attempts`, `retry_intervals_days`, `failure_action`, `invoice_due_days`, `invoice_prefix`, `tax_mode`, `default_tax_rate_bps`, `free_trial_days`, `addon_billing_interval`, etc.
- Timing values : `immediate`, `end_of_period`, `end_of_trial`

#### Pilier 3 : Moteur unifié plan + addons

- Un seul moteur de facturation pour les souscriptions de plan ET les modules addon
- `invoice_lines` avec type `plan`, `addon`, `proration`, `adjustment`
- `ModuleQuoteCalculator` intégré dans le pipeline de facturation

### Schema

| Table | Type | Colonnes clés |
|-------|------|---------------|
| `platform_billing_policies` | NEW | 20 paramètres typés, singleton |
| `company_wallets` | NEW | `company_id` (unique), `currency`, `cached_balance` (bigint) |
| `company_wallet_transactions` | NEW | `wallet_id`, `type`, `amount`, `balance_after`, `source_type`, `idempotency_key` |
| `invoices` | ALTER | +`number`, `subtotal`, `tax_amount`, `wallet_credit_applied`, `amount_due`, `period_start/end`, `billing_snapshot`, `finalized_at` |
| `invoice_lines` | NEW | `invoice_id`, `type`, `module_key`, `quantity`, `unit_amount`, `amount` |
| `credit_notes` | NEW | `number`, `company_id`, `invoice_id`, `amount`, `status`, `wallet_transaction_id` |

### Services (LOT 1)

| Service | Responsabilité |
|---------|----------------|
| `WalletLedger` | Credit/debit avec locking, idempotency, recompute |
| `InvoiceNumbering` | Numérotation séquentielle sans trous (SELECT FOR UPDATE minimal) |
| `InvoiceIssuer` | Création draft → ajout lines → finalize (compute + wallet + number + snapshot) |
| `CreditNoteIssuer` | Draft → issue → apply (→ wallet credit) |
| `TaxResolver` | Calcul taxe (none/exclusive/inclusive), basis points, floor |

### Invariants

1. **W1** : `wallet.cached_balance = SUM(credits) - SUM(debits)` — recomputé sous FOR UPDATE
2. **W2** : `cached_balance >= 0` sauf si `allow_negative_wallet` — enforcement applicatif
3. **I1** : `invoice.amount = invoice.subtotal + invoice.tax_amount` — computé à la finalisation
4. **I2** : `invoice.amount_due = invoice.amount - invoice.wallet_credit_applied`
5. **I3** : `SUM(invoice_lines.amount) = invoice.subtotal`
6. **I4** : Numérotation séquentielle sans trous — SELECT FOR UPDATE sur policy row
7. **I5** : Invoice immutable après `finalized_at IS NOT NULL` — seuls `notes`, `retry_count`, `status`, `paid_at`, `voided_at` modifiables
8. **C1** : Credit note `applied` → `wallet_transaction_id IS NOT NULL`

### Règles de type

- Tous les montants en cents : `BIGINT` (cumuls peuvent dépasser 32-bit)
- Pas de CHECK constraint DB (enforcement applicatif + tests)
- Proration : `remaining_days / total_days × price`, floor systématique (favorable company)
- Delta résiduel (1-2 cents) jamais compensé

### Lots

| LOT | Scope |
|-----|-------|
| 1 | Fondations : Invoice Core + Credit Notes + Wallet + BillingPolicy + TaxResolver |
| 2 | Proration + Timing (upgrade/downgrade) |
| 3 | Payment Engine wallet-first |
| 4 | Dunning |
| 5 | Tax Engine (market-based) |
| 6 | UI Company (factures, wallet, billing) |
| 7 | UI Platform (governance, policies, audit) |
| 8 | Audit + E2E + migration platform_settings.billing |

### LOT 2 : Plan Change + Proration + Trial Timing

#### Schema LOT 2

| Table | Type | Colonnes clés |
|-------|------|---------------|
| `plan_change_intents` | NEW | `company_id`, `from_plan_key`, `to_plan_key`, `interval_from/to`, `timing`, `effective_at`, `proration_snapshot`, `status`, `idempotency_key` |
| `subscriptions` | ALTER | +`interval` (monthly/yearly), `trial_ends_at`, `cancel_at_period_end` |

#### Services LOT 2

| Service | Responsabilité |
|---------|----------------|
| `PlanChangeIntent` | Modèle persistent — intent de changement de plan, statut scheduled/executed/cancelled |
| `ProrationCalculator` | Calcul pur, déterministe, sans DB. `(old_price, new_price, period, change_date) → {credit, charge, net}` |
| `PlanChangeExecutor` | Pipeline transactionnel : schedule → execute. Batch `executeScheduled()` pour cron |

#### Invariants LOT 2

1. **P1** : 1 seul intent `scheduled` par company à un instant donné
2. **P2** : Intent `executed` = immutable (status + executed_at frozen)
3. **P3** : Proration = `floor(remaining_days / total_days × price)` — company-favorable
4. **P4** : Timing policy-driven : `upgrade_timing` et `downgrade_timing` depuis PlatformBillingPolicy
5. **P5** : Net > 0 → facture proration ; Net < 0 → wallet credit ; Net = 0 → no-op
6. **P6** : Idempotency via `idempotency_key` unique sur intent
7. **P7** : `end_of_trial` exécution → subscription status `trialing` → `active`

### Audit LOT 1+2 — Corrections & Preuves

#### Bug fix : end_of_trial / end_of_period silently fallback to now()

`PlanChangeExecutor::schedule()` acceptait `end_of_trial` même sans `subscription.trial_ends_at`, et `end_of_period` sans `current_period_end`. Fallback silencieux à `now()` = exécution immédiate non voulue.

**Fix** : throw `RuntimeException` quand les données manquent. 2 tests ajoutés.

#### Pré-LOT3 : Trial par plan (zéro dette)

`PlatformBillingPolicy.free_trial_days` existait comme paramètre global, mais les Plans n'avaient pas de `trial_days`. Résultat : `subscription.trial_ends_at` n'était jamais défini, `end_of_trial` timing incomplet.

Livré en pré-LOT3 (pas de dette, prêt pour LOT3 dunning) :
- `plans.trial_days` ajouté (migration + modèle + registry). Pro/Business = 14 jours.
- `InternalPaymentAdapter` et `NullPaymentGateway` créent un subscription `trialing` avec `trial_ends_at` quand `plan.trial_days > 0`
- 5 tests couvrant le cycle complet (création trialing → end_of_trial → active)

#### Guard dur : idempotency_key obligatoire sur écritures wallet système

`WalletLedger::record()` throw si `actorType === 'system'` et `idempotencyKey === null`. Écritures manuelles (`platform_user`, etc.) autorisées sans clé. 2 tests ajoutés.

#### Preuves idempotency (17 tests)

| Write-path | Mécanisme idempotency | Vérifié par test |
|---|---|---|
| `schedule()` | `idempotency_key` unique sur intent | `test_schedule_idempotency_key_prevents_duplicate` |
| `execute()` | Status guard `scheduled` + lockForUpdate | `test_execute_replay_throws_no_side_effects` |
| `WalletLedger` | `idempotency_key` unique sur transaction | `test_wallet_ledger_idempotency_key_prevents_duplicate` |
| `CreditNoteIssuer::apply()` | `credit-note-{id}` key + status guard | `test_credit_note_apply_is_idempotent_via_wallet_key` |
| `InvoiceIssuer::finalize()` | `isFinalized()` guard + lockForUpdate | `test_invoice_finalize_replay_throws` |
| `WalletLedger` (system) | Hard guard: system + no key → throw | `test_system_wallet_write_without_idempotency_key_throws` |
| `WalletLedger` (manual) | No key required for non-system writes | `test_manual_wallet_write_without_idempotency_key_allowed` |

#### Preuves atomicité (3 tests)

- `execute()` crash (subscription supprimée) → rollback total, intent reste `scheduled`
- Direct `execute()` failure → rollback vérifié
- `finalize()` nested transaction (wallet debit) → savepoints Laravel corrects

### LOT 3 : Dunning Engine — Payment-Failure Policy

#### Service

`DunningEngine::processOverdueInvoices()` — pipeline en 2 phases :

1. **Phase 1** : Scan invoices `open` + `finalized_at IS NOT NULL` + `due_at <= now - grace_period_days` → mark `overdue`, schedule `next_retry_at`, subscription → `past_due`
2. **Phase 2** : Scan invoices `overdue` + `next_retry_at <= now` → attempt wallet payment, reschedule ou exhaust

#### Matrice de policies (PlatformBillingPolicy)

| Paramètre | Default | Effet |
|-----------|---------|-------|
| `grace_period_days` | 3 | Jours après `due_at` avant première action dunning |
| `max_retry_attempts` | 3 | Tentatives max avant `uncollectible` |
| `retry_intervals_days` | [1, 3, 7] | Jours entre chaque retry |
| `failure_action` | suspend | Action quand max retries atteint : `suspend` ou `cancel` |

#### Statuts invoice ajoutés

| Status | Signification |
|--------|---------------|
| `overdue` | Past grace period, en cours de retry |
| `uncollectible` | Max retries exhausted, failure_action appliquée |

#### Transitions subscription.status (dunning)

| Transition | Déclencheur |
|-----------|-------------|
| `active/trialing` → `past_due` | Première invoice passe overdue (Phase 1) |
| `past_due` → `active` | Toutes les invoices overdue payées (réactivation bornée) |
| `past_due` → `suspended` | `failure_action=suspend`, max retries exhausted |
| `past_due` → `cancelled` | `failure_action=cancel`, max retries exhausted |

#### Wallet payment rule (LOT3)

**Full-coverage only.** Si `wallet_balance < amount_due`, le paiement échoue entièrement et l'invoice est reschedulée. Pas de paiement partiel — il n'y a pas de provider pour couvrir le reste en LOT3. Le partial wallet + provider fallback sera implémenté en LOT4 (Stripe).

#### failure_action=cancel — sémantique complète

1. Subscription → `cancelled`
2. Tous les `PlanChangeIntent` scheduled pour la company → `cancelled`
3. `company.plan_key` → `starter` (free tier)
4. `company.status` → `suspended`

#### Réactivation bornée

Quand un retry paie l'invoice :
- Si plus aucune invoice `overdue` pour la subscription → subscription revient `active`
- Si `company.status = suspended` et plus aucune invoice `overdue`/`uncollectible` → company revient `active`
- Les invoices `uncollectible` sont terminales — réactivation après uncollectible requiert une action admin (void + resubscribe)

#### Artisan command

`billing:process-dunning` — idempotent, schedulable quotidiennement. Implémente `Isolatable` (cache lock, pas d'exécution concurrente).

#### Invariants LOT 3

1. **D1** : Seuls les invoices finalized + open + non-voided sont éligibles au dunning
2. **D2** : `retry_count` incrémente exactement 1× par tentative
3. **D3** : Wallet payment utilise `idempotency_key: dunning-retry-{invoice_id}-{retry_count}`
4. **D4** : Suspension/cancellation company est idempotente
5. **D5** : `failure_action=cancel` → subscription cancelled + intents cancelled + plan_key downgraded + company suspended
6. **D6** : Invoice `uncollectible` est un état terminal (plus de retry)
7. **D7** : Subscription `past_due` dès la première invoice overdue
8. **D8** : Réactivation bornée — subscription revient `active` ssi zéro invoice outstanding
9. **D9** : Phases 1 et 2 strictement séparées (pas de leak cross-phase dans un même run)

### Audit LOT 3 — Corrections & Preuves

#### Fix 1 : ProcessDunningCommand → Isolatable

Le command manquait de protection contre l'exécution concurrente. Deux crons parallèles pouvaient sélectionner les mêmes invoices (même si lockForUpdate sérialisait le traitement individuel).

**Fix** : `implements Isolatable` — cache lock, une seule instance à la fois.

#### Fix 2 : Subscription status transitions (past_due)

`subscription.status` restait `active` pendant tout le dunning → incohérent avec `company.status`.

**Fix** : `markOverdue()` met la subscription en `past_due`. `applyFailureAction()` met en `suspended` ou `cancelled`.

#### Fix 3 : Réactivation bornée

Aucun mécanisme de retour à `active` quand un retry payait l'invoice.

**Fix** : `checkReactivation()` après chaque paiement réussi. Vérifie que zéro invoice overdue/uncollectible reste avant de réactiver.

#### Fix 4 : failure_action=cancel sémantique complète

`cancel` ne touchait ni `company.plan_key`, ni les `PlanChangeIntent` scheduled.

**Fix** : Cancel annule tous les intents scheduled + downgrade `company.plan_key → starter`.

#### Fix 5 : Wallet partial payment rule documentée

Design choice explicite : full-coverage only en LOT3. `wallet_balance < amount_due` → payment fails entirely. Pas de partial.

#### Preuves (20 tests, 70 assertions)

| Test | Invariant vérifié |
|------|-------------------|
| `test_subscription_becomes_past_due_on_overdue_invoice` | D7 |
| `test_reactivation_when_all_overdue_invoices_paid` | D8 |
| `test_no_reactivation_when_uncollectible_remains` | D8 (négatif) |
| `test_cancel_action_cancels_scheduled_plan_change_intents` | D5 |
| `test_cancel_action_downgrades_plan_key_to_starter` | D5 |
| `test_command_implements_isolatable` | Isolatable |

### Phase D — Delivery (UI + backend mutations post-LOTs)

Phases de livraison intégrées après les LOTs backend. Chaque phase D est incrémentale, testée, et documentée.

#### Phase D0 : PlatformBillingPolicy Governance (Backend)

`PlatformBillingPolicyController` — singleton GET/PUT sur `platform_billing_policies`.

- Route : `GET|PUT /api/platform/billing/billing-policy`
- Middleware : `module.active:platform.billing` + `platform.permission:manage_billing`
- Validation : enums, bounds, `retry_intervals_days` length/increasing, `next_number` cannot decrease, prefix regex
- Audit : `AuditAction::BILLING_POLICY_UPDATED` avec diff before/after, skip si no-change
- 23 tests E2E

#### Phase D1 : Subscription Behaviour Mutations (Backend)

`ChangePlanService`, `SubscriptionCanceller`, `InvoicePayNowService` — endpoints company-facing.

| Endpoint | Service | Effet |
|----------|---------|-------|
| `PUT /api/billing/plan-change` | `ChangePlanService` | Schedule un `PlanChangeIntent` selon timing policy |
| `PUT /api/billing/cancel` | `SubscriptionCanceller` | `cancel_at_period_end = true` ou immédiat |
| `POST /api/billing/pay-now` | `InvoicePayNowService` | Wallet payment sur invoices open/overdue |

- Tous requièrent `idempotency_key`
- Audit via `AuditLogger::logCompany()` avec actions `PLAN_CHANGE_REQUESTED`, `CANCEL_REQUESTED`, `PAID_NOW`
- 15 tests E2E

#### Phase D1.1 : UI Platform Settings → Billing (Frontend)

Onglet "Billing" ajouté dans Platform Settings (`/platform/settings/billing`).

| Fichier | Action |
|---------|--------|
| `resources/js/modules/platform-admin/billing/billingPolicy.store.js` | CREATE — Pinia store fetch/update |
| `resources/js/pages/platform/settings/_SettingsBilling.vue` | CREATE — 5 sections (Wallet, Plan Changes, Dunning, Invoice, Tax) |
| `resources/js/pages/platform/settings/[tab].vue` | MODIFY — ajout tab + VWindowItem |
| `en.json` + `fr.json` | MODIFY — ~45 clés `platformSettings.billing.*` |

Caractéristiques :
- **17 champs safe** exposés, **3 champs phantom** exclus (`proration_strategy`, `free_trial_days`, `addon_billing_interval`)
- Dirty tracking via `JSON.stringify` snapshot
- Validation client : retry intervals length/increasing, `next_number` cannot decrease below server value
- `retry_intervals_days` auto-resize quand `max_retry_attempts` change
- 403 graceful : si permission manquante, VAlert au lieu du formulaire
- Zéro modification backend

#### Phase D2a : Platform Admin Invoice Mutations (Backend)

`AdminInvoiceMutationService` — 3 mutations admin sur factures finalisées.

| Endpoint | Méthode service | Guards | Effet |
|----------|-----------------|--------|-------|
| `PUT /billing/invoices/{id}/mark-paid-offline` | `markPaidOffline()` | finalized + not voided | status→paid, paid_at→now(), Payment(provider=offline) créé |
| `PUT /billing/invoices/{id}/void` | `void()` | finalized + not paid | status→void, voided_at→now(), CreditNote si wallet_credit_applied > 0 |
| `PUT /billing/invoices/{id}/notes` | `updateNotes()` | finalized + not voided | notes mis à jour |

| Fichier | Action |
|---------|--------|
| `app/Core/Billing/AdminInvoiceMutationService.php` | CREATE — service avec 3 méthodes |
| `app/Modules/Platform/Billing/Http/PlatformInvoiceMutationController.php` | CREATE — controller avec validation |
| `app/Core/Audit/AuditAction.php` | MODIFY — ajout `INVOICE_NOTES_UPDATED` |
| `routes/platform.php` | MODIFY — 3 routes sous `manage_billing` |
| `tests/Feature/AdminInvoiceMutationTest.php` | CREATE — 23 tests E2E (66 assertions) |

Idempotency :
- `markPaidOffline` : si invoice déjà `paid` + Payment avec même `idempotency_key` en metadata → 200 replay
- `void` : si invoice déjà `void` → 200 replay
- `updateNotes` : skip audit si valeur identique

Wallet credit reversal (void) :
- Si `invoice.wallet_credit_applied > 0` → `CreditNoteIssuer::issueAndApply()` reverse le crédit wallet

Audit : `INVOICE_MARKED_PAID` (severity=warning), `INVOICE_VOIDED` (severity=warning), `INVOICE_NOTES_UPDATED` (severity=info)

#### Phase D2b : Platform Billing UI — Invoice Actions (Frontend)

Actions admin sur l'onglet Invoices de la page `/platform/billing`.

| Fichier | Action |
|---------|--------|
| `resources/js/modules/platform-admin/billing/billing.store.js` | MODIFY — 3 actions mutation + `_mutationLoading` lock map |
| `resources/js/pages/platform/billing/_BillingInvoicesTab.vue` | MODIFY — colonne Actions, VMenu, dialogs confirm + notes |
| `en.json` + `fr.json` | MODIFY — 16 clés `platformBilling.action*`, `confirm*`, `notes*`, toasts |

Caractéristiques :
- **Permission gate** : colonne Actions visible seulement si `hasPermission('manage_billing')`
- **Can-act guards** : mark-paid/void seulement sur `open|overdue`, notes seulement sur finalized
- **Idempotency key UI** : format `ui-{action}-{YYYYMMDDHHmmss}-{rand6}`
- **Double-click protection** : `_mutationLoading[invoiceId]` lock par facture
- **Confirm dialog** : VDialog custom pour mark-paid (primary) et void (red/error)
- **Notes dialog** : VDialog avec AppTextarea, max 2000 chars, save/cancel
- **Error handling** : 401/403 → "Not authorized", 409 → conflict message, else → generic
- **Auto-refresh** : après mutation, re-fetch la page courante d'invoices
- Aucune action sur les autres onglets (Dunning, Payments, Credit Notes, Wallets, Subscriptions)

#### Phase D2c : Advanced Admin Mutations (ADR-136)

**Date** : 2026-02-27
**Statut** : Livré

5 mutations admin sensibles sur factures finalisées, toutes protégées par `manage_billing`, idempotency, audit logging, et `DB::transaction`.

**Endpoints** (sous `/api/platform/billing/invoices/{id}/`) :

| Verb | Route | Action | Status requis |
|------|-------|--------|---------------|
| POST | `refund` | Émet un avoir de remboursement (issued, pas applied V1) | `paid` |
| POST | `retry-payment` | Force un retry dunning via wallet | `overdue` |
| PUT  | `dunning-transition` | Force une transition dunning | `open→overdue` ou `overdue→uncollectible` |
| POST | `credit-note` | Émet un avoir manuel (± wallet apply) | finalized, non voided |
| PUT  | `write-off` | Passage comptable en irrécouvrable | `overdue` |

**Machine d'état facture — transitions autorisées** :
- `open → overdue` (dunning-transition) : schedule retry, subscription → past_due
- `overdue → uncollectible` (dunning-transition) : applique failure_action (suspend/cancel)
- `overdue → uncollectible` (write-off) : PAS de failure_action (passage comptable pur)
- `overdue → paid` (retry-payment + wallet suffisant) : bounded reactivation
- `overdue → retried` (retry-payment + wallet insuffisant) : reschedule next_retry_at

**Distinction critique write-off vs dunning-transition(uncollectible)** :
- `forceDunningTransition(overdue→uncollectible)` : applique `DunningEngine::applyFailureAction()` → suspend/cancel company+subscription
- `writeOff()` : NE FAIT PAS de failure_action — pure écriture comptable, company et subscription inchangées

**Refund — plafond cumulatif** :
- `SUM(credit_notes WHERE type=refund AND invoice_id=X) + montant_demandé <= invoice.amount`
- CreditNote créé en statut `issued` (pas `applied`) — V1 sans provider refund

**Idempotency** :
- Refund/Credit Note : `CreditNote.metadata->idempotency_key` par invoice
- Dunning Transition/Write-off : status check (si déjà dans target_status → replay)
- Retry Payment : status check (overdue requis, sinon 409)

**Modifications DunningEngine** :
- `applyFailureAction()` : `private static` → `public static` (réutilisé par forceDunningTransition)
- Ajout `retrySingleInvoice(Invoice)` : wrapper public qui désambiguë le retour `'retried'` en `'paid'|'retried'|'exhausted'|'skipped'`

**AuditAction ajoutés** :
- `INVOICE_DUNNING_FORCED` = `billing.invoice_dunning_forced`
- `CREDIT_NOTE_MANUAL` = `billing.credit_note_manual`
- `INVOICE_WRITTEN_OFF` = `billing.invoice_written_off`
- `BILLING_REFUND` et `DUNNING_FORCE_RETRY` existaient déjà (forward declarations)

**Fichiers** :
- `app/Core/Billing/AdminAdvancedMutationService.php` (créé — 5 méthodes)
- `app/Modules/Platform/Billing/Http/PlatformAdvancedMutationController.php` (créé — 5 endpoints)
- `app/Core/Billing/DunningEngine.php` (modifié — +1 méthode publique, 1 visibilité)
- `app/Core/Audit/AuditAction.php` (modifié — +3 constantes)
- `routes/platform.php` (modifié — +5 routes)
- `tests/Feature/AdminAdvancedMutationTest.php` (créé — 28 tests, 100 assertions)

### Conséquences

- LOT 1 : 6 migrations, 5 modèles, 5 services, 35 tests
- LOT 2 : 2 migrations, 1 modèle, 2 services, 21 tests (11 unit + 10 feature)
- Audit LOT 1+2 : 1 bug fix (timing throw), pré-LOT3 (trial_days), 1 guard (system idempotency), 1 migration, 22 tests ajoutés
- LOT 3 : 1 service (DunningEngine), 1 command (billing:process-dunning, Isolatable), 20 tests E2E
- Phase D0 : 1 controller, 23 tests
- Phase D1 : 3 services, 3 endpoints, 15 tests
- Phase D1.1 : 2 fichiers créés, 3 modifiés, ~45 clés i18n
- Phase D2a : 1 service, 1 controller, 3 routes, 23 tests (66 assertions)
- Phase D2b : 3 fichiers modifiés, 16 clés i18n
- Phase D2c : 2 services (créé + modifié), 1 controller, 5 routes, 28 tests (100 assertions)
- Phase D3a : 2 interfaces étendues, 1 adapter réécrit, 1 controller durci, 1 migration, 7 tests (17 assertions)
- Phase D3b : 1 service créé, 1 adapter modifié, 1 migration, 14 tests (51 assertions)
- `platform_settings.billing` JSON blob conservé temporairement (supprimé en LOT 8)
- `Invoice.amount` conservé comme synonyme de `total` (backward compat)

#### Phase D3a : Stripe SDK Bootstrap & Webhook Security (ADR-137)

**Date** : 2026-02-27
**Statut** : Livré

Stripe SDK installé (`stripe/stripe-php` v19.4), vérification de signature webhook obligatoire (HMAC-SHA256), interface `refund()` ajoutée, webhook controller durci.

**Changements interface** (`PaymentProviderAdapter`) :
- `refund(string $providerPaymentId, int $amount, array $metadata = []): array` — retourne `{provider_refund_id, amount, status, raw_response}`
- `verifyWebhookSignature(string $rawBody, array $headers): void` — throw `RuntimeException` si invalide

**StripePaymentAdapter** — SDK live :
- `verifyWebhookSignature()` : HMAC-SHA256 via `Stripe\Webhook::constructEvent()`, tolérance 300s
- `refund()` : appel `Stripe\Refund::create()` via wrapper testable `callStripeRefund()` (protected)
- `healthCheck()` : `Stripe\Balance::retrieve()` réel
- Checkout/callback/cancel/webhookEvent : stubs (D3b scope)

**InternalPaymentAdapter** :
- `refund()` : throw `RuntimeException` (pas de provider externe)
- `verifyWebhookSignature()` : no-op (pas de webhooks externes)

**PaymentWebhookController — flux durci** :
1. Vérifie provider actif (existant)
2. Résout l'adapter tôt (avant storage)
3. Vérifie la signature : `adapter->verifyWebhookSignature()` → 400 si invalide
4. Exige `event_id` — suppression du fallback `uniqid('evt_')`
5. Exige `event_type` — suppression du fallback `'unknown'`
6. Rejette `event.created` > 5 minutes → 400
7. Insert idempotent (existant)
8. `DB::transaction` autour du handler
9. Status `'ignored'` (au lieu de `'failed'`) pour events non-handled

**Migration** :
- Index unique sur `payments.provider_payment_id` (nullable — MySQL/SQLite OK avec multiples NULL)

**Tests** (`StripeWebhookSecurityTest` — 7 tests, 17 assertions) :
- Signature invalide → 400
- Event trop vieux → 400
- Event ID manquant → 400
- Signature valide → 200 + row webhook_events
- Duplicate event → idempotent (1 row)
- Refund SDK success → normalized array
- Refund SDK exception → RuntimeException

**Fichiers** :
- `app/Core/Billing/Contracts/PaymentProviderAdapter.php` (modifié — +2 méthodes)
- `app/Core/Billing/Adapters/StripePaymentAdapter.php` (réécrit — SDK live)
- `app/Core/Billing/Adapters/InternalPaymentAdapter.php` (modifié — +2 méthodes)
- `app/Modules/Infrastructure/Webhooks/Http/PaymentWebhookController.php` (durci)
- `database/migrations/2026_02_27_300001_add_unique_provider_payment_id_to_payments.php` (créé)
- `tests/Feature/StripeWebhookSecurityTest.php` (créé — 7 tests)
- `tests/Feature/WebhookIdempotencyTest.php` (modifié — status assertion)

#### Phase D3b : Stripe Webhook State Synchronization (ADR-138)

**Date** : 2026-02-27
**Statut** : Livré

Business event handlers pour webhooks Stripe. 3 event types traités : `payment_intent.succeeded`, `payment_intent.payment_failed`, `charge.refunded`.

**StripeEventProcessor** (nouveau service `app/Core/Billing/Stripe/`) :
- `process(array $payload): WebhookHandlingResult` — dispatcher pour 3 types d'events
- `handlePaymentSucceeded()` — upsert Payment, mark Invoice paid (open/overdue → paid)
- `handlePaymentFailed()` — upsert Payment (status=failed), mark Invoice overdue (open → overdue seulement)
- `handleChargeRefunded()` — issue CreditNote par refund (status=issued, PAS de wallet apply)

**Mapping STRICT invoice-first** :
- PaymentIntent `metadata.invoice_id` → lookup direct avec guard company_id
- Fallback : `metadata.invoice_number` → lookup par numéro
- Si aucune facture finalisée résolue → `handled: false`, PAS de Payment créé
- Company : `metadata.company_id` → direct. Fallback : `CompanyPaymentCustomer.provider_customer_id`

**Migration** :
- `payments.invoice_id` nullable FK → invoices (nullOnDelete)
- Index composite `(invoice_id, status)` pour queries billing
- Contrainte `unique(provider_payment_id)` globale conservée (D3a)

**Idempotency** :
- Payment upsert : `Payment::updateOrCreate(['provider_payment_id' => $intentId])` + index unique
- Invoice mark-paid : guard `in_array($status, ['open', 'overdue'])` — jamais re-marquer paid
- Invoice mark-overdue : guard `$status === 'open'` — jamais régresser paid
- Refund CreditNote : `CreditNote.metadata->provider_refund_id` dedup par invoice

**Invariants D3b** :
1. **S1** : Payment upsert idempotent (unique provider_payment_id)
2. **S2** : Invoice paid seulement depuis open/overdue (void/uncollectible ignorés)
3. **S3** : payment_failed → open→overdue seulement (DunningEngine gère la suite)
4. **S4** : Refund CreditNote issued only, pas de wallet apply (conservateur)
5. **S5** : Résolution company/invoice échoue gracieusement (handled=false)

**AuditAction ajoutés** :
- `WEBHOOK_PAYMENT_SYNCED` = `webhook.payment_synced` (severity: info)
- `WEBHOOK_PAYMENT_FAILED` = `webhook.payment_failed` (severity: warning)
- `WEBHOOK_REFUND_SYNCED` = `webhook.refund_synced` (severity: critical)

**Tests** (`StripeWebhookSyncTest` — 14 tests, 51 assertions) :
| Groupe | Tests |
|--------|-------|
| payment_intent.succeeded | creates payment + marks paid, idempotent replay, ignored sans invoice, ignored si void, noop si déjà paid |
| payment_intent.payment_failed | creates failed payment + marks overdue, noop si déjà overdue, noop si paid, ignored sans invoice |
| charge.refunded | creates CN issued, idempotent par refund_id, ignored sans payment matching |
| Edge + Audit | unhandled event → ignored, 3 audit logs créés |

**Fichiers** :
- `app/Core/Billing/Stripe/StripeEventProcessor.php` (créé — 3 handlers + 3 resolvers)
- `app/Core/Billing/Adapters/StripePaymentAdapter.php` (modifié — wire handleWebhookEvent)
- `app/Core/Billing/Payment.php` (modifié — +invoice_id fillable + invoice() relation)
- `app/Core/Audit/AuditAction.php` (modifié — +3 constantes webhook)
- `database/migrations/2026_02_27_400001_add_invoice_id_to_payments_table.php` (créé)
- `tests/Feature/StripeWebhookSyncTest.php` (créé — 14 tests)

---

### ADR-139 — Phase D3c : Provider-First Collection & Refund Chaining

**Date** : 2026-02-27
**Statut** : Implémenté
**Contexte** : D3b a établi le webhook Stripe comme source de vérité. Mais DunningEngine retry uniquement via wallet, et les refunds admin sont locaux uniquement. D3c rend Stripe le moteur de collection actif.

#### Design critique : API calls OUTSIDE DB::transaction

`DunningEngine::attemptRetry()` s'exécute dans `DB::transaction` avec `lockForUpdate()`. Les appels API Stripe ne doivent JAMAIS être dans cette transaction (row lock maintenu pendant I/O réseau).

**Solution** : L'appel provider se fait AVANT la transaction wallet. Si provider réussit → return `'provider_attempted'` (webhook finalise l'état). Si provider échoue → fallback au flow wallet existant.

#### Flux retry DunningEngine (modifié)

```
1. attemptProviderPayment() [HORS transaction]
   → subscription.provider != null && != 'internal'
   → adapter.collectInvoice(invoice, company)
   → status == 'succeeded' → 'provider_attempted' (webhook finalisera)
   → status == 'failed' ou exception → null (fallback wallet)

2. DB::transaction [existant, inchangé]
   → attemptWalletPayment()
   → paid / reschedule / exhaust
```

#### collectInvoice() — Interface + Implémentation

```php
// PaymentProviderAdapter (interface)
public function collectInvoice(Invoice $invoice, Company $company, array $metadata = []): array;
// Retourne: {provider_payment_id, amount, status: 'succeeded'|'failed', raw_response}

// StripePaymentAdapter
// - Rate limit: 50 appels / 60s (RateLimiter)
// - PaymentIntent::create(['confirm' => true, 'off_session' => true])
// - Ne mute PAS la DB locale — webhook = source de vérité

// InternalPaymentAdapter
// - throw RuntimeException (pas de collection externe)
```

#### Refund chaining admin

`AdminAdvancedMutationService::refund()` est provider-aware :

```
1. Trouver Payment provider : Payment.where(invoice_id, status=succeeded, provider!=internal)
2. Si trouvé : adapter.refund(provider_payment_id, amount)
   → succès : stocker provider_refund_id dans CreditNote.metadata
   → échec : RuntimeException → controller renvoie 409
3. Si pas trouvé : CreditNote locale uniquement (wallet-only invoices)
```

CreditNote metadata enrichi :
- `provider_refund_id` — ID Stripe du refund
- `provider_payment_id` — ID du payment original

#### Adapter resolution

`resolveAdapter()` dans DunningEngine et AdminAdvancedMutationService utilise le container Laravel (`app(StripePaymentAdapter::class)`) pour permettre le test override via `$this->app->bind()`.

#### Rate limiting

```php
private const RATE_LIMIT_KEY = 'stripe-api';
private const RATE_LIMIT_MAX = 50;
private const RATE_LIMIT_DECAY = 60;
// RateLimiter::tooManyAttempts() → RuntimeException
// RateLimiter::hit() avant chaque appel
// Couvre : collectInvoice() + refund()
```

#### Valeurs retour DunningEngine

- `retrySingleInvoice()` : `'paid' | 'retried' | 'exhausted' | 'skipped' | 'provider_attempted'`
- `'provider_attempted'` = provider a accepté la charge, webhook finalisera l'état local

#### Invariants

1. **I1** : collectInvoice() ne mute JAMAIS la DB locale
2. **I2** : API call toujours HORS DB::transaction
3. **I3** : Provider fail → wallet fallback transparent
4. **I4** : Rate limit Stripe → RuntimeException (catchée par DunningEngine, propagée par admin refund)
5. **I5** : Idempotency refund admin préservée (CreditNote.metadata.idempotency_key)
6. **I6** : Invoice sans Payment provider → refund local-only

**AuditAction ajouté** :
- `PROVIDER_COLLECTION_ATTEMPTED` = `billing.provider_collection_attempted`

**Tests** (`StripeProviderCollectionTest` — 18 tests, 48 assertions) :
| Groupe | Tests |
|--------|-------|
| Provider collection (7) | retry Stripe first, provider ne marque pas paid, fallback wallet, exhaust sans wallet, amount_due vérifié, metadata invoice_id+company_id, internal skippé |
| Admin refund chaining (6) | Stripe refund appelé, CN avec provider_refund_id, abort on provider failure, idempotent replay, partial refund, wallet-only no Stripe |
| Rate limit (3) | blocks collection, wallet fallback preserved, refund bloqué |
| Audit (2) | refund audit, dunning retry audit |

**Fichiers** :
- `app/Core/Billing/Contracts/PaymentProviderAdapter.php` (modifié — +collectInvoice)
- `app/Core/Billing/Adapters/StripePaymentAdapter.php` (modifié — +collectInvoice, +rate limit refund)
- `app/Core/Billing/Adapters/InternalPaymentAdapter.php` (modifié — +collectInvoice stub)
- `app/Core/Billing/DunningEngine.php` (modifié — provider-first retry, resolveAdapter)
- `app/Core/Billing/AdminAdvancedMutationService.php` (modifié — refund chaining, resolveAdapter)
- `app/Core/Audit/AuditAction.php` (modifié — +1 constante)
- `tests/Feature/StripeProviderCollectionTest.php` (créé — 18 tests)

**Zéro frontend. Zéro nouvel endpoint. Zéro migration.**

---

### ADR-140 — Phase D3d : Reconciliation, Drift Detection & Alerting (Finance Hardening)

**Date** : 2026-02-27
**Statut** : Implémenté
**Contexte** : D3c a établi provider-first collection, webhook source-of-truth, refund chaining, et rate limiting global. D3d complète la couche finance production-grade avec : détection de drift, réconciliation planifiée, alerting automatique, et isolation du rate limiting par company.

#### Stratégie : Detection-only (pas d'auto-repair)

Le ReconciliationEngine détecte les écarts mais ne corrige **rien** automatiquement. Toute correction passe par intervention admin. Auto-repair prévu en D3e.

#### Taxonomie des drifts (5 types)

| Type | Condition |
|------|-----------|
| `missing_local_payment` | Stripe PI succeeded, aucun Payment local avec ce provider_payment_id |
| `missing_stripe_payment` | Payment local succeeded (provider=stripe), absent de la liste Stripe |
| `status_mismatch` | Stripe PI succeeded mais Payment local status ≠ succeeded |
| `refund_mismatch` | Charge Stripe refunded mais aucun CreditNote correspondant |
| `invoice_not_paid` | Stripe PI succeeded + metadata.invoice_id, mais Invoice status ≠ paid |

#### Rate limit isolation (Critical Fix)

```php
// AVANT (D3c) — global
private const RATE_LIMIT_KEY = 'stripe-api';

// APRÈS (D3d) — par company
private static function rateLimitKey(?int $companyId = null): string
{
    return $companyId ? "stripe-api:{$companyId}" : 'stripe-api:global';
}
```

- `collectInvoice()` → `enforceRateLimit($company->id)`
- `refund()` → `enforceRateLimit($metadata['company_id'])`
- `listPaymentIntents()` → `enforceRateLimit($companyId)`
- Seuil inchangé : 50 appels / 60s par company

#### Alerting

Policy : `severity >= critical` + `config('billing.alerting.enabled')` → `BillingCriticalAlert` via `Notification::route('mail', $email)`.

```php
// config/billing.php
'alerting' => [
    'enabled' => env('BILLING_ALERT_ENABLED', false),
    'email'   => env('BILLING_ALERT_EMAIL'),
    'webhook_url' => env('BILLING_ALERT_WEBHOOK'),  // future
],
```

Hook dans `AuditLogger::logPlatform()` — après DB write + realtime publish. Graceful : exception → `Log::warning()`, ne bloque jamais l'audit.

#### Scheduler

```php
// routes/console.php
Schedule::command('billing:process-dunning')->daily()->withoutOverlapping();
Schedule::command('billing:reconcile')->weekly()->withoutOverlapping();
```

Les deux commandes implémentent `Isolatable` (cache lock, une instance).

#### Commande billing:reconcile

```
billing:reconcile {--company=} {--dry-run}
```

- `--company=ID` : limiter à une company
- `--dry-run` : pas d'audit log
- Délègue à `ReconciliationEngine::reconcile()`
- Output : summary des drifts par type

#### Invariants

1. **I1** : Detection-only — aucune mutation automatique
2. **I2** : Rate limit isolé par company — `stripe-api:{companyId}`
3. **I3** : Alerting graceful — dispatch fail → warning log, audit intact
4. **I4** : Dry-run = aucun side-effect (pas d'audit, pas d'alert)
5. **I5** : Stripe API errors dans reconcile → skip company, continue les autres
6. **I6** : Scheduler withoutOverlapping + Isolatable = zéro concurrence

**AuditAction ajouté** :
- `BILLING_DRIFT_DETECTED` = `billing.drift_detected` (severity: critical)

**Tests** (`BillingReconciliationTest` — 20 tests, 30 assertions) :
| Groupe | Tests |
|--------|-------|
| Detection (8) | missing local, missing stripe, status mismatch, refund mismatch, invoice not paid, clean, dry-run no log, non-dry-run logs |
| Alerting (6) | critical dispatches, disabled no dispatch, email channel, non-critical no dispatch, alert content, dispatch failure graceful |
| Rate limit isolation (3) | company isolation, A ne bloque pas B, global fallback |
| Scheduler (3) | dunning daily, reconcile weekly, withoutOverlapping |

**Fichiers** :
- `app/Core/Billing/Adapters/StripePaymentAdapter.php` (modifié — rate limit isolation + listPaymentIntents)
- `app/Core/Billing/ReconciliationEngine.php` (créé — drift detection)
- `app/Console/Commands/BillingReconcileCommand.php` (créé — Isolatable)
- `app/Core/Audit/AuditLogger.php` (modifié — alert hook)
- `app/Core/Audit/AuditAction.php` (modifié — +1 constante)
- `app/Notifications/BillingCriticalAlert.php` (créé — mail notification)
- `config/billing.php` (modifié — +alerting config)
- `routes/console.php` (modifié — +2 scheduler entries)
- `tests/Feature/StripeProviderCollectionTest.php` (modifié — rate limit keys)
- `tests/Feature/BillingReconciliationTest.php` (créé — 20 tests)

**Zéro frontend. Zéro migration. Zéro nouvel endpoint.**

**Future D3e** : Auto-repair strategy (réconciliation corrective, forensics).

---

### ADR-141 — Phase D3e : Auto-Repair & Financial Forensics (Controlled, Auditable, Reversible)

**Date** : 2026-02-27
**Statut** : Implémenté
**Contexte** : D3d a établi la détection de drift (5 types) et l'alerting. D3e ajoute l'auto-repair contrôlé pour les drifts safe, avec snapshot avant mutation, et un service de forensics pour timeline financière.

#### Stratégie : Opt-in only, 3 safe types

L'auto-repair est **désactivé par défaut** (`billing.auto_repair.enabled = false`). Dry-run par défaut (`billing.auto_repair.dry_run_default = true`). Seuls 3 types de drift sont réparables automatiquement :

| Type safe | Réparation |
|-----------|------------|
| `missing_local_payment` | Crée un Payment local depuis les données Stripe |
| `status_mismatch` | Met à jour le status du Payment local → succeeded |
| `invoice_not_paid` | Marque l'Invoice comme paid + paid_at |

Les 2 types unsafe (`missing_stripe_payment`, `refund_mismatch`) sont **toujours ignorés** — intervention admin obligatoire.

#### Snapshot avant mutation (FinancialSnapshot)

Chaque auto-repair prend un snapshot **avant** toute modification :

```
financial_snapshots
├── company_id (FK)
├── trigger (auto_repair | forensics)
├── drift_type (missing_local_payment | status_mismatch | invoice_not_paid)
├── entity_type (payment | invoice)
├── entity_id
├── snapshot_data (JSON — état complet avant mutation)
├── correlation_id (UUID partagé par toutes les réparations d'un run)
└── created_at
```

- Snapshots immutables — jamais modifiés après création
- `correlation_id` lie toutes les réparations d'un même run
- Indexé sur `[company_id, entity_type, entity_id]`

#### Idempotence

Chaque stratégie vérifie l'état actuel **avant** de muter :
- `missing_local_payment` : skip si Payment avec ce provider_payment_id existe déjà
- `status_mismatch` : skip si Payment.status === 'succeeded'
- `invoice_not_paid` : skip si Invoice.status === 'paid'

Un double-run ne produit aucune mutation supplémentaire.

#### Configuration

```php
// config/billing.php
'auto_repair' => [
    'enabled'          => env('BILLING_AUTO_REPAIR_ENABLED', false),
    'dry_run_default'  => env('BILLING_AUTO_REPAIR_DRY_RUN', true),
    'safe_types'       => [
        'missing_local_payment',
        'status_mismatch',
        'invoice_not_paid',
    ],
],
```

#### Commande billing:reconcile (étendue)

```
billing:reconcile {--company=} {--dry-run} {--repair}
```

- `--repair` : active l'auto-repair des drifts safe
- Requiert `billing.auto_repair.enabled = true` en config
- `--dry-run` + `--repair` : calcule les réparations sans muter

#### Financial Forensics

`FinancialForensicsService::timeline(companyId, days, ?entityType)` — vue chronologique de tous les événements financiers :
- Invoices, Payments, CreditNotes, WalletTransactions, FinancialSnapshots
- Filtrable par type d'entité
- Trié par timestamp

#### Invariants

1. **I1** : Auto-repair opt-in uniquement — config enabled = false par défaut
2. **I2** : Snapshot avant toute mutation — pas de mutation sans preuve
3. **I3** : Idempotent — double-run = zéro mutation supplémentaire
4. **I4** : Types unsafe jamais réparés automatiquement (missing_stripe_payment, refund_mismatch)
5. **I5** : Dry-run = aucun side-effect (pas de snapshot, pas de mutation, pas d'audit)
6. **I6** : Correlation ID lie toutes les réparations d'un run
7. **I7** : Erreur sur une réparation → log warning, continue les autres

**AuditAction ajouté** :
- `BILLING_AUTO_REPAIR_APPLIED` = `billing.auto_repair_applied` (severity: warning)

**Tests** (`BillingAutoRepairTest` — 25 tests, 65 assertions) :
| Groupe | Tests |
|--------|-------|
| missing_local_payment (3) | create payment, correct amount, links to subscription |
| status_mismatch (3) | update status, stores previous in metadata, skips missing payment |
| invoice_not_paid (4) | mark paid, sets paid_at, skips missing invoice, skips no invoice_id |
| Snapshots (5) | missing creates snapshot, status creates snapshot before mutation, invoice creates snapshot, correlation_id, dry-run no snapshots |
| Idempotency (4) | missing idempotent, status idempotent, invoice idempotent, double repair safe |
| Config/dry-run (3) | disabled skips repairs, unsafe types skipped, dry-run no mutations |
| Forensics (3) | timeline includes all types, sorted chronologically, filters by type |

**Fichiers** :
- `config/billing.php` (modifié — +auto_repair config)
- `app/Core/Audit/AuditAction.php` (modifié — +1 constante)
- `app/Core/Billing/FinancialSnapshot.php` (créé — modèle Eloquent)
- `database/migrations/2026_02_27_500001_create_financial_snapshots_table.php` (créé)
- `app/Core/Billing/AutoRepairEngine.php` (créé — 3 stratégies de réparation)
- `app/Core/Billing/ReconciliationEngine.php` (modifié — +paramètre autoRepair)
- `app/Console/Commands/BillingReconcileCommand.php` (modifié — +--repair flag)
- `app/Core/Billing/FinancialForensicsService.php` (créé — timeline forensics)
- `tests/Feature/BillingAutoRepairTest.php` (créé — 25 tests)

**1 migration. Zéro frontend. Zéro nouvel endpoint.**

---

### ADR-142 — Phase D3f : Immutable Financial Ledger (Enterprise-Grade Accounting Layer)

**Date** : 2026-02-27
**Statut** : Implémenté
**Contexte** : D3e a établi detect → alert → snapshot → repair → audit → forensics. D3f ajoute une couche comptable append-only double-entry, garantissant : aucune modification destructive, traçabilité irréfutable, reconstruction de solde à tout instant, conformité audit.

#### Principes non négociables

1. **Append-only strict** — jamais UPDATE, jamais DELETE (enforced par boot())
2. **Double-entry obligatoire** — SUM(debit) = SUM(credit) par correlation_id
3. **Aucune logique métier dans le ledger** — le ledger enregistre, ne décide pas
4. **Le ledger ne remplace pas les tables métier** — les Payment/Invoice/CreditNote restent source de vérité opérationnelle
5. **Reconstruction possible** — trialBalance(companyId) recompute le solde en temps réel

#### Mapping comptable

| Événement | Entry Type | Débit | Crédit |
|-----------|-----------|-------|--------|
| Invoice finalisée (100€) | `invoice_issued` | AR: 100 | REVENUE: 100 |
| Paiement reçu (100€) | `payment_received` | CASH: 100 | AR: 100 |
| Refund émis (30€) | `refund_issued` | REFUND: 30 | CASH: 30 |
| Write-off (bad debt 100€) | `writeoff` | BAD_DEBT: 100 | AR: 100 |

Comptes : `AR` (Accounts Receivable), `CASH`, `REVENUE`, `REFUND`, `BAD_DEBT`

#### Schema

```sql
financial_ledger_entries
├── id
├── company_id (FK, index)
├── entry_type (invoice_issued | payment_received | refund_issued | writeoff | adjustment)
├── account_code (AR | CASH | REVENUE | REFUND | BAD_DEBT)
├── debit DECIMAL(15,2)
├── credit DECIMAL(15,2)
├── currency CHAR(3)
├── reference_type (invoice | payment | credit_note)
├── reference_id
├── correlation_id UUID (index)
├── metadata JSON
├── recorded_at (index)
└── timestamps
```

NO soft deletes. NO cascade delete. NO update possible.

#### LedgerService API

```php
LedgerService::recordInvoiceIssued(Invoice $invoice);    // Debit AR, Credit REVENUE
LedgerService::recordPaymentReceived(Payment $payment);   // Debit CASH, Credit AR
LedgerService::recordRefundIssued(CreditNote $cn);        // Debit REFUND, Credit CASH
LedgerService::recordWriteOff(Invoice $invoice);           // Debit BAD_DEBT, Credit AR
LedgerService::trialBalance(int $companyId): array;        // Balance par compte
```

#### Hooks dans le domaine

| Point d'intégration | Moment |
|---------------------|--------|
| `InvoiceIssuer::finalize()` | Après update → `recordInvoiceIssued()` |
| `StripeEventProcessor::handlePaymentSucceeded()` | Après Payment créé + Invoice payée → `recordPaymentReceived()` |
| `AdminAdvancedMutationService::refund()` | Après CreditNote émis → `recordRefundIssued()` |
| `AdminAdvancedMutationService::writeOff()` | Après status uncollectible → `recordWriteOff()` |
| `DunningEngine::attemptRetry()` (exhausted) | Après status uncollectible → `recordWriteOff()` |

Chaque hook est wrappé en try/catch — le ledger ne bloque **jamais** le flux métier.

#### Commande billing:ledger-check

```
billing:ledger-check {--company=}
```

Validations :
1. Double-entry : `SUM(debit) == SUM(credit)` par correlation_id
2. Orphelins : reference_type/id pointe vers un enregistrement existant
3. Cohérence devise : pas de mix EUR/USD dans un même correlation group

#### Invariants

1. **I1** : Append-only — update() et delete() lèvent RuntimeException
2. **I2** : Double-entry — debit == credit par correlation_id (vérifié par ledger-check)
3. **I3** : Ledger indépendant du repair engine — n'auto-répare jamais
4. **I4** : Trial balance reproductible — SUM(debit - credit) par account_code
5. **I5** : Hooks gracieux — erreur ledger → warning log, flux métier intact
6. **I6** : Ledger isolé — pas de cascade delete depuis les tables métier

**Tests** (`BillingLedgerTest` — 30 tests, 62 assertions) :
| Groupe | Tests |
|--------|-------|
| Double-entry (10) | invoice 2 entries, debit=credit, AR debit, REVENUE credit, payment 2 entries, CASH debit + AR credit, refund 2 entries, REFUND debit + CASH credit, writeoff 2 entries, BAD_DEBT debit + AR credit |
| Immutability (5) | update throws, delete throws, save throws, create works, has timestamps |
| Hook integration (5) | finalize hook, webhook hook, admin refund hook, admin writeoff hook, dunning exhaustion hook |
| Trial balance (5) | all accounts present, after invoice, after payment clears AR, after refund, after writeoff |
| Integrity command (5) | clean passes, detects imbalance, detects orphan, detects currency mismatch, company filter |

**Fichiers** :
- `database/migrations/2026_02_27_600001_create_financial_ledger_entries_table.php` (créé)
- `app/Core/Billing/LedgerEntry.php` (créé — modèle immutable)
- `app/Core/Billing/LedgerService.php` (créé — 4 recording methods + trialBalance)
- `app/Console/Commands/BillingLedgerCheckCommand.php` (créé — Isolatable)
- `app/Core/Billing/InvoiceIssuer.php` (modifié — hook ledger)
- `app/Core/Billing/Stripe/StripeEventProcessor.php` (modifié — hook ledger)
- `app/Core/Billing/AdminAdvancedMutationService.php` (modifié — hook ledger refund + writeoff)
- `app/Core/Billing/DunningEngine.php` (modifié — hook ledger writeoff)
- `tests/Feature/BillingLedgerTest.php` (créé — 30 tests)

**1 migration. 1 commande. Zéro frontend. Zéro nouvel endpoint.**

---

## ADR-143 : Period Closing, Ledger Locking & Financial Controls (D3g)

- **Date** : 2026-02-27
- **Contexte** : ADR-142 fournit un ledger immutable append-only, mais ne gère pas la clôture comptable, le gel financier, ni les seuils de sécurité sur les opérations critiques.
- **Décision** : Ajouter 5 mécanismes de contrôle financier :
  1. **Financial Period Closing** — période (start_date, end_date) fermable par commande CLI. Une fois fermée, aucune écriture normale dans le ledger pour les dates couvertes.
  2. **Ledger Period Guard** — `assertPeriodOpen()` vérifié avant chaque recording method. Rejet avec RuntimeException si la date tombe dans une période fermée.
  3. **Adjustment Entries** — seul moyen de modifier le ledger après clôture. `entry_type='adjustment'`, reason obligatoire, double-entry respectée.
  4. **Financial Freeze** — boolean `financial_freeze` sur Company. Quand activé : aucune écriture ledger (y compris adjustments), refunds et writeoffs bloqués.
  5. **Writeoff Threshold** — seuil configurable (`billing.writeoff_threshold` en cents). Si > 0, les write-offs dépassant le seuil sont rejetés.
- **Conséquences** :
  - Clôture = opération irréversible (par design, pas de réouverture)
  - Freeze = opération réversible (toggle admin)
  - Guards LedgerService propagés via try/catch dans les hooks métier → freeze/period bloquent le ledger mais le flux métier continue (warning log)
  - Threshold = 0 signifie illimité (défaut)

**Invariants** :
1. **I1** : Période fermée → écriture normale rejetée, adjustment permis (sauf freeze)
2. **I2** : Company frozen → toute écriture ledger rejetée, y compris adjustments
3. **I3** : Freeze bloque aussi refund() et writeOff() dans AdminAdvancedMutationService
4. **I4** : Writeoff threshold vérifié avant l'écriture DB — pas d'incohérence partielle
5. **I5** : BillingPeriodCloseCommand est Isolatable — une seule instance à la fois
6. **I6** : Overlap interdit — deux périodes fermées ne peuvent pas chevaucher pour la même company
7. **I7** : Audit trail — clôture de période → AuditAction::BILLING_PERIOD_CLOSED (severity critical)

**LedgerService — guards ajoutés** :
```php
// Chaque recording method commence par :
self::assertPeriodOpen($companyId);
self::assertNotFrozen($companyId);
```

**Config** (`config/billing.php`) :
```php
'writeoff_threshold' => (int) env('BILLING_WRITEOFF_THRESHOLD', 0),
```

**Tests** (`BillingPeriodGovernanceTest` — 35 tests, 70 assertions) :
| Groupe | Tests |
|--------|-------|
| Period Closing (8) | create, close, casts, unique constraint, different companies, audit constants, freeze attribute, freeze default |
| Ledger Guard (7) | no period passes, open period passes, closed period throws, outside period passes, invoice rejected, payment rejected, writeoff rejected |
| Adjustment (5) | balanced entries, entry_type=adjustment, reason in metadata, returns correlation_id, rejects zero |
| Financial Freeze (5) | blocks ledger, blocks adjustment, blocks admin writeoff, blocks admin refund, unfrozen allows |
| Writeoff Threshold (5) | below succeeds, above blocked, equal succeeds, zero=unlimited, config reads correctly |
| Command (5) | dry-run, creates period, logs audit, rejects overlap, rejects invalid dates |

**Fichiers** :
- `database/migrations/2026_02_27_700001_create_financial_periods_table.php` (créé)
- `database/migrations/2026_02_27_700002_add_financial_freeze_to_companies.php` (créé)
- `app/Core/Billing/FinancialPeriod.php` (créé — modèle)
- `app/Core/Models/Company.php` (modifié — +financial_freeze fillable + cast)
- `app/Core/Audit/AuditAction.php` (modifié — +3 constantes)
- `app/Core/Billing/LedgerService.php` (modifié — guards + recordAdjustment)
- `app/Core/Billing/AdminAdvancedMutationService.php` (modifié — freeze + threshold guards)
- `config/billing.php` (modifié — +writeoff_threshold)
- `app/Console/Commands/BillingPeriodCloseCommand.php` (créé — Isolatable)
- `tests/Feature/BillingPeriodGovernanceTest.php` (créé — 35 tests)

**2 migrations. 1 commande. Zéro frontend. Zéro nouvel endpoint.**

---

## ADR-144 : HTTP Exposure of D3 Financial Services (D4b)

- **Date** : 2026-02-27
- **Contexte** : D3 services (Ledger, Forensics, Reconciliation, Period governance) exist only as internal PHP classes + CLI commands. The platform billing UI (D4c) needs HTTP endpoints to consume them.
- **Décision** : Create a thin HTTP layer (`PlatformFinancialController`) that delegates to Core services via `PlatformFinancialReadService`. No business logic in the controller.
- **Endpoints** :
  - **Read** (view_billing) : `GET /billing/ledger/trial-balance`, `GET /billing/ledger/entries`, `GET /billing/financial-periods`, `GET /billing/forensics/timeline`, `GET /billing/forensics/snapshots`, `GET /billing/drift-history`
  - **Write** (manage_billing) : `POST /billing/financial-periods/close`, `PUT /billing/companies/{id}/financial-freeze`, `POST /billing/reconcile`
- **Contraintes** :
  - Reconcile defaults to `dry_run=true` — auto-repair remains CLI-only
  - Financial freeze toggle is per-company, not global
  - All endpoints use existing `module.active:platform.billing` + `platform.permission:*` middleware
  - 4 FormRequests for strict validation (TrialBalanceRequest, PeriodCloseRequest, FinancialFreezeRequest, ReconcileRequest)
- **Fichiers** :
  - `app/Core/Billing/ReadModels/PlatformFinancialReadService.php` (créé)
  - `app/Modules/Platform/Billing/Http/PlatformFinancialController.php` (créé — 9 méthodes)
  - `app/Modules/Platform/Billing/Http/Requests/` (créé — 4 FormRequests)
  - `routes/platform.php` (modifié — +9 routes)

**Zéro migration. Zéro modification Core. Repair = CLI-only.**

---

## ADR-145 : Invoice Detail Pages + PDF Fix (D4d)

- **Date** : 2026-02-27
- **Contexte** : D4c delivered tab-based billing views (invoices, payments, governance, ledger, forensics) but lacked drill-down invoice detail pages. The company-scope PDF download used `window.open()` which bypasses `$api` headers — the `X-Company-Id` header required by `SetCompanyContext` middleware was never sent, causing auth failures.
- **Décision** :
  - **Invoice detail pages** — Platform (`/platform/billing/invoices/[id]`) and Company (`/company/billing/invoices/[id]`) using Vuexy 9+3 col invoice preview preset layout
  - **Backend enrichment** — `invoiceDetail()` in both `PlatformBillingReadService` and `CompanyBillingReadService` now includes `payments` and `ledger_entries` arrays (queried by `reference_type=invoice` + `reference_id`)
  - **PDF fix** — Replace `window.open()` with `$api` blob download (ofetch `responseType: 'blob'`), which auto-injects `X-Company-Id` from cookie
  - **Click-through** — Invoice number in list views becomes a `RouterLink` to the detail page
  - **Scope separation** — Platform detail shows ledger entries (financial forensics), company detail does not
- **Contraintes** :
  - Zero new routes — existing `GET /billing/invoices/{id}` (both scopes) reused
  - Zero new controllers — enriched existing read services only
  - Platform page requires `layout: 'platform', platform: true` meta (BMAD-UI-001)
  - Company page has NO layout meta (default = company layout)
  - All amounts displayed via `formatMoney()` (cents → display)
  - All text i18n'd (EN + FR)
- **Fichiers** :
  - `app/Core/Billing/ReadModels/PlatformBillingReadService.php` (modifié — +payments, +ledger_entries)
  - `app/Core/Billing/ReadModels/CompanyBillingReadService.php` (modifié — +payments, +ledger_entries)
  - `resources/js/pages/platform/billing/invoices/[id].vue` (créé)
  - `resources/js/pages/company/billing/invoices/[id].vue` (créé)
  - `resources/js/pages/company/billing/_BillingInvoices.vue` (modifié — PDF blob + RouterLink)
  - `resources/js/pages/platform/billing/_BillingInvoicesTab.vue` (modifié — RouterLink)
  - `resources/js/modules/platform-admin/billing/billing.store.js` (modifié — +fetchInvoiceDetail)
  - `tests/Feature/InvoiceDetailEndpointTest.php` (créé — 10 tests)

**Zéro route. Zéro migration. Zéro controller.**

---

## ADR-146 : D4d Hardening — i18n Nav, Ledger Crash, CSRF, Runtime Safety

- **Date** : 2026-02-27
- **Contexte** : Post-D4d, cinq problèmes identifiés :
  1. Les titres de navigation (sidebar) étaient des chaînes anglaises brutes passées depuis les manifests modules — aucune traduction FR
  2. `e.debit.toFixed(2)` crash sur la page détail facture : Laravel `decimal:2` cast sérialise en **string** dans le JSON, pas en number
  3. Le retry 419 CSRF dans `$api` réutilisait les headers stale contenant l'ancien `X-XSRF-TOKEN`
  4. Les `v-for` sur `invoice.payments`, `invoice.credit_notes`, `invoice.ledger_entries` crashent si le backend retourne `null`/`undefined`
  5. Chaîne "Notes:" hardcodée en anglais dans la page détail company
- **Décision** :
  - **i18n navigation** — Les composables `useCompanyNav` et `usePlatformNav` traduisent via `t('nav.company.{key}')` / `t('nav.platform.{key}')` au lieu de passer `item.title` brut. Ajout de `nav.platform.*` (15 clés), `nav.company.*` (11 clés), `nav.groups.account` dans EN + FR. Les items statiques (Dashboard, Account Settings, heading Account) aussi i18n'és.
  - **Ledger numeric** — `Number(e.debit).toFixed(2)` : coerce string→number avant `.toFixed()`. Fonctionne avec string ET number.
  - **CSRF retry** — Avant le retry, suppression de `X-XSRF-TOKEN` de `options.headers` pour que `onRequest` injecte le token frais.
  - **Runtime safety** — `v-for="p in (invoice.payments || [])"` sur toutes les boucles des deux pages détail.
  - **Hardcoded string** — `"Notes:"` → `t('companyBilling.invoiceDetail.notes')`
- **Tests** : +2 tests dans `InvoiceDetailEndpointTest` (total 12) :
  - `test_ledger_entries_return_numeric_debit_credit` — vérifie que debit/credit survivent au cast `decimal:2`
  - `test_invoice_detail_without_ledger_entries` — vérifie le retour d'un tableau vide
- **Fichiers** :
  - `resources/js/composables/useCompanyNav.js` (modifié — i18n titles)
  - `resources/js/composables/usePlatformNav.js` (modifié — i18n titles)
  - `resources/js/pages/platform/billing/invoices/[id].vue` (modifié — Number() coerce + v-for safety)
  - `resources/js/pages/company/billing/invoices/[id].vue` (modifié — v-for safety + i18n "Notes:")
  - `resources/js/utils/api.js` (modifié — delete stale XSRF before 419 retry)
  - `resources/js/plugins/i18n/locales/en.json` (modifié — +nav.platform.*, +nav.company.*, +nav.groups.account)
  - `resources/js/plugins/i18n/locales/fr.json` (modifié — idem FR)
  - `tests/Feature/InvoiceDetailEndpointTest.php` (modifié — +2 tests)

---

## ADR-147 : D4e Billing Widgets Dashboard

- **Date** : 2026-02-27
- **Contexte** : Le tableau de bord plateforme et la page billing n'affichaient aucun indicateur financier visuel. Les données existent dans le ledger (ADR-142) mais aucun widget ne les exploite. L'objectif est de fournir une vue d'ensemble financière rapide pour les admins plateforme.
- **Décision** :
  - **Architecture widgets** — Interface `BillingDashboardWidget` (key, labelKey, defaultPeriod, resolve) + `BillingWidgetRegistry` (static catalog). Chaque widget est une classe autonome qui interroge le `PlatformBillingWidgetsReadService`.
  - **ReadModel** — `PlatformBillingWidgetsReadService` : 5 méthodes statiques (currencyForCompany, revenueTrend, refundTotals, revenueTotals, arOutstanding). Requêtes sur `LedgerEntry` uniquement (immutable, append-only).
  - **3 widgets** — `RevenueTrendWidget` (graphique area daily), `RefundRatioWidget` (ratio % avec seuils couleur), `ArOutstandingWidget` (solde net créances).
  - **Controller passif** — `PlatformBillingWidgetsController` : index (liste widgets) + show (résout un widget). Aucun SQL dans le controller. 409 Conflict si devises mixtes.
  - **Routes** — 2 GET dans le groupe `view_billing` : `/billing/widgets` et `/billing/widgets/{key}`.
  - **Frontend** — Store Pinia enrichi (widgetData, widgetLoading, fetchAllWidgets). 3 sous-composants Vue (`_RevenueTrendWidget.vue`, `_RefundRatioWidget.vue`, `_ArOutstandingWidget.vue`). VueApexCharts pour le graphique area.
  - **Dashboard** — Section « Financial Overview (Billing) » sur `/platform` avec toggle localStorage (`lzr:dashboard-billing-widgets`), gatée par permission `view_billing`.
  - **Billing page** — Section « Financial Overview » en haut de `/platform/billing` avec sélecteur company + période.
  - **i18n** — Clés `platformBilling.widgets.*` (13) + `platformDashboard.billingWidgets.*` (2) en EN + FR.
- **Tests** : `PlatformBillingWidgetsTest` (10 tests) :
  1. index requires auth (401)
  2. index requires view_billing (403)
  3. index returns 3 widgets
  4. show unknown widget → 404
  5. revenue_trend returns labels + series
  6. refund_ratio returns revenue/refunds/ratio
  7. ar_outstanding returns outstanding balance
  8. validate company_id → 422
  9. validate period enum → 422
  10. mixed currencies → 409
- **Fichiers** :
  - `app/Modules/Billing/Dashboard/BillingDashboardWidget.php` (créé — interface)
  - `app/Modules/Billing/Dashboard/BillingWidgetRegistry.php` (créé — registre statique)
  - `app/Core/Billing/ReadModels/PlatformBillingWidgetsReadService.php` (créé — read model)
  - `app/Modules/Billing/Dashboard/Widgets/RevenueTrendWidget.php` (créé)
  - `app/Modules/Billing/Dashboard/Widgets/RefundRatioWidget.php` (créé)
  - `app/Modules/Billing/Dashboard/Widgets/ArOutstandingWidget.php` (créé)
  - `app/Modules/Platform/Billing/Http/PlatformBillingWidgetsController.php` (créé)
  - `app/Modules/Platform/Billing/Http/Requests/WidgetResolveRequest.php` (créé)
  - `routes/platform.php` (modifié — +2 routes widget)
  - `resources/js/modules/platform-admin/billing/billing.store.js` (modifié — widget state/actions)
  - `resources/js/pages/platform/billing/_RevenueTrendWidget.vue` (créé — ApexCharts area)
  - `resources/js/pages/platform/billing/_RefundRatioWidget.vue` (créé — ratio + progress)
  - `resources/js/pages/platform/billing/_ArOutstandingWidget.vue` (créé — montant outstanding)
  - `resources/js/pages/platform/billing/index.vue` (modifié — Financial Overview section)
  - `resources/js/pages/platform/index.vue` (modifié — dashboard billing widgets toggle)
  - `resources/js/plugins/i18n/locales/en.json` (modifié — +widget keys)
  - `resources/js/plugins/i18n/locales/fr.json` (modifié — +widget keys FR)
  - `tests/Feature/PlatformBillingWidgetsTest.php` (créé — 10 tests)

---

## ADR-148 : D4e.2 Dashboard Engine — Catalog + Scope + Persistent Layout

- **Date** : 2026-02-27
- **Contexte** : ADR-147 fournissait 3 widgets billing company-scoped uniquement. Le dashboard exigeait un `company_id` pour chaque appel widget — aucune vue globale (cross-company) n'existait. Aucun catalogue, aucun batch resolver, aucune persistance de disposition, et aucun mécanisme pour d'autres modules d'enregistrer des widgets.
- **Décision** :
  - **WidgetManifest** — Nouvelle interface contrat (`app/Modules/Dashboard/Contracts/WidgetManifest.php`) : key, module, labelKey, descriptionKey, scope (`global`|`company`|`both`), permissions, capabilities, defaultConfig, resolve(array $context). Remplace `BillingDashboardWidget` pour le nouveau moteur (l'ancien reste intact).
  - **DashboardWidgetRegistry** — Registre statique pattern `PaymentRegistry` : register, all, find, catalogForUser (filtrage par permissions + capabilities), boot, clearCache.
  - **PeriodParser** — Extraction du parseur de période dupliqué dans les 3 widgets (`app/Modules/Dashboard/PeriodParser.php`).
  - **Scope global** — 5 nouvelles méthodes dans `PlatformBillingWidgetsReadService` : `currencyGlobal()` (retourne devise unique ou `'MULTI'`), `revenueTrendGlobal()`, `refundTotalsGlobal()`, `revenueTotalsGlobal()`, `arOutstandingGlobal()`. Agrégation cross-company sans filtre company_id.
  - **Widget adapters** — 3 nouvelles classes dans `app/Modules/Dashboard/Widgets/` implémentant `WidgetManifest` et déléguant au ReadModel. Préfixées `billing.` (billing.revenue_trend, billing.refund_ratio, billing.ar_outstanding). Les anciennes classes dans `Billing/Dashboard/Widgets/` restent intactes (backward compat).
  - **Layout persistent** — Table `platform_user_dashboard_layouts` (user_id unique, layout_json). Modèle `PlatformUserDashboardLayout`. Layout par défaut en dur dans le controller si aucun enregistré.
  - **Batch resolver** — `POST /dashboard/widgets/data` accepte un tableau de widgets à résoudre en une requête. Gestion d'erreur par widget (not_found, forbidden, company_id_required, RuntimeException).
  - **Catalog endpoint** — `GET /dashboard/widgets/catalog` retourne les widgets visibles pour l'utilisateur courant.
  - **4 routes** — catalog, batchResolve, layout GET, layout PUT. Middleware `module.active:platform.dashboard` uniquement (pas de permission spécifique — filtrage par widget).
  - **hasCapability stub** — Ajouté à `PlatformUser`, retourne toujours `true` (capabilities non implémentées).
  - **Frontend** — Nouveau store Pinia `dashboard.store.js` (catalog, layout, widgetData, resolveWidgets, saveLayout). Dashboard page transformée avec grille dynamique, drag-and-drop HTML5 natif, drawer catalogue, scope toggle par widget, bouton Save Layout.
  - **Multi-currency** — Frontend affiche un badge "Multi-currency" si `currency === 'MULTI'`. Les widgets formatent les montants sans symbole devise dans ce cas.
  - **i18n** — 15 clés `platformDashboard.engine.*` EN + FR. 2 clés description widget ajoutées.
- **Tests** : `DashboardEngineTest` (10 tests, 46 assertions) :
  1. catalog requires auth (401)
  2. catalog filters by permission (admin=3, viewer=0)
  3. catalog returns widget structure
  4. batch resolve global scope (3 widgets sans company_id)
  5. batch resolve company scope
  6. batch resolve unknown widget → not_found
  7. company scope requires company_id
  8. layout get returns default
  9. layout put saves and get returns
  10. layout is per-user (indépendance entre users)
- **Non-régressions** : `PlatformBillingWidgetsTest` (10 tests) reste vert. Endpoints `/billing/widgets` intacts.
- **Fichiers** :
  - `app/Modules/Dashboard/Contracts/WidgetManifest.php` (créé — interface)
  - `app/Modules/Dashboard/DashboardWidgetRegistry.php` (créé — registre statique)
  - `app/Modules/Dashboard/PeriodParser.php` (créé — helper)
  - `app/Modules/Dashboard/Widgets/BillingRevenueTrendWidget.php` (créé — adapter)
  - `app/Modules/Dashboard/Widgets/BillingRefundRatioWidget.php` (créé — adapter)
  - `app/Modules/Dashboard/Widgets/BillingArOutstandingWidget.php` (créé — adapter)
  - `app/Modules/Dashboard/PlatformUserDashboardLayout.php` (créé — modèle)
  - `app/Modules/Platform/Dashboard/Http/DashboardWidgetController.php` (créé — catalog + batch)
  - `app/Modules/Platform/Dashboard/Http/DashboardLayoutController.php` (créé — layout CRUD)
  - `database/migrations/2026_02_27_800001_create_platform_user_dashboard_layouts_table.php` (créé)
  - `resources/js/modules/platform-admin/dashboard/dashboard.store.js` (créé — Pinia store)
  - `tests/Feature/DashboardEngineTest.php` (créé — 10 tests)
  - `app/Platform/Models/PlatformUser.php` (modifié — +hasCapability stub)
  - `app/Core/Billing/ReadModels/PlatformBillingWidgetsReadService.php` (modifié — +5 méthodes global)
  - `app/Providers/AppServiceProvider.php` (modifié — +DashboardWidgetRegistry::boot())
  - `routes/platform.php` (modifié — +4 routes dashboard)
  - `resources/js/pages/platform/index.vue` (modifié — grille dynamique)
  - `resources/js/pages/platform/billing/_RevenueTrendWidget.vue` (modifié — +scope prop, MULTI)
  - `resources/js/pages/platform/billing/_RefundRatioWidget.vue` (modifié — +scope prop, MULTI)
  - `resources/js/pages/platform/billing/_ArOutstandingWidget.vue` (modifié — +scope prop, MULTI)
  - `resources/js/plugins/i18n/locales/en.json` (modifié — +engine keys, +desc keys)
  - `resources/js/plugins/i18n/locales/fr.json` (modifié — +engine keys, +desc keys)

## ADR-149 : D4e.3 Dashboard Grid Engine V2 — Grid x/y/w/h, Module Hooks, Company Surface

- **Date** : 2026-02-27
- **Contexte** : ADR-148 livrait un moteur de widgets plat avec `col_span`, drag-and-drop HTML5, côté plateforme uniquement. Manquant : grille 2D réelle (x/y/w/h), CSS Grid responsive, drag+resize, dashboard entreprise, hooks de widgets par module, défauts par jobdomain, auto-inject addon, validation layout, conformité thème.
- **Décision** :
  - **WidgetManifest V2** — 4 nouvelles méthodes : `layout()` (default_w/h, min_w/max_w, min_h/max_h), `category()` (groupement UI), `tags()` (recherche), `component()` (clé composant frontend). Trait `WidgetLayoutDefaults` fournit les implémentations par défaut.
  - **Module key système** — `module()` passe de `'billing'` à `'core.billing'` (cohérence ModuleGate). `category()` remplace le rôle de groupement.
  - **Convention-based discovery** — `app/Modules/*/Dashboard/widgets.php` et `app/Modules/*/*/Dashboard/widgets.php` retournent des tableaux de FQCN. `DashboardWidgetRegistry::boot()` scanne ces fichiers au lieu d'enregistrer en dur.
  - **Dual catalog** — `catalogForUser(PlatformUser)` (existant, filtrage permissions) + `catalogForCompany(Company)` (nouveau, filtrage par activation module via `ModuleGate::isActiveForScope()`).
  - **LayoutValidator** — Validation : max 30 tuiles, bornes grille (x≥0, y≥0, x+w≤12), contraintes min/max du manifest, détection overlap rectangulaire O(n²).
  - **LayoutPacker** — Algorithme first-fit row-by-row en grille 12 colonnes. Carte d'occupation sparse. Borne de sécurité y<200. Retourne `{packed[], pending[]}`.
  - **4 tables** — `company_dashboard_layouts` (company_id unique, layout_json), `jobdomain_dashboard_defaults` (jobdomain_id unique, layout_json, version), migration données `col_span→x/y/w/h`, `company_dashboard_widget_suggestions` (company_id, widget_key, status enum pending/accepted/dismissed).
  - **Company dashboard endpoints** — 5 routes core sans module gate : catalog GET, batchResolve POST (scope forcé company), layout GET, layout PUT (middleware `company.access:manage-structure`), suggestions GET.
  - **Platform presets** — Route `GET /dashboard/layout/presets` retournant les défauts par jobdomain.
  - **ModuleEnabled listener** — `InjectModuleWidgets` : à l'activation d'un module, injecte les widgets company-scoped dans le layout via LayoutPacker, crée des suggestions pour les widgets non placés.
  - **JobDomain dashboard clone** — Dans `JobdomainGate::assignToCompany()`, si un défaut existe et l'entreprise n'a pas de layout → clone automatique.
  - **DashboardGrid.vue** — Composant partagé CSS Grid : `grid-template-columns: repeat(var(--dashboard-cols), 1fr)`, `grid-auto-rows: 80px`. Drag + resize par mousedown/mousemove/mouseup avec snap grille. Ghost preview. Responsive via `useDisplay()` (12/8/4 colonnes).
  - **Widget shell** — VCard `variant="flat"`, flex column, dernier enfant flex-grow. Widgets ne contiennent plus de `<VCard>` propre.
  - **widgetComponentMap.js** — Registre de composants async (`defineAsyncComponent`) pour code splitting. Partagé entre les deux surfaces.
  - **Company dashboard page** — `pages/company/index.vue` sans meta layout (default = company). Suggestions banner VAlert, catalogue drawer, grille éditable si `manage-structure`.
  - **Thème** — Zéro couleur codée en dur. Resize handle + ghost utilisent `rgba(var(--v-theme-primary), ...)`. Tous les chips `variant="tonal"`.
  - **i18n** — 3 clés `platformDashboard.engine.*` (presets, applyPreset, resizeHint) + 9 clés `companyDashboard.*` EN/FR.
- **Tests** :
  - `LayoutValidatorTest` (7 tests) : valid, x<0, y<0, overflow, overlap, min/max, max tiles
  - `LayoutPackerTest` (3 tests) : empty grid, existing respected, max tiles → pending
  - `WidgetRegistryScanTest` (4 tests) : boot discovery, system key, V2 fields, catalogForCompany filtering
  - `DashboardGridV2Test` (9 tests) : platform grid format, overlap rejection, presets, company catalog, company batch resolve, company layout CRUD, manage-structure guard, suggestions, company overlap rejection
  - `DashboardEngineTest` (10 tests) : mis à jour pour format x/y/w/h et module `core.billing`
- **Fichiers** :
  - Créés : WidgetLayoutDefaults.php (trait), widgets.php (convention), LayoutValidator.php, LayoutPacker.php, CompanyDashboardLayout.php, JobdomainDashboardDefault.php, CompanyDashboardWidgetSuggestion.php, InjectModuleWidgets.php, CompanyDashboardWidgetController.php, CompanyDashboardLayoutController.php, 4 migrations, useDashboardGrid.js, DashboardGrid.vue, widgetComponentMap.js, company/dashboard.store.js, company/index.vue, 4 fichiers test
  - Modifiés : WidgetManifest.php, DashboardWidgetRegistry.php, 3 billing widgets, DashboardWidgetController.php, DashboardLayoutController.php, JobdomainGate.php, AppServiceProvider.php, routes/company.php, routes/platform.php, platform/dashboard.store.js, platform/index.vue, 3 billing widget Vue, DashboardEngineTest.php, en.json, fr.json

## ADR-150 : Widget Responsive Density Engine

- **Date** : 2026-02-27
- **Contexte** : ADR-149 livrait une grille CSS Grid fonctionnelle avec drag + resize, mais le contenu des widgets ne s'adaptait pas à la taille de leur tuile. Un widget redimensionné en petit croppe son contenu (chart tronqué, texte coupé).
- **Décision** :
  - **Density S/M/L** — Algorithme basé sur w/h de la tuile : `L` (w≥8 && h≥4), `M` (w≥4 && h≥3), `S` (sinon). Calculé dans `DashboardGrid.vue`, passé aux widgets via prop `viewport: { w, h, pxWidth, pxHeight, density }`.
  - **ResizeObserver par tuile** — Chaque tuile observe son `contentRect` via `ResizeObserver`. Les dimensions pixel (`pxWidth`, `pxHeight`) sont disponibles en temps réel.
  - **Widget shell** — `VCard variant="flat"` avec `border-radius: 12px`, `background-color: rgb(var(--v-theme-surface))`. Shell interne en flex column, dernier enfant `flex: 1 1 auto; min-height: 0; overflow: hidden`.
  - **BillingRevenueTrend** — L: chart complet avec axes + tooltips + grid. M: chart simplifié (hauteur réduite, padding compacté). S: sparkline mode (`chart.sparkline.enabled: true`, pas d'axes/grid) + KPI valeur courante.
  - **BillingRefundRatio** — L: KPI + breakdown revenue/refunds + progress bar. M: KPI + progress bar. S: ratio seul avec icône colorée.
  - **BillingArOutstanding** — L/M: montant + description. S: montant seul avec icône.
  - **Compatibilité** — Prop `viewport` optionnel, fallback density `'L'` si absent. Widgets fonctionnent identiquement sans la prop.
  - **Zéro style inline** — Toutes les couleurs via Vuetify tokens (`text-${color}`, `:color` props). Aucun hex codé en dur.
- **Tests** : `WidgetDensityTest` (4 tests) — L/M/S computation, boundary values.
- **Fichiers** :
  - Modifiés : `DashboardGrid.vue` (ResizeObserver, viewport prop, widget-shell CSS), `_RevenueTrendWidget.vue` (density S/M/L avec sparkline), `_RefundRatioWidget.vue` (density S/M/L), `_ArOutstandingWidget.vue` (density S/M/L)
  - Créé : `tests/Unit/WidgetDensityTest.php`

## ADR-151 : Company Dashboard Wiring Fix

- **Date** : 2026-02-27
- **Contexte** : ADR-149 créait `pages/company/index.vue` comme page dashboard entreprise, mais le routing effectif du dashboard company est `pages/dashboard.vue` (route `'dashboard'` à `/dashboard`). La navigation pointait déjà vers `{ name: 'dashboard' }`. Résultat : le dashboard widget engine n'était pas visible côté company.
- **Décision** :
  - **Intégration dans `dashboard.vue`** — Le widget engine (DashboardGrid, catalog drawer, suggestions, save layout) est intégré dans la page existante `pages/dashboard.vue` sous la section welcome + plan badge. Pas de page séparée.
  - **Suppression `company/index.vue`** — Le fichier orphelin à `/company` est supprimé (route inaccessible depuis la navigation).
  - **Empty state propre** — Si aucun widget configuré : VAvatar tonal + icône `tabler-layout-dashboard` + titre + description + bouton "Add Widget" (si `canEdit`). Pattern cohérent avec Vuexy.
  - **Navigation inchangée** — La nav company pointe déjà vers `{ name: 'dashboard' }` avec i18n `nav.company.dashboard`. Pas de modification nécessaire.
  - **Audit hook** — `JobdomainGate::assignToCompany()` log via `\Log::info()` le clone de dashboard default (ou le skip si layout existant).
  - **i18n** — 2 nouvelles clés : `companyDashboard.widgetsTitle`, `companyDashboard.emptyStateHint` (EN + FR).
- **Tests** : 2 tests ajoutés à `DashboardGridV2Test` — roundtrip complet API (catalog → save → get → resolve), empty state (layout vide par défaut).
- **Fichiers** :
  - Modifiés : `pages/dashboard.vue` (intégration widget engine), `JobdomainGate.php` (audit log), `en.json` + `fr.json` (+2 clés), `DashboardGridV2Test.php` (+2 tests)
  - Supprimé : `pages/company/index.vue`

---

## ADR-152 : D4e.3 Fix Pack — Scope Widgets + Collision Drag + Smart Chart Density

- **Date** : 2026-02-28
- **Contexte** : Le dashboard grid V2 (ADR-149/150/151) présentait 3 problèmes : (1) les widgets billing platform (Revenue Trend, Refund Ratio, AR Outstanding) apparaissaient dans le catalog company, (2) le drag-drop autorisait l'overlap entre widgets, (3) en densité M les labels de dates se chevauchaient et en S les données étaient cachées sans contexte min/max.
- **Décision** :
  - **A) Audience séparation** — Nouveau champ `audience(): string` ('platform'|'company'|'both') sur `WidgetManifest`. Default trait = 'platform' (safe). Les 3 widgets billing sont `audience: 'platform'`. `catalogForCompany()` filtre par `audience in ['company','both']` + scope + module activation via `ModuleGate::isActive()`. Résultat : catalog company = [] sans widgets company activés.
  - **B) Collision resolution** — `useDashboardGrid.js` expose `resolveCollisions(layout, movedKey)` : push-down algorithm qui déplace les tiles impactées vers `y = movedTile.y + movedTile.h`, résout les cascades, safety bound y<200. Si impossible : revert layout + VSnackbar erreur i18n `dashboardGrid.noSpaceToPlaceWidget`.
  - **C) Smart chart density** — `maxTicks = max(2, floor(pxWidth / 80))` basé sur le `viewport.pxWidth` du ResizeObserver. Mode M : `xaxis.tickAmount = maxTicks`, `hideOverlappingLabels: true`, format court (`12 Feb`), font réduite. Mode S : sparkline + KPI + min/max context, `padding.bottom: 12` pour respiration.
- **Tests** :
  - `WidgetDensityTest` +1 test `test_max_ticks_computation` (9 assertions)
  - `WidgetRegistryScanTest` +1 test `test_widgets_have_audience_field`, renommé `test_catalog_for_company_excludes_platform_audience_widgets`
  - `DashboardGridV2Test` renommé `test_company_catalog_excludes_platform_audience_widgets`, mis à jour roundtrip
- **Fichiers** :
  - Backend : `WidgetManifest.php` (+audience), `WidgetLayoutDefaults.php` (+audience default), 3 billing widgets (+audience override), `DashboardWidgetRegistry.php` (catalogForCompany audience filter), controllers (+audience field)
  - Frontend : `useDashboardGrid.js` (collision resolution), `DashboardGrid.vue` (+resolveCollisions +VSnackbar), `_RevenueTrendWidget.vue` (maxTicks + min/max S), `_RefundRatioWidget.vue` (S padding fix)
  - i18n : `en.json` + `fr.json` (+dashboardGrid.noSpaceToPlaceWidget)

### Addendum — UX Hardening (2026-02-28)

- **Contexte** : Le drag était trop agressif (tout clic déclenchait un drag), les collisions laissaient des trous, le mode S affichait des axes et des dates cropées.
- **Décisions** :
  - **Drag handle only** — Le drag ne se déclenche qu'au clic sur l'icône `.drag-handle` (tabler-grip-vertical). Le contenu du widget (texte, KPI) est sélectionnable/scrollable normalement.
  - **Drag threshold 6px** — Le drag ne s'active qu'après un déplacement de souris ≥ 6px. En dessous, c'est un clic normal.
  - **compactLayout()** — Après chaque mutation (drop, resize), tous les tiles sont remontés au maximum possible (y--) tant qu'il n'y a pas d'overlap. Zéro trou garanti.
  - **Smart collision** — Le tile déplacé a la priorité, les autres sont poussés vers le bas puis compactés. Résultat : réorganisation propre sans sauts ni espaces vides.
  - **Height override h<=2 → S** — Si `tile.h <= 2`, la densité est forcée à S (sparkline only, no axes). Évite les axes et labels dans un espace insuffisant.
  - **Axes strictement désactivés en S** — `xaxis.labels.show: false`, `yaxis.show: false`, `grid.show: false`, `legend.show: false`. Aucune ligne H/V.
  - **Date format intelligent** — L/M : `"12 Feb"` (mois court). M : `tickAmount = maxTicks`, `hideOverlappingLabels: true`. S : aucun label, tooltip seul.
  - **Header toujours visible** — Structure `widget-content > widget-header (flex: 0 0 auto) + widget-body/widget-chart-area (flex: 1 1 auto)`. En S, header réduit (`text-caption`) mais jamais supprimé.
  - **KPI jamais cropé** — Padding stable, overflow contrôlé par `widget-body { min-height: 0; overflow: hidden }`. KPI dans zone `flex: 0 0 auto`.
  - **Invalid placement** — Si le drop est hors grille ou impossible : revert + snackbar i18n `dashboardGrid.invalidPlacement`.
- **Tests** : `WidgetDensityTest` +1 test `test_height_override_forces_s` (6 assertions). Total : 995 passed, 0 failures.
- **Fichiers** : `useDashboardGrid.js` (rewrite: handle-only, threshold, compactLayout, collision), `DashboardGrid.vue` (rewrite: handle-only template, height override, validation), 3 billing widgets (rewrite: strict S/M/L, header always visible, structured layout), `en.json` + `fr.json` (+invalidPlacement)

### Addendum V2 — UX Correction (2026-02-28)

- **Contexte** : Le hardening précédent ne corrigeait que le drag sur handle. Les axes restaient visibles en mode S, la formule de densité basée sur grid (w,h) ne reflétait pas la taille réelle des pixels, et les collisions ne tentaient que le push-down (création de trous).
- **Décisions** :
  - **Formule densité h + pxWidth** — Nouvelle `computeDensity(h, pxWidth)` : h≤2→S, h≤3→M, pxWidth<400→S, pxWidth<700→M, else→L. La hauteur de grille force S/M dans les cas extrêmes, le reste est piloté par la largeur pixel réelle (via ResizeObserver). Suppression de l'ancienne formule basée sur grid (w,h).
  - **Collision intelligente** — `resolveCollisions(layout, movedKey, origPos)` tente dans l'ordre : (1) **Swap** — si exactement 1 tile chevauche et ses dimensions permettent de le placer à l'ancienne position du tile déplacé. (2) **Push latéral** — essai droite puis gauche du tile déplacé. (3) **Push-down** — descente sous le tile déplacé + résolution cascade. (4) **Compact** — remontée globale.
  - **S mode bulletproof** — Ajout explicite `xaxis.crosshairs: { show: false }`, `yaxis.axisBorder: { show: false }`, `yaxis.axisTicks: { show: false }`, `grid.padding.bottom: 12` dans la config ApexCharts S. Aucune ligne d'axe possible.
- **Tests** : `WidgetDensityTest` entièrement réécrit pour la nouvelle formule (7 tests, 39 assertions). Total suite : 996 passed, 0 failures.
- **Fichiers** : `DashboardGrid.vue` (computeDensity h+pxWidth, origPos passé), `useDashboardGrid.js` (resolveCollisions swap+lateral+push-down+compact), `_RevenueTrendWidget.vue` (S config crosshairs/axisBorder/axisTicks/padding), `WidgetDensityTest.php` (rewrite)

### Addendum V3 — Grid Engine V3 (2026-02-28)

- **Contexte** : V2 laissait des collisions (tiles superposées) et des trous persistants. L'algorithme de collision ad-hoc (swap/lateral/push-down) n'était pas déterministe. Besoin d'un VRAI layout engine avec invariants prouvés.
- **Décisions** :
  - **Pipeline unique** — Chaque mutation (drag, resize, add, remove, breakpoint) passe par exactement le même pipeline : `clampToBounds → resolveOverlaps → compactLayout → assertNoOverlap`. Si l'assertion finale échoue : revert + snackbar.
  - **resolveOverlaps déterministe** — Boucle jusqu'à stabilité (max 200 itérations). Pour chaque paire de tiles en collision : le tile déplacé (movedKey) a priorité, sinon upper-left reste fixe. Le tile mobile est résolu dans l'ordre : (1) shift right, (2) shift left, (3) push down, (4) fallback next row (x=0, y=maxBottom). Chaque option n'est appliquée que si elle ne crée aucun nouveau chevauchement. Zéro collision garantie.
  - **compactLayout gravity** — Tri y asc, x asc. Chaque tile descend y-- tant que pas d'overlap. Zéro trou garanti.
  - **Breakpoint remap** — Quand cols change (12/8/4) : remap proportionnel `newW = round(w * newCols / oldCols)`, `newX = floor(x * newCols / oldCols)`, puis pipeline complet. Ajouté via `watch(cols)` dans DashboardGrid.vue.
  - **Resize reflow** — Si le ghost dépasse cols au x courant : ghostX=0 (wrap). Le pipeline résout le reste au drop.
  - **Chart crop fix** — `_RevenueTrendWidget.vue` : chart `height="100%"` (plus de hauteurs fixes 140/220), `:key="density"` pour remount propre, `chart.redrawOnParentResize: true`, suppression `overflow: hidden` sur widget-content et widget-chart-area (le parent card clip), M mode `grid.padding.bottom: 0` (plus de -5).
  - **KPI widget-kpi** — Nouveau bloc `flex: 0 0 auto` pour le KPI en mode S : jamais cropé.
- **Tests** : 4 nouveaux fichiers unit (29 tests, 118 assertions) :
  - `LayoutEngineNoOverlapTest` (7 tests) — prouve que drag/resize/cascade ne produit jamais d'overlap
  - `LayoutEngineCompactionTest` (6 tests) — prouve que compactLayout élimine tous les trous
  - `LayoutEngineReflowTest` (6 tests) — prouve shift right > shift left > push down, breakpoint remap, full row → next row
  - `DensityRenderRulesTest` (10 tests) — prouve la formule + documente les règles de rendu par densité
- **Total suite** : 1025 passed, 0 failures.
- **Fichiers** :
  - `useDashboardGrid.js` — rewrite complet : pipeline, resolveOverlaps déterministe, remapBreakpoint
  - `DashboardGrid.vue` — applyPipeline au drop, watch(cols) breakpoint remap
  - `_RevenueTrendWidget.vue` — height="100%", :key=density, widget-kpi flex, pas d'overflow hidden
  - 4 nouveaux tests unit PHP

### Addendum V5 — Responsive Grid + Persistent Header (2026-02-28)

- **Contexte** : V3 résolvait les collisions mais le grid n'était pas véritablement responsive. En réduisant la hauteur d'un widget, le header/title/snackbar disparaissait (masqué par `overflow: hidden` ou supprimé par `v-if`). Les breakpoints tablet/mobile ne réorganisaient pas les widgets correctement. Le resize sur mobile ne restreignait pas au vertical.
- **Décisions** :
  - **A) Header JAMAIS supprimé** — Chaque widget utilise un header unifié inline flex : `header-left` (icône + titre tronquable) + `header-right` (KPI + chip, jamais masqués). Le header est un unique `<div class="widget-header">` — PAS de `<template v-if="density === 'S'">` qui supprime le header. En S : typographie compacte (`text-caption` titre, `text-body-2` KPI). En M/L : typographie standard (`text-body-1` titre, `text-h6` KPI). Le KPI est toujours dans `header-right` (pas dans le body). Le body contient uniquement le chart/contenu spécifique à la densité. INTERDIT : `v-if` supprimant le header, `display:none` sur snackbar/chip, `overflow:hidden` clippant le KPI.
  - **B) Breakpoint contract responsive** — Desktop ≥1280px → 12 cols. Tablet ≥960px <1280px → 8 cols. Mobile <960px → 4 cols. Via Vuetify `useDisplay()` : `smAndDown` → 4 cols, `mdAndDown` → 8 cols. Mobile (4 cols) : `w` max = 2 (clamp dans `clampToBounds`), max 2 widgets par row, packing vertical. Gap responsive : 8px mobile / 16px desktop (via CSS custom property `--dashboard-gap`).
  - **C) Mobile resize vertical only** — Quand `isMobile`, le resize freeze la largeur (`newW = resizing.origW`). Curseur `s-resize` au lieu de `se-resize`.
  - **D) Row height unification** — `normalizeRowHeights(tiles)` : pour chaque row (tiles partageant le même `y`), `rowH = max(h des widgets de la row)`, clampé au plus proche supérieur dans `{2, 3, 4, 6}`. Tous les tiles de la row reçoivent `rowH`. Élimine les incohérences visuelles intra-row.
  - **E) Pipeline V5** — `clampToBounds → resolveOverlaps → compactLayout → normalizeRowHeights → re-resolveOverlaps → re-compactLayout → assertNoOverlap`. Le re-resolve + re-compact après normalisation corrige les overlaps potentiels causés par le changement de hauteur.
- **Tests** : Mis à jour 2 fichiers + 6 nouveaux tests :
  - `LayoutEngineNoOverlapTest` — +3 tests : `mobile_4col_clamps_width_to_2`, `mobile_two_tiles_per_row`, `row_heights_normalized_to_valid_set`, `normalize_row_heights_clamps_to_ceiling`. Pipeline V5 avec `normalizeRowHeights`.
  - `LayoutEngineReflowTest` — +2 tests : `breakpoint_8_to_4_mobile_clamp`, `row_height_normalization_after_reflow`. Pipeline V5 + mobile clamp dans `remapBreakpoint`.
- **Total suite** : 1031 passed, 0 failures (9 skipped).
- **Fichiers** :
  - `useDashboardGrid.js` — breakpoints smAndDown/mdAndDown, `isMobile`, mobile w clamp dans clampToBounds, `normalizeRowHeights`, pipeline V5, mobile vertical-only resize
  - `DashboardGrid.vue` — import `isMobile`, gap responsive `--dashboard-gap`, resize handle cursor `s-resize` mobile
  - `_RevenueTrendWidget.vue` — header unifié inline flex (header-left + header-right), KPI dans header-right, zéro `v-if` sur header
  - `_RefundRatioWidget.vue` — header unifié inline flex, ratio% dans header-right, zéro `v-if` sur header
  - `_ArOutstandingWidget.vue` — header unifié inline flex, montant dans header-right, zéro `v-if` sur header
  - 2 tests unit PHP mis à jour (V5 pipeline + mobile + row height)

### Addendum V5-hotfix — Row Height Unification Reverted (2026-02-28)

- **Contexte** : La normalisation de hauteur de row (V5) empêchait les layouts "1 grand à gauche + 2 petits stackés à droite" — tous les widgets d'une même ligne recevaient la même hauteur, écrasant les différences voulues.
- **Décision** : Suppression complète de `normalizeRowHeights`. Chaque widget garde son `h` indépendamment. Le pipeline redevient : `clampToBounds → resolveOverlaps → compactLayout → assertNoOverlap`. Le resize vertical ne modifie que le tile ciblé.
- **Tests** : Les 2 tests `row_heights_normalized_to_valid_set` et `normalize_row_heights_clamps_to_ceiling` sont remplacés par `resize_height_does_not_affect_neighbors` et `two_small_beside_one_large` qui prouvent le free-height. Le test `row_height_normalization_after_reflow` est remplacé par `free_height_preserved_after_reflow`. Total suite : 1031 passed, 0 failures.

### Addendum V5-hotfix-2 — Decouple Vertical Height From Horizontal Packing (2026-02-28)

- **Contexte** : Le `resolveOverlaps` utilisait `overlaps(candidate, t)` avec le `h` réel du tile candidat pour les checks SHIFT RIGHT / SHIFT LEFT. Un tile avec `h=4` pouvait échouer le shift right (overlap vertical avec un tile en dessous) alors que `h=2` passait — causant un packing horizontal différent basé uniquement sur la hauteur.
- **Décision** : Les checks SHIFT RIGHT et SHIFT LEFT utilisent un **probe `h=1`** au lieu du `h` réel. La décision horizontale dépend uniquement de `x + w + cols`, jamais de `h`. Le tile placé conserve son `h` réel. Les overlaps verticaux résultants sont résolus aux itérations suivantes du loop `resolveOverlaps`. Règle : `H4V2` et `H4V4` produisent exactement les mêmes positions horizontales.
- **Tests** : Nouveau test `height_change_does_not_alter_horizontal_packing` qui compare les positions x entre un layout avec `h=2` et `h=4` — identiques.

### Addendum V5-hotfix-3 — Vertical Resize Limit (2026-02-28)

- **Contexte** : Le resize vertical était illimité — un widget pouvait atteindre `h=8` ou plus, dépassant les contraintes visuelles raisonnables.
- **Décisions** :
  - **Limites par widget** : `WIDGET_MIN_H = 2`, `WIDGET_MAX_H = 6`. Appliquées dans `clampToBounds` et `getConstraints` (cap global, peu importe le manifest du widget).
  - **Limite dashboard** : `DASHBOARD_MAX_H = 24` (en unités de grille). Le pipeline rejette tout layout où un tile a `y + h > 24` (remplace l'ancien guard `y >= 200`).
  - **Snackbar** : Quand le resize brut dépasse `WIDGET_MAX_H`, affiche `dashboardGrid.maxHeightReached` via le mécanisme `placementError` existant.
- **Tests** : 4 nouveaux tests : `widget_height_clamped_to_max_6`, `widget_height_clamped_to_min_2`, `dashboard_max_height_24_enforced`, `resize_height_no_horizontal_effect`.

### Addendum V5-hotfix-4 — Allow W=3 + Strict Left Row Packing (2026-02-28)

- **Contexte** : `BillingRevenueTrendWidget` avait `min_w=4` empêchant les widgets de faire 3 colonnes. De plus, après suppression d'un widget, les widgets restants ne comblaient pas le trou horizontal (gap entre widgets sur la même ligne).
- **Décisions** :
  - **WIDGET_MIN_W = 3** : Constante globale. Appliquée dans `clampToBounds` (desktop/tablet uniquement, mobile garde max w=2). `getConstraints` enforce `min_w >= WIDGET_MIN_W`. `BillingRevenueTrendWidget.min_w` corrigé de 4 à 3. `max_h` corrigé de 8 à 6 (cohérent avec WIDGET_MAX_H).
  - **packRowsLeft** : Nouvelle étape pipeline entre compact et assert. Groupe les tiles par `y`, trie par `x` asc, réassigne `x` séquentiellement (`cursor = 0, tile.x = cursor, cursor += tile.w`). Si un tile crée un cross-row overlap (tile haute au-dessus), cherche le premier `x` libre. Si overflow (`x + w > cols`), le tile descend en bas à `x=0`. Pipeline final : `clamp → resolve → compact → packLeft → compact → assert`. Le double compact gère les trous verticaux créés par le déplacement des tiles overflow.
- **Tests** : 5 nouveaux tests : `widget_min_width_3_on_desktop`, `four_w3_widgets_fit_12_cols`, `no_horizontal_gap_after_removal`, `overflow_goes_to_bottom_left`, `resize_wider_packs_neighbors_and_overflows`. Total suite : 1041 passed, 0 failures.

### Addendum V5-hotfix-A — Auto-Reflow Upward (2026-02-28)

- **Contexte** : Quand widget1 grandit et pousse widget3 vers le bas, puis widget1 rétrécit, widget3 restait en bas car `compactLayout` ne change que `y` (pas `x`). Si la place libre est à un `x` différent sur une ligne supérieure, compact ne la trouve pas.
- **Décision** : Nouvelle étape `reflowUpward` dans le pipeline. Traite les tiles du bas vers le haut. Pour chaque tile, cherche la première position `(y, x)` libre en partant de `y=0`. Pipeline final : `clamp → resolve → compact → packLeft → compact → reflowUpward → packLeft → compact → assert`.
- **Tests** : 2 nouveaux tests : `tile_reflows_up_when_space_frees`, `reflow_respects_overlap_constraints`. Total : 37 layout tests, 1043 suite.

### Addendum V5-hotfix-B — Ghost Preview Live Simulation (2026-02-28)

- **Contexte** : Pendant le drag, seul le ghost (rectangle pointillé) montrait la position cible. Les AUTRES widgets ne bougeaient pas visuellement, donnant une UX statique.
- **Décision** : `previewLayout` computed dans `DashboardGrid.vue`. Pendant drag/resize, exécute `applyPipeline` en simulation avec la position du ghost. Les autres tiles s'affichent à leurs positions résolues avec `transition: grid-column/grid-row 0.15s ease`. `displayLayout` computed sélectionne `previewLayout` ou `sortedLayout` selon l'état.

### Addendum V5-hotfix-C — First-Fit Widget Placement (2026-02-28)

- **Contexte** : `addWidget()` dans les stores platform et company utilisait un algorithme "last row" — il cherchait la ligne la plus basse (`max(y)`) et essayait de placer le widget à droite. Si la ligne était pleine (ex: 8+4=12), le widget allait systématiquement en dessous même si des trous existaient sur d'autres lignes (ex: après suppression d'un widget en milieu de grille).
- **Décision** : Remplacement par un algorithme **first-fit** : scan top-to-bottom (y=0→maxY), left-to-right (x=0→12-w). Pour chaque position candidate (x, y), vérifie l'absence d'overlap rectangle avec tous les tiles existants (`col < t.x + t.w && col + w > t.x && row < t.y + t.h && row + h > t.y`). Premier gap trouvé → placement. Si aucun gap → placement en dessous (`y = maxY`). Complexité O(maxY × 12 × n) — négligeable pour ≤30 widgets.
- **Exemple** : Layout `[revenue_trend x=0,w=8 + ar_outstanding x=0,y=4,w=4]`. Ajout `refund_ratio` (w=4) → first-fit trouve (8, 0) au lieu de (4, 4). Le widget se place à droite de revenue_trend, pas à côté de ar_outstanding sur une ligne inférieure.
- **Fichiers** : `dashboard.store.js` (platform + company) — action `addWidget` réécrite.

## ADR-153 : Hydration Gate — Full Boot Before Layout Mount

- **Date** : 2026-02-28
- **Contexte** : Après login, la sidebar (menu de navigation) restait vide. Un refresh de la page la faisait apparaître. Le problème n'était PAS CSS/visuel mais un défaut d'initialisation du cycle de vie.
- **Cause racine** : Le router guard (`guards.js:43`) n'attendait que `whenAuthResolved()` (Phase 1 : validation session) avant d'autoriser la navigation. Le layout montait AVANT que la Phase 3 (`features:nav` → `navStore.fetchCompanyNav()`) ne complète. Le `navStore.companyGroups` était `[]` au moment du render — la sidebar affichait uniquement les items statiques (Dashboard, Account Settings) sans les sections métier dynamiques.
  - **Login** : login() → teardown() → router.push → guard await auth ONLY → layout mount (nav vide) → features:nav complète tard → sidebar devrait se mettre à jour mais race condition avec le composant `VerticalNavLayout` de Vuexy.
  - **Refresh** : boot froid → même race mais le délai est trop court pour être visible à l'oeil.
- **Décision** : Remplacer `await runtime.whenAuthResolved()` par `await runtime.whenReady(8000)` dans le router guard, pour les deux cas : boot initial (cold/scope switch) et recovery (error state). Le guard bloque la navigation jusqu'à ce que TOUTES les phases soient complétées (auth → tenant → features → ready). Timeout 8s de sécurité — si boot stall, le guard continue et AppShellGate affiche le loading.
- **Conséquences** :
  - Le bouton login reste en état `loading` pendant tout le boot (~300-800ms de plus). L'utilisateur voit une transition directe login → dashboard avec sidebar complète. Zéro flash vide, zéro double render.
  - Les utilisateurs non authentifiés ne sont PAS bloqués : le scheduler court-circuite (`!auth.isLoggedIn → _resolveReady()`) immédiatement après la phase auth. Le guard redirige vers /login sans délai.
  - Les navigations ultérieures (même scope, runtime ready) ne sont PAS affectées : le bloc boot ne s'exécute que si `phase === 'cold'` ou scope change.
  - Les routes module-gated conservent leur propre `whenReady(5000)` en second filet.
- **Fichiers** : `resources/js/plugins/1.router/guards.js` — 2 lignes modifiées (whenAuthResolved → whenReady).

---

> Pour ajouter une décision : copier le template ci-dessus, incrémenter le numéro.
