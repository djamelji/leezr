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

---

> Pour ajouter une décision : copier le template ci-dessus, incrémenter le numéro.
