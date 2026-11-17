# ADR-815 — Accueil, tableau de bord et contexte utilisateur

> **Mode audit.** Aucune modification. Les observations navigateur ont été faites sur un **clone isolé** de la base DEV (`leezr_audit815`, servi sur `http://127.0.0.1:8815`), pour que les connexions de recette n'écrivent rien dans la base DEV. Les mots de passe des comptes de recette n'ont été redéfinis que dans ce clone.
>
> Base de code : `70ec3dbe`.
>
> Captures : `docs/bmad/adr815/shots/dash-*.jpg`.

## 1. Question posée

`https://leezr.test/home` et `https://leezr.test/dashboard` « affichent notamment des widgets associés à Alice ». Pourquoi ?

## 2. Réponse prouvée

**Cause G (autre cause prouvée) : le navigateur est connecté avec le compte d'Alice.** Le compte `admin@leezr.test` **est** la personne Alice Martin : utilisateur 2, salariée 2 de la société 1, rôle « Dispatcher », administratif.

Preuves (lecture seule de la base DEV) :

| Élément | Valeur |
|---|---|
| `users.id=2` | Alice Martin, `admin@leezr.test` |
| `workforce_employees.id=2` | Alice Martin, `user_id=2`, société 1 |
| Session la plus récente de la base | `user_id=2`, vrai Chrome (`Chrome/157`, pas un navigateur automatisé), dernière activité **2026-11-17 01:18**, dernière URL `/api/realtime/stream?company_id=1` |
| Disposition du tableau de bord d'Alice | `company_dashboard_layouts.id=60` (`user_id=2`), **modifiée le 2026-11-17 à 01:07**, depuis cette session (aucun script de l'audit n'a agi sous ce compte) |
| `users.last_login_at` (id 2) | 2026-11-09 : la session est conservée depuis plusieurs jours |

Reproduction sur le clone, avec 7 profils de la société 1. Le mot « Alice » a été recherché dans tout le contenu de `/home` et `/dashboard` :

| Profil | Rôle RBAC (archétype) | Arrivée après connexion | « Alice » sur `/home` | « Alice » sur `/dashboard` |
|---|---|---|---|---|
| Propriétaire `owner@leezr.test` | Propriétaire (management) | `/dashboard` | 0 | 0 |
| **Alice** `admin@leezr.test` | Dispatcher (operations_center) | `/dashboard` | **1** (« Bonjour, Alice ») | **1** |
| Administrateur `ops@leezr.test` | Responsable exploitation (aucun) | `/dashboard` | 0 | 0 |
| Manager `manager@leezr.test` | Manager équipe (aucun) | `/dashboard` | 0 | 0 |
| RH `rh-nopay@leezr.test` | RH sans paie (aucun) | `/dashboard` | 0 | 0 |
| Salarié `salarie@leezr.test` | Salarié (aucun, **non administratif**) | `/dashboard` | 0 | 0 |
| Chauffeur `bob@leezr.test` | Driver (field_worker) | `/home` | 0 | 0 |

Aucune donnée d'Alice n'est servie à un autre profil par les widgets : l'objet `widget_data` ne contient « Alice » pour aucun profil.

**Hypothèses écartées par la preuve** :
- **C. Cache partagé** : non. Il n'existe aucune clé de stockage navigateur ni de cache serveur pour la disposition ; elle est lue en base à chaque appel `GET /api/dashboard/layout`.
- **D. Mauvaise résolution de contexte** : non. La disposition est résolue avec l'utilisateur connecté et son rôle (`CompanyDashboardLayoutController.php:show`).
- **E. Erreur d'autorisation exposant Alice** : non pour Alice. Une exposition différente existe toutefois (§4, constat C2).

