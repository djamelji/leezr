# Leezr 4.2 — 03 · Audit Company (navigation réelle)

> Captures : sélection versionnée dans `captures/` (nommées `<profil>__<page>.jpg`, parcours `J*.jpg`) ; l'ensemble des ~900 captures reste en local dans `data/crawl/shots/` (non versionné, 44 Mo).

## Méthode, sociétés et profils

Même méthode que 02 (navigation réelle, clic sur chaque onglet, mobile 390 px, axe, relevé des erreurs) sur des **sociétés fictives** de la base de recette, choisies pour couvrir plusieurs **métiers, plans et combinaisons de modules**. Les mots de passe des comptes fictifs ont été remplacés **dans le clone seulement**.

| Profil | Société (métier · plan · modules) | Entrées de menu | Pages visitées | Résultat |
|---|---|---|---|---|
| Propriétaire | 1 Leezr Logistics (Logistique · Pro · 21 modules dont RH, congés, planning, paie, documents) | 29 | 71 + 40 | 1 défaut (Facturation › Activité 404) |
| Manager | 1 (rôle recette-manager : congés, planning, feuilles de temps) | 11 | 52 | aucune erreur |
| Salarié | 1 (recette-salarie) | 5 | 35 | aucune erreur |
| Consultation | 1 (recette-consultation) | 7 | 40 | aucune erreur |
| Chauffeur | 1 (driver) | 5 | 36 | aucune erreur ; **voit l'annuaire** (SEC-04) |
| Dispatcher | 1 (dispatcher) | 13 | 45 | aucune erreur |
| Gestionnaire de paie | 1 (recette-pay : 4 droits paie) | 4 | 34 | menu exactement conforme à ses droits |
| Propriétaire en essai | 2 Trial Logistics (Logistique · Pro · sans RH) | 16 | 49 | modules non actifs → redirection silencieuse |
| Propriétaire Starter | 3 Pending Approval Co (Logistique · Starter) | 16 | 49 | idem |
| Propriétaire Beta Services | 5 Beta Services (Beta Services · Pro · RH, congés, paie, planning) | 26 | 59 | aucune erreur hors 404 connu |
| Propriétaire Beta Services | 58 VORTEX (Beta Services · Starter · 7 modules RH) | 27 | 65 | aucune erreur hors 404 connu |
| Membre limité | 22 Sec Recette (rôle « collab ») | 8 | 38 | refus propres (page 403 Company) |
| Visiteur non connecté | — (pages publiques) | — | 40 | correct (centre d'aide, connexion, inscription…) |

## Parcours complets joués

| ID | Parcours | Résultat |
|---|---|---|
| J2 | Le propriétaire d'une société créée par Platform reçoit l'e-mail, choisit son mot de passe, arrive sur l'accueil « Bienvenue — 2/5 étapes » | **Réussi** |
| J3 | Modules : la page distingue « inclus dans votre offre », « options », « hors offre » | **Réussi** (pas d'invitation à monter en gamme visible : UXC-05) |
| J3b | « Ajouter une personne » avec invitation | **Partiel** : formulaire clair (prénom, nom, « aura-t-elle besoin de se connecter ? », e-mail, rôle) ; l'envoi n'a pas été piloté par le robot (liste Vuetify) — l'envoi d'invitation est prouvé par la recette ADR-834 |
| J4 | Salarié : « Mon espace », puis « Demander un congé » | **Réussi** : le panneau explique pourquoi aucun solde n'est ouvert (contrat + types de congés à configurer) |
| J5 | Propriétaire : nouveau ticket de support → visible côté Platform | **Réussi** |

**Isolation** (`data/isolation.json`) : 19 tentatives d'accès hors droits ou hors société refusées sur 20 (voir 06, SEC-04 et SEC-14).

## Différences « module » — ce que voit réellement l'utilisateur

| Situation | Ce qui se passe aujourd'hui | Attendu |
|---|---|---|
| Permission manquante (module actif) | Page **403 Company** explicite (`/company/403`) | ✔ conforme |
| Module **non activé** (société 2, 3, 22) | Les adresses du module **renvoient à l'accueil sans message** | Page « Ce module n'est pas activé » + bouton « Activer » (si `modules.manage`) ou « Demandez à votre administrateur » |
| Module **hors plan** | Page Modules : filtre « Hors offre (0) » ; carte sans parcours de montée en gamme | Carte « Disponible avec Pro » + aperçu du prix + action « Changer d'offre » |
| Module **dépendant** | Carte « Nécessite : Ressources humaines ✓ », « Utilisé par : … » | ✔ clair (activation automatique des dépendances) |
| Fonctionnalité réellement indisponible (données absentes) | États vides explicites (« Aucun créneau… », « Aucun solde… ») | ✔ conforme |

## Constats (UXC)

| ID | Page / composant | Problème | Preuve | Impact | Gravité | Correction | Effort | Prio |
|---|---|---|---|---|---|---|---|---|
| UXC-01 | Facturation › **Activité** | L'onglet appelle `/api/company/billing/timeline` alors que la route est `/api/billing/timeline` → **404**, onglet vide **dans toutes les sociétés**. | `_BillingTimeline.vue:59` vs `routes/company.php:135` ; 21 erreurs sur 6 profils. | Historique de facturation inaccessible. | **Élevée** | Corriger l'URL (1 ligne) + test de contrat « toutes les URL du front existent côté API ». | XS | **P1** |
| UXC-02 | Modules non activés | Redirection **silencieuse** vers l'accueil (sociétés 2, 3, 5, 22). | Crawls c2/c3/c5/c22. | L'utilisateur ne sait pas que la fonction existe ni comment l'obtenir. | Moyenne | Page « module non activé » (voir tableau ci-dessus), une seule règle pour toutes les routes de module. | S | P2 |
| UXC-03 | Modules (cartes) | Puce **« Configuration historique »** sur chaque module actif. | Capture `company_modules.jpg`. | Jargon technique incompréhensible pour un client. | Faible | Supprimer la puce ou la remplacer par « Configuré » / « À configurer ». | XS | P2 |
| UXC-04 | Équipe | Menu « **Équipe › Équipe** » ; deux actions principales voisines « Embaucher » et « Ajouter une personne » sans explication de la différence ; rôles affichés en anglais (« Driver », « Dispatcher ») ; statuts « Compte connecté » / « Compte activé » ambigus ; personnes sans nom affichées par leur e-mail. | Capture `company_people.jpg`. | Hésitation sur la bonne action ; vocabulaire mixte. | Moyenne | Une action principale « Ajouter » avec choix *Embauche complète* / *Accès seulement* ; libellés de rôles traduits (décision C4 en attente) ; un seul vocabulaire de statut d'accès. | S | P2 |
| UXC-05 | Modules / Offre | Pas de parcours de **montée en gamme** depuis une fonction indisponible. | Parcours J3 (`upgrade: false`). | Perte de revenu, frustration. | Moyenne | « Disponible avec … » + aperçu `plan-change-preview` + confirmation. | M | P2 |
| UXC-06 | Feuilles de temps, Postes | Libellés **anglais** : « Items per page », « Cancel », « Create ». « Status » dans les modèles de documents. | Crawl (englishHints). | Finition. | Faible | Locale Vuetify des tableaux + clés manquantes. | XS | P3 |
| UXC-07 | Réglages Workforce | Page ouverte avec `workforce.view` seul (manager) ; elle n'indique pas qu'elle est en **lecture seule** pour lui. | `settings/[tab].vue` meta ; rôle recette-manager. | Le manager modifie puis découvre le refus. | Faible | Mode lecture explicite (champs désactivés + « Réservé à … ») quand `workforce.manage` manque. | S | P3 |
| UXC-08 | `/onboarding` (dossier d'intégration) | Ouverte par un utilisateur connecté : appel `GET /api/public/onboarding` → 401 (la page attend la session de la recrue). | 12 occurrences (tous profils). | Erreur visible si un membre suit un ancien lien. | Faible | Message « Ce lien est réservé à la personne invitée » au lieu d'un échec d'API. | XS | P3 |
| UXC-09 | Accueil / tableaux | Accessibilité : boutons-icônes sans nom, contraste (voir UXP-11/15, composants partagés). | axe. | Lecteurs d'écran. | Faible | Corrigé une fois dans les composants partagés. | — | P3 |
| UXC-10 | Alias historiques | 16 anciennes adresses redirigent correctement (`/company/members`, `/company/plan`, `/company/workforce/employees`…) — bon pour les favoris, mais elles restent dans le code. | 01-inventaire, crawl. | Aucun pour l'utilisateur. | Info | Garder un an puis retirer (TEC-01). | — | P4 |

## Bons points

- **Accueil opérationnel** : « À traiter » (intégrations, feuilles de temps, congés, expéditions, signatures) + actions rapides + pointage ; c'est le bon modèle de tableau de bord.
- **Réglages** rangés par sujet métier, avec recherche et repère « Essentiel » / « informations à compléter ».
- **Menus par profil** conformes aux permissions : aucun refus 403 dans le menu d'aucun profil Company (les 403 n'apparaissent que sur des adresses saisies hors menu, avec une page explicite).
- États vides explicatifs (congés sans solde, planning vide), parcours d'activation de compte fluide.
- 0 erreur JavaScript, 0 erreur serveur, 0 débordement mobile sur les 13 profils Company/public.

## Notes par section (sur 100)

Mêmes critères que 02 (Clarté, Action, Fiabilité, Permissions, Finition — 20 pts chacun).

| Section | Clarté | Action | Fiabilité | Permissions | Finition | **Note** | Constats |
|---|---|---|---|---|---|---|---|
| Accueil | 18 | 18 | 18 | 18 | 14 | **86** | — |
| Équipe (personnes, embauches, contrats, rôles) | 14 | 13 | 17 | 16 | 12 | **72** | UXC-04, SEC-04 |
| Planning & temps (planning, pointage, feuilles, congés) | 15 | 16 | 17 | 17 | 12 | **77** | UXC-06, UXC-07 |
| Documents (à traiter, personnes, signatures, modèles) | 16 | 16 | 18 | 17 | 13 | **80** | UXC-06 |
| Préparation de la paie | 15 | 15 | 17 | 19 | 14 | **80** | — |
| Expéditions / Mes livraisons | 16 | 16 | 18 | 17 | 14 | **81** | — |
| Modules | 15 | 13 | 18 | 17 | 12 | **75** | UXC-02, 03, 05 |
| Réglages | 18 | 16 | 18 | 15 | 14 | **81** | UXC-07 |
| Abonnement & facturation | 15 | 14 | 10 | 17 | 13 | **69** | UXC-01, UXC-05 |
| Mon espace / Mon travail (salarié) | 17 | 17 | 18 | 18 | 14 | **84** | — |
| Support, notifications, audit | 16 | 16 | 18 | 17 | 13 | **80** | — |
| **Moyenne Company** | | | | | | **78** | |
