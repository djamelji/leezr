# Leezr 3.5 — Sécurité RBAC, fiabilisation UX, cohérence Workforce — rapport final (ADR-825)

> Branche `dev` locale. **Aucun push, aucun déploiement.**
> - **Aucune société réelle modifiée.** La remédiation a été **simulée** sur DEV. Elle n'a été **appliquée que sur le clone isolé** `leezr_audit821`, sociétés fictives 109 et 110.
> - Aucune écriture sur la société 1, aucune modification du calcul de paie.

## 1. Commits

| Commit | Objet |
|---|---|
| `6f6cab0b` | feat(rbac) lot 1 — commande de remédiation contrôlée des rôles chauffeur historiques |
| `9f201fb5` | fix(ui) lot 2 — panneaux ouverts depuis l'adresse (campagnes, ligne de paie inconnue, `useAsyncAction`) |
| `d8ff8e69` | fix(ui) lot 2 — panneau Parrainage ouvert depuis l'adresse |
| `d5289755` | docs(workforce) lot 3 — matrice des modèles de temps de travail ; régime affiché pour les exclus |
| `945ed068` | test(e2e) lot 4 — specs historiques réalignées sur le produit actuel |
| lot 6 | docs — rapport, ADR-825, preuves |

## 2. Audit RBAC (reprise de `rbac:driver-role-audit`, ADR-824)

- **Droits à retirer** du rôle système `driver` historique : `members.view`, `settings.view`, `documents.view`. Le troisième n'est présent que sur les sociétés créées avant ADR-823.
- **Droits conservés, jamais touchés** : `shipments.view_own`, `shipments.manage_status`, ainsi que les droits non listés (`theme.*`).
- **Personnalisations explicites** : détectées dans le journal d'audit du rôle (actions `role.*` autres que celles de la remédiation). **Aucune** sur DEV.
- **Dépendances fonctionnelles** :
  - le widget `compliance.rate` exige `documents.view` : il disparaît pour le chauffeur, ce qui est cohérent ;
  - les widgets de livraison n'exigent que `shipments.view_own` ;
  - `settings.view` ne protège que le profil société (`/company/profile`, surface « structure ») : le chauffeur y était **déjà refusé** par la surface. Ce droit était donc inerte pour lui.

## 3. Mode simulation

- `php artisan rbac:driver-role-remediate` : simulation par défaut, sans écriture. Pour chaque société et chaque rôle, la commande indique :
  - le nombre de membres ;
  - les droits retirés et conservés ;
  - les droits de livraison ;
  - les personnalisations détectées ;
  - le statut (`would_apply`, `already_compliant`, `skipped_customised`) ;
  - un avertissement si les droits de livraison sont incomplets.
- `--apply` est **refusé sans `--confirm-database=<nom de la base courante>`**. Ce garde-fou empêche d'écrire sur la mauvaise base.
- Écriture : une transaction par rôle et un audit `role.remediation_applied` (permissions avant / après, lot, droits retirés).
- Reprise : relancer la commande ne traite que ce qui reste (`already_compliant`).
- Restauration : `--restore=<lot>` (simulation, puis `--apply`) réattache exactement les droits retirés par ce lot, avec un audit `role.remediation_restored`. Elle est idempotente.
- Rôle personnalisé : il est sauté, sauf s'il est désigné explicitement par `--role=<id>`.
- **Simulation DEV** (`company-3-5/driver-remediation-simulation-dev.json`) :
  - 8 rôles `would_apply`, 0 personnalisé ;
  - société 1 : rôle 7, 12 membres ;
  - société 101 : 1 membre ;
  - les autres rôles n'ont aucun membre ;
  - nombre de lignes de pivot identique avant et après (852) : **rien écrit**.

## 4. Résultats de la remédiation sur le clone

Clone `leezr_audit821`, limité aux sociétés fictives 109 et 110 :

- **110** : un audit `role.capabilities_changed` a été simulé pour en faire un rôle personnalisé. Résultat : `skipped_customised`, rôle intact.
- **109** : `applied`. Le rôle 199 ne garde que `theme.view`, `theme.manage`, `shipments.view_own` et `shipments.manage_status`.
- **Reprise** : `already_compliant: 1, skipped_customised: 1`.
- **Restauration** : simulation `would_restore: 1`, puis application `restored: 1`, puis nouvelle application (lot `drv-20261118062828-txjo2k`).
- **Droits du chauffeur 109, mesurés dans le navigateur** (`clone-rbac-before.json` / `clone-rbac-after.json`) :