**Ce qui rend la confusion possible** (causes structurelles, prouvées) :
1. **L'identifiant `admin@…` désigne une personne.** Le compte d'administration de démonstration est le compte personnel d'Alice. Toute la partie personnelle de l'accueil (« Bonjour, Alice », mon pointage, mon prochain créneau, mes documents) est donc celle d'Alice.
2. **`/home` et `/dashboard` affichent la même grille.** Les deux écrans appellent le même moteur (`createSurfaceEngine`) sur les mêmes points d'API : `GET /api/dashboard/layout`, `GET /api/dashboard/widgets/catalog`, `POST /api/dashboard/widgets/data`. Voir `resources/js/modules/company/home/home.store.js` et `resources/js/modules/company/dashboard/dashboard.store.js`. Une disposition personnalisée sur l'un apparaît sur l'autre. L'« Accueil personnel » du propriétaire affiche sous « Mes widgets » les 11 indicateurs **de l'entreprise** : effectifs 52, présents, anomalies, expéditions, conformité, abonnement (`shots/dash-owner-home.jpg`).
3. **Ouvrir `/home` peut écrire la disposition du tableau de bord.** `home.store.js:loadDashboard` enregistre une disposition « compacte » (`PUT /api/dashboard/layout`) si l'utilisateur n'en a aucune et qu'il est administratif.

**Conclusion** : **G** (compte personnel utilisé comme compte d'administration), aggravé par **B** (confusion entre accueil personnel et tableau de bord de l'entreprise) et **F** (duplication fonctionnelle : un seul moteur, une seule grille, deux pages).

## 3. Mécanique actuelle (code)

```
Connexion ─▶ WorkspaceResolver::resolve(archetype du rôle RBAC)      app/Core/Auth/WorkspaceResolver.php
              ├─ 'field_worker'      → workspace 'home'       → arrivée /home
              └─ tout le reste (y compris archétype NULL)      → arrivée /dashboard
Garde routeur (guards.js:118) : /dashboard + workspace 'home' ⇒ /home  (l'inverse est permis)

/home      (pages/home.vue, sans definePage : ni module ni permission)
   ├─ partie personnelle : /profile/space, /workforce/me (pointage), planning, congés, livraisons
   └─ « Mes widgets » : surface-engine ─┐
/dashboard (pages/dashboard.vue, meta module core.dashboard)       │  MÊMES appels
   ├─ « À traiter » GET /dashboard/priorities, actions             │
   └─ « Vue d'ensemble » : surface-engine ────────────────────────┘
        GET  /dashboard/widgets/catalog → DashboardCatalogService::forArchetype(company, archétype, permissions, owner)
        GET  /dashboard/layout          → CompanyDashboardLayout::resolveForUser : utilisateur > rôle > société
        POST /dashboard/widgets/data    → widget->resolve(['scope'=>'company','company_id'=>…])   ← aucun utilisateur transmis
        PUT  /dashboard/layout          → company.access:manage-structure ; enregistre (company_id, user_id)
```

**Portée des réglages** : la disposition est enregistrée **par utilisateur** (`company_dashboard_layouts.user_id`). Si l'utilisateur n'en a pas, on se rabat sur la disposition du rôle (`company_role_id`), puis sur celle de la société (`user_id` et `company_role_id` NULL), puis sur une disposition construite côté navigateur. Aucune clé `localStorage` n'est utilisée.

État dans la société 1 : 4 dispositions, appartenant aux utilisateurs 1 (propriétaire), 2 (Alice), 51 (RH) et 55 (manager). Aucune disposition de rôle ni de société.

## 4. Constats confirmés

