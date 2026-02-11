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

---

> Pour ajouter une décision : copier le template ci-dessus, incrémenter le numéro.
