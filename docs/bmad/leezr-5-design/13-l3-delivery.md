# Leezr 5.0 — 13 · Livraison L3 · Accueil Company V22 (ADR-857)

Livré en local sur `dev`, sans push ni déploiement. Tout est construit sur l'existant : moteur de dashboard, catalogue autorisé, priorités serveur (ADR-776), actions servies (ADR-775), checklist d'onboarding (ADR-383) et composants L0. Billing, RBAC, droits historiques et onboarding sont inchangés. La recette tourne sur le clone marqué `leezr_audit842`.

## 1. Composition V22

| Zone | Contenu | Source |
|---|---|---|
| En-tête (bande de marque) | salutation selon l'heure, prénom (sinon nom de la société), date, société, personnes en activité, **phrase de situation**, anneau de progression, 1 ou 2 actions | `WorkspaceHero` → `AppPageHero` workspace |
| À traiter (colonne droite) | priorités du rôle, chacune ouvre son traitement | `/dashboard/priorities` (inchangé) |
| Actions rapides | tuiles V22 (survol, entrée échelonnée), sans les actions déjà dans l'en-tête | actions servies (`nav.actions`, filtrées serveur) |
| Moi | le bloc personnel du gestionnaire qui est aussi salarié ; le titre « Moi » ouvre désormais le bloc au lieu de le suivre | `PersonalHome embedded` |
| Vue d'ensemble | la grille personnalisable (L4) | moteur existant |

**Phrase de situation** (`workspaceSituation.js`, fonction pure testée) : elle n'affirme que ce que les données prouvent.
- Livraisons terminées sur prévues, retards, expéditions à affecter, personnes au travail, nombre d'éléments à traiter.
- « Aucune action ne vous attend » seulement si l'API des priorités a répondu avec une liste vide et complète.
- Une donnée en erreur n'est jamais lue comme un zéro.
- Les indicateurs lus sont pris dans le catalogue **autorisé** de l'utilisateur : les valeurs déjà résolues par la grille sont réutilisées, les manquantes sont demandées en un seul appel, et une clé hors catalogue n'est jamais demandée.

**Actions de l'en-tête** :
- la priorité la plus urgente (gravité, puis nombre), qui ouvre son traitement ;
- puis la première action servie du rôle.

Le contrat ADR-775 est conservé : chaque action servie apparaît une seule fois sur la page (`home-action-<clé>`), dans l'en-tête ou en tuile.

## 2. Composition selon le métier

Le métier est déduit des **modules actifs** (le catalogue autorisé), jamais d'une clé de JobDomain codée en dur (ADR-562) :