| ID | Gravité | Constat | Preuve |
|---|---|---|---|
| **C1** | P2 | `/home` « Accueil personnel » reprend la grille collective du tableau de bord. Pour le propriétaire, ce sont 11 indicateurs de l'entreprise sous le titre « Mes widgets ». | `shots/dash-owner-home.jpg`. La disposition renvoyée est identique sur les deux pages pour chaque profil (`dash-context.json`). |
| **C2** | **P0 (confidentialité, agrégats)** | Un **salarié** (rôle « Salarié », non administratif) reçoit des **chiffres de toute l'entreprise** : présents, non pointés, anomalies, heures de la semaine, congés en attente de **tous** (1, qui n'est pas le sien). Les widgets `workforce.clock_live`, `workforce.anomalies` et `workforce.hours_trend` sont gardés par `workforce.timesheet_view`, permission décrite comme « View own timesheets » (`WorkforceModule.php:70`). `workforce.leave_pending` est gardé par `workforce.leave_request`, le droit de *demander* un congé. Pourtant, `/api/workforce/timesheets` renvoie au même salarié une liste vide (scopée) et `/api/workforce/employees` un 403. | Réponse HTTP sur le clone : `{"working":0,"on_break":0,"not_clocked":1,…}`, `{"pending":1}`. Aucun nom n'est exposé. |
| **C3** | P1 | Le salarié **arrive sur le tableau de bord de gestion** (`/dashboard`, « Vue d'ensemble »). L'atterrissage ne dépend que de l'archétype du rôle RBAC : un rôle personnalisé sans archétype (cas de tout rôle créé par la société) part toujours sur `/dashboard`. | `WorkspaceResolver.php` ; arrivée `/dashboard` pour `salarie@leezr.test`. |
| **C4** | P2 | Ouvrir `/home` peut **enregistrer** la disposition du tableau de bord : effet de bord d'une page de consultation. | `home.store.js` (`saveLayout` dans `loadDashboard`). |
| **C5** | P2 | La résolution des widgets (`batchResolve`) ne transmet **ni utilisateur ni salarié**. Les widgets « personnels » lisent `auth()->id()` en dehors du contrat (par exemple `DeliveriesMyTodayWidget`). Le contrat d'un widget ne permet donc pas de distinguer « moi » de « l'entreprise ». | `CompanyDashboardWidgetController.php:batchResolve`. |
| **C6** | P3 | Incohérence affichée chez le propriétaire : « Documents à régulariser **1** » en haut de page, mais les widgets « Taux de conformité », « Documents à régulariser » et « Conformité par rôle » indiquent « Aucun document requis configuré ». Pourtant la société 1 a 3 pièces rendues obligatoires et 58 demandes ouvertes. | `shots/dash-owner-home.jpg`. **Cause non établie** : les widgets lisent `GET /company/documents/compliance` (réponse 200), le haut de page lit `/profile/space`. |
| **C7** | P3 | « Conformité par rôle » agrège la conformité par **rôle RBAC**. | `DocumentComplianceReadModel.php:181`. Voir `people-jobrole-rbac-model.md`. |

## 5. Architecture cible proposée

**Un seul écran d'accueil, `/home`**, organisé en **vues adaptées**. Il n'y a pas trois tableaux de bord.

```
/home  (Accueil — tout utilisateur connecté)
 ├─ Bloc « Moi »          : visible si une fiche salarié m'est liée ou si j'ai des actions personnelles
 │                          (pointage, prochain créneau, mes congés, mes pièces à fournir, mes signatures, mes livraisons)
 │                          données : uniquement les miennes (le serveur résout l'utilisateur ET le salarié lié)
 ├─ Bloc « À traiter »    : visible si j'ai au moins une capacité de gestion
 │                          (validations, demandes, anomalies — dans MON périmètre : société, équipe ou rien)
 └─ Bloc « Entreprise »   : visible si j'ai un droit de LECTURE COLLECTIVE explicite
                            (indicateurs ; grille personnalisable, enregistrée par utilisateur, jamais écrite en consultation)
/dashboard → alias de /home (lien historique conservé)
```

Règles :
- **La portée d'un widget est déclarée et vérifiée côté serveur** : `self` (moi), `team` (mon périmètre managérial) ou `company`. Le contexte de résolution reçoit l'utilisateur et le salarié lié. Un widget `company` exige une permission de lecture collective, jamais une permission « own ».
- **L'arrivée après connexion n'est plus pilotée par l'archétype RBAC.** Tout le monde arrive sur `/home`, et les blocs dépendent des capacités réelles. Cela supprime C3 sans maintenir deux espaces.
- **Pas de tableau de bord « métier » séparé.** Les vues spécialisées (planning, pointage, expéditions) existent déjà comme pages d'outils.
- **Les comptes de démonstration ont des identifiants nominatifs** (par exemple `alice.martin@…`). Hors code : décision de données de recette.

Avant / après pour le propriétaire :

| Avant | Après |
|---|---|
| `/dashboard` : À traiter, Actions, Vue d'ensemble | `/home` : À traiter (société), Entreprise |
| `/home` : Accueil personnel, puis la même Vue d'ensemble sous « Mes widgets » | même page `/home` : bloc Moi (s'il est salarié) |
| deux URL, une seule grille | une seule URL, trois blocs sans doublon |

## 6. Questions de décision produit

1. Un salarié doit-il voir des chiffres collectifs (présents, congés en attente) ? La recommandation est **non**, sauf permission explicite de lecture collective.
2. Le chauffeur doit-il conserver l'annuaire complet de la société (voir `company-routes-permissions.md`, constat R1) ?
3. Faut-il fusionner `/dashboard` dans `/home` (recommandé), ou garder deux URL avec deux contenus strictement distincts ?