| Accès | Avant | Après |
|---|---|---|
| API `/my-deliveries` | 200 (8) | **200 (8)** |
| API `/company/members` | 200 | **403** |
| API `/company/people` | 200 (2) | **403** |
| API `/company/documents` et conformité | 200 | **403** |
| Page Mes livraisons | accessible | **accessible** |
| Page Équipe `/company/people` | accessible | **refusée (403)** |
| Profil société | refusé (surface) | refusé |
| Hub Réglages | vide | vide (« Aucun réglage n'est accessible avec vos droits ») |
| Coffre documentaire | refusé | refusé |
| Inter-tenant (société 1) | 403 | 403 |

## 5. Plan de migration contrôlée (réel, **non exécuté**)

1. Sauvegarder la base cible.
2. Lancer la simulation sur la base cible et archiver le JSON. Faire relire les sociétés ayant des membres (DEV : sociétés 1 et 101).
3. Prévenir les sociétés concernées : leurs chauffeurs perdent l'annuaire et les documents de la société, mais gardent leurs livraisons et leur espace personnel.
4. Appliquer par société (`--company=<id> --apply --confirm-database=<base>`), en commençant par une société pilote.
5. Faire vérifier le parcours d'un chauffeur.
6. Laisser les rôles personnalisés à la décision de leur société (`--role=<id>`).
7. En cas de besoin, restaurer avec `--restore=<lot>`.
8. **Décision requise** avant toute exécution sur DEV réel ou en production.

## 6. Audit des tiroirs et du routage

Cas vérifiés dans le navigateur, sur le clone :

| Ouverture par l'adresse | Liste | Adresse directe | Rechargement | Fermeture | Précédent | Élément inconnu | Droit insuffisant |
|---|---|---|---|---|---|---|---|
| Paie `?line=` | OK | OK | OK | OK (adresse nettoyée) | quitte la page¹ | adresse nettoyée (**corrigé**) | 403 (chauffeur) |
| Documents › Campagnes `?new=campaign` | OK | **corrigé** | — (demande consommée) | OK | — | — | — |
| Documents `?new=deposit` / `sign` | — | OK (dialogue) | — | OK | — | — | — |
| Fiche `?do=deposit` | — | OK (dialogue) | — | OK | — | — | — |
| Équipe `?action=create` | — | OK | — | OK | — | — | — |
| Parrainage `?open=` | — | **corrigé, non rejoué**² | — | — | — | adresse nettoyée (**corrigé**) | garde de module |

1. Convention ADR-475 : l'état porté par l'adresse utilise `router.replace`. Précédent quitte donc la page au lieu de fermer seulement le panneau. C'est le comportement du produit partout, non modifié (voir D-7).
2. Le module Parrainage n'est pas activable sur la société fictive du clone sans son droit commercial. Ce droit n'a pas été contourné : le correctif est vérifié par lecture et par build uniquement.

## 7. Défauts corrigés

- **Documents › Campagnes** : le lien « Demande collective » du menu Nouveau n'ouvrait jamais le panneau.
  - Cause racine : `useAsyncAction` démarrait « non chargé ». La page affichait son contenu, puis un squelette, puis à nouveau le contenu, ce qui démontait et remontait le panneau. Il démarre désormais en chargement avec `immediate` (6 pages concernées, plus de clignotement).
  - La demande d'ouverture est en outre conservée jusqu'à la fermeture par l'utilisateur.
- **Paie** : un `?line=` inconnu restait dans l'adresse ; il est maintenant nettoyé.
- **Parrainage** :
  - le panneau ouvert par `?open=` se refermait quand `?tab=` réécrivait l'adresse : ajout de `disable-route-watcher` ;
  - un `?open=` inconnu est nettoyé.
- **Planning** : le bouton de repli de la palette n'avait ni `data-test` ni `aria-expanded` (accessibilité) ; ils sont ajoutés.
- **Workforce** : le régime de travail des salariés exclus d'une période de paie est désormais affiché (diagnostic, lecture seule).

## 8. Matrice des modèles de temps de travail

Voir `company-3-5/WORKFORCE-WORK-MODEL-MATRIX.md`. En résumé :

- **La base de paie suit le type de rémunération** (`monthly`, `hourly`, `daily`), **jamais le modèle de temps** (`horaire`, `forfait_heures`, `forfait_jours`).
- Tous les modèles exigent une feuille horaire verrouillée.
- Les heures supplémentaires sont calculées de la même façon pour les forfaits.
- Le temps partiel n'a pas d'heures complémentaires.
- Seul le contrôle de conformité distingue les forfaits (« non vérifiable »).
- Le type de contrat (CDI, CDD…) n'influence aucune règle : c'est conforme à la demande.

## 9. Écarts Workforce (non corrigés : décision métier requise)

1. Forfait jours : une feuille horaire est obligatoire pour être payé, et ses heures supplémentaires seraient valorisées.
2. Forfait heures : le seuil d'heures supplémentaires est le seuil standard, pas le volume du forfait.
3. Temps partiel : pas d'heures complémentaires.
4. Mensualisés : un salarié est exclu faute de feuille verrouillée, même si sa base ne dépend pas des heures.
5. Un contrat DEV (société 21) porte un modèle hors liste (`on_site`).
6. La cohérence modèle × type de rémunération n'est pas vérifiée.

## 10. E2E historiques

Rejoués sur le clone, **sans relâcher aucune assertion**.

| Spec | Cause de l'échec | Traitement |
|---|---|---|
| `e3r-r3` | `/company/workforce/employees/:id` redirige vers la fiche unifiée (ADR-768) ; laboratoire `devOnly` | Interface historique `?legacy=1` pour la barre d'actions ; navigation de section sur la fiche unifiée ; laboratoire sauté hors serveur Vite (garde de routeur) |
| `ux2-team` | Deux champs « Rechercher » (le tiroir fermé des champs personnalisés en porte un) | Ciblage du champ de la liste |
| `workforce-grid-refonte` | Format commun des durées (« 16 h ») | Expression régulière du format actuel ; le résumé heures · jours reste exigé |
| `workforce-lot4b3b-undo` A | Palette repliée par défaut (préférence mémorisée) | Ouverture par son bouton |

- **Résultat : 18 réussis, 2 sautés (laboratoire dev-only ; un cas d'annulation sans donnée applicable), 0 échec.**
- Les démarrages de 30 s observés sur le clone ne viennent pas du produit. Le serveur PHP intégré ne sert un fichier statique qu'une fois le flux temps réel de la page terminé. Le DEV sous nginx démarre en moins d'une seconde (mesuré). Le serveur de recette a été relancé avec des flux courts (variables d'environnement, aucun code modifié).

## 11. Recette navigateur

- RBAC avant / après sur le clone : section 4.
- Tiroirs : section 6.
- Workforce : la période 28 de la société fictive 109, avec un contrat passé en forfait jours sur le clone, affiche « Yanis Chauffeur51718 (forfait jours) » parmi les exclus (`company-3-5/payroll-work-model.jpg`).
- Captures : `company-3-5/before-_company_people.jpg`, `after-_company_people.jpg`, `after-_company_settings.jpg`.

## 12. Tests et build

- Ciblés : remédiation 2/2 ; validation de paie 7/7 (dont le diagnostic du régime) ; Document, Support et Invoice 600 réussis.
- Suite complète : **4714 réussis, 12 sautés, 3 échecs** (`ControllerSizeInvariantTest` : échecs antérieurs connus. Aucun contrôleur modifié ici n'y figure).
- `pnpm build` : propre.
- E2E : section 10. Les captures écrites par les specs dans `docs/bmad/ux2-777/` et absentes au départ ont été restaurées.

## 13. Risques résiduels

- La remédiation réelle n'est **pas exécutée** : les chauffeurs historiques de DEV (société 1 : 12 membres ; société 101 : 1 membre) gardent l'annuaire et les documents de la société tant qu'elle n'est pas décidée.
- **Flux temps réel** : une connexion fermée n'est détectée qu'au battement suivant (30 s). Sous PHP-FPM, chaque navigation complète peut garder un worker jusqu'à 30 s. À surveiller en charge (pool FPM).
- Le correctif Parrainage n'a pas été rejoué dans le navigateur.
- Écarts Workforce 1 à 6 : un forfait jours ou un temps partiel peut être mal préparé en paie tant que D-1 à D-6 ne sont pas tranchées. Le diagnostic les rend visibles.
- Suppression du laboratoire `devOnly` des builds : sa spec n'est exécutable qu'avec `pnpm dev`.

## 14. Décisions métier attendues

- **D-1 à D-6** : modèles de temps (matrice, section 5).
- **D-7** : le bouton Précédent doit-il fermer un panneau ouvert, au lieu de quitter la page ? Cela changerait la convention ADR-475 (`replace` → `push`).
- **D-8** : exécution de la remédiation sur DEV réel, puis en production : calendrier, société pilote, communication aux sociétés.
- **D-9** : correction du contrat `on_site` de la société 21.