- **Logistique** (widgets d'expédition présents) :
  - la disposition recommandée commence par la bande du jour (expéditions du jour, en transit, livrées, en retard), puis la synthèse et la flotte, puis Workforce, puis Documents ;
  - la même priorité s'applique sur mobile.
- **Workforce sans Logistique** (ex. Beta Services) : présences, planning, anomalies et heures. Aucun rendez-vous, aucune mission, aucun revenu inventés : le module `scheduling` (ADR-563) n'existe pas encore.
- **Bande incomplète** (des tuiles retirées selon le rôle ou les modules) : la largeur est répartie sur les 12 colonnes, pour qu'aucun trou ne soit comblé par une tuile d'une autre section. Sur tablette et mobile, ces KPI élargis restent appariés deux par deux.

## 3. Accueil selon le rôle (recette sur clone)

| Profil | Accueil | Contrôle |
|---|---|---|
| Propriétaire Logistique (`owner`) | en-tête de pilotage, 5 priorités, 4 actions, bande Logistique en tête | ✅ |
| Dispatcher (`admin@leezr.test`) | expéditions à affecter, sa disposition enregistrée conservée | ✅ |
| Manager | ses 3 priorités d'équipe, planning en action | ✅ |
| Salarié | accueil personnel V22 | ✅ |
| Chauffeur (`bob`) | « Bonne tournée », ses livraisons, son pointage, « Mes livraisons » et « Mon planning » ; aucun appel de gestion | ✅ |
| Paie (`pay`) | « Aucune action ne vous attend » (prouvé), vue d'ensemble vide utile | ✅ |
| Utilisateur limité (`consultation`) | uniquement ses priorités autorisées, aucun indicateur | ✅ |
| Propriétaire d'un autre métier (`ownerb`, beta-services) | aucune mention d'expédition, aucun widget Logistique | ✅ |
| Société récente (`pending`, société 3) | parcours de mise en service : anneau « 40 % mise en service », la checklist **existante**, « premières opérations » à la place des zéros, « Afficher les indicateurs » à la demande | ✅ |

Pour tous ces profils : 0 erreur JavaScript, 0 appel API refusé, 0 débordement, en desktop, tablette et mobile, en clair et en sombre.

## 4. Mise en service

Le mode s'active quand **chaque** indicateur connu est vide : aucune expédition, aucun créneau, au plus 1 personne, aucun pointage. Si rien n'est connu, le mode ne s'active pas. Pour le propriétaire, l'anneau suit la progression de la checklist d'onboarding existante : c'est la même API, il n'y a pas de seconde checklist. L'accueil passe de lui-même en pilotage dès la première opération.

## 5. Animations

- L'en-tête s'élève à l'apparition.
- Les cartes de la grille et les tuiles d'action entrent en séquence (transformation seule : le contenu n'est jamais masqué).
- Les compteurs progressent (`useCountUp`), et les tuiles d'action se soulèvent au survol, chevron compris.
- Tout est désactivé avec `prefers-reduced-motion`.

## 6. Cohérence avec Platform V35

| | Platform V35 | Company V22 |
|---|---|---|
| En-tête | typographique, clair (`cockpit`) | bande de marque, anneau de progression (`workspace`) |
| Objet | pilotage du SaaS | travail du jour et pilotage métier |
| Partagé | `AppPageHero`, `AppKpiTile`, `AppWidgetShell`, `AppEmptyState`, jetons `styles.scss`, couleurs sémantiques, règles responsive de la grille | idem |

Comparaison à largeur identique : `captures/l3/apres/cockpit-platform-v35-desktop.jpg` et `captures/l3/apres/home-owner-desktop.jpg`.

## 7. Captures

- `captures/l3/avant/` : 9 profils, desktop et mobile, sur le build L4.
- `captures/l3/apres/` : les mêmes profils et largeurs, plus tablette (propriétaire) et sombre (propriétaire, chauffeur, société récente, autre métier).
- `captures/l3/recipe-*` : captures de la recette E2E.

## 8. Tests

- JS `workspace-situation.test.mjs` 7/7 (JS total 56/56).
- E2E `audit842-workspace857-recipe` 10/10 : 4 parcours, plus le contrat de l'accueil (priorités et actions servies, une fois chacune, sans refus) pour 6 profils.
- Non-régression : recettes `widgets856` 4/4 et `cockpit855` 5/5.
- PHP ciblés (tableau de bord, accueil, priorités, onboarding, mise en page) : 68/68.
- Aucun changement backend dans ce lot : la suite complète n'a pas été relancée. Dernière exécution complète : 4908 réussis, 0 échec (ADR-856).

## 9. Limites restantes

- **Pas de liste des personnes présentes ni d'agenda d'équipe** dans l'en-tête : aucune lecture d'équipe autorisée n'existe pour l'accueil. Le prototype les montrait avec des données fictives.
- **Fil d'activité** : non intégré, faute de source d'activité Company dédiée (les notifications sont personnelles).
- **Prénoms vides** : plusieurs comptes du clone n'ont pas de prénom, l'en-tête affiche alors le nom de la société.
- **Dispositions enregistrées** (manager, dispatcher) : elles sont conservées telles quelles et peuvent laisser des espaces. « Disposition recommandée » les rééquilibre.
- **Responsable paie** : aucun widget de paie au catalogue. Son accueil se limite à l'en-tête et aux priorités.
- **Chauffeur** : pas de « prochain arrêt » détaillé dans l'en-tête ; le prochain créneau et ses livraisons y sont.
