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

## ADR-008 : Modèle de tenancy — à trancher

- **Date** : 2026-02-11
- **Contexte** : Multi-tenant nécessite un modèle d'isolation des données. Deux options : DB partagée (colonne `company_id`) ou DB par tenant.
- **Décision** : **À trancher** lors de la phase Architecture (`03-architecture.md`). Cette décision impacte chaque table, chaque query, chaque migration, le RGPD.
- **Conséquences** : Bloquant pour `03-architecture.md`. Doit être décidé avant toute migration métier.

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
  - Vite détecte les certificats TLS Valet via `detectTls: 'leezr'`
  - `pnpm dev:all` lance uniquement Vite (Valet gère PHP en arrière-plan)
  - `php artisan serve` n'est plus utilisé
- **Conséquences** : `APP_URL=https://leezr.test` dans `.env`. Le script `dev:server` est supprimé de `package.json`. `pnpm dev:all` = `pnpm dev` (Vite seul). Valet doit être installé et démarré sur chaque machine de développement.

---

> Pour ajouter une décision : copier le template ci-dessus, incrémenter le numéro.
