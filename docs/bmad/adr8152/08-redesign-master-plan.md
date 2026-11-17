# ADR-815.2 — 08. Plan directeur de refonte intégrale de l'espace Company

> Recalculé à partir des 57 pages (`01`), des fiches (`02`), des 26 parcours (`03`) et des contradictions (`04`). **Il ne reprend pas R1–R8 d'ADR-815** : les lots sont regroupés par **résultat utilisateur**.
>
> **Catégories** :
> - **SÉC** : sécurité urgente ;
> - **BLQ** : parcours métier bloquant ;
> - **ERG** : refonte ergonomique globale ;
> - **TEC** : rationalisation technique ;
> - **FUT** : hors périmètre.
>
> **Complexité relative** : S < M < L < XL.

## Vue d'ensemble

| Ordre | Lot | Catégorie | Résultat utilisateur | Complexité |
|---|---|---|---|---|
| 0 | **L0 — Correctifs de sécurité urgents** | SÉC | Personne ne voit ce qui ne le concerne pas | S |
| 1 | **L1 — Un seul modèle Personne / Poste / Rôle d'accès** | BLQ | « Je sais ce qu'est un poste et ce qu'est un accès » | L |
| 2 | **L2 — Embaucher, sur place ou à distance** | BLQ | Une embauche complète en un assistant | L |
| 3 | **L3 — Une seule chaîne documentaire** | BLQ | Toute pièce attendue est au même endroit, pour tous | M |
| 4 | **L4 — Fiche personne et Équipe refondues** | ERG | Une fiche lisible, un seul formulaire par sujet | M |
| 5 | **L5 — Un seul accueil, des vues par profil** | ERG | J'arrive sur ce que j'ai à faire | M |
| 6 | **L6 — Travail : planning, temps, absences pour gérer ; Mon activité pour soi** | BLQ + ERG | La gestion et le personnel sont séparés | M |
| 7 | **L7 — Réglages et modules : une seule porte de configuration** | ERG | Je configure une fois, au même endroit | M |
| 8 | **L8 — Accès et cycle de vie des comptes sans impasse** | BLQ/ERG | Retirer, rendre ou suspendre un accès sans écran cassé | S |
| 9 | **L9 — Cohérence transversale (noms, états, libellés, mobile)** | ERG | Mêmes mots et mêmes gestes partout | M |
| 10 | **L10 — Rationalisation technique prouvée** | TEC | (invisible) code plus simple, moins de risques | M |
| — | **Hors périmètre** | FUT | Établissements multi-SIRET, verticales futures, suivi temps réel, flotte, analytics | — |

**Ordre** :
1. **L0** est immédiat.
2. **L1** est le socle de L2, L3, L4 et L5.
3. **L8** est indépendant et peut avancer en parallèle de L2.
4. **L5** suit L1, car les blocs de l'accueil dépendent des permissions et non de l'archétype.
5. **L9** et **L10** se font en continu, puis en clôture.

---

## L0 — Correctifs de sécurité urgents (SÉC)

1. **Objectif métier** : confidentialité.
2. **Actuel** : agrégats d'entreprise pour un salarié (P0) ; lectures de modules sans droit ; annuaire complet du chauffeur ; `theme.manage` donné au chauffeur.
3. **Cible** : chaque widget et chaque endpoint de lecture de gestion exige une permission collective. L'annuaire est réduit selon D3.
4. **Pages à conserver** : toutes. 5–7 (fusionner, reconstruire, retirer) : aucune.
8. **Backend réutilisé** : `WidgetManifest::permissions()`, `EnsureCompanyAccess`, bundles des modules.
9. **Modifications** : gardes de 4 widgets, 3 routes, rôle par défaut `driver` des **nouvelles** sociétés ; projection d'annuaire (si D3).
10. **RBAC** : on restreint seulement ; rien n'est ouvert. 11. **Multi-tenant** : aucun impact.
12–13. **Compatibilité et migrations** : aucune.
14. **Tests** : matrice profil × widget × endpoint (salarié, chauffeur, manager, RH, propriétaire) ; test d'invariant « un widget `company` exige un droit collectif ».
15. **Recette** : clone isolé, mêmes sondes HTTP qu'ADR-815.
16. **Dépendances** : aucune. 17. **Complexité** : S.
18. **Validation** : le salarié reçoit 403 ou `not_found` sur les widgets collectifs ; `GET /modules/*/settings` → 403 pour le chauffeur.
19. **Rollback** : retour de la garde (une ligne par widget).

## L1 — Un seul modèle Personne / Poste / Rôle d'accès (BLQ)

1. **Objectif** : séparer le métier (poste) de l'accès (rôle).
2. **Actuel** :
   - le rôle porte l'arrivée, les widgets, les pièces, les champs et la conformité ;
   - le poste ne sert qu'à l'intégration ;
   - le poste est saisi deux fois sur la fiche (liste + « Intitulé de poste » libre) ;
   - le responsable n'est modifiable que dans un formulaire secondaire ou en lot, et un salarié **sorti** est proposé comme responsable [R parcours 24].
3. **Cible** :
   - le **Poste** porte les exigences d'intégration (`requirements` par poste) ;
   - le **Rôle d'accès** ne porte que des droits, avec un périmètre explicite (soi / équipe / entreprise) ;
   - le **responsable** est un champ unique de l'emploi.
4. **Conserver** : Organisation › Postes.
5. **Fusionner** : `/company/workforce/requirements` dans la fiche Poste.
6. **Reconstruire** : la fiche Poste (onglet Exigences).
7. **Retirer** : l'édition de `field_config` dans le tiroir des rôles.
8. **Backend réutilisé** : `requirements`, `EffectiveRequirementResolver::subjectForJobRole`, `RequirementConfigService`, `RequirementParityComparator`, `WorkforceAccess`.
9. **Modifications** :
   - `DocumentResolverService` lit les exigences du poste en plus des règles de la société ;
   - le rôle n'est plus qu'un repli de compatibilité ;
   - la liste des responsables exclut les salariés sortis ;
   - affichage du périmètre calculé dans l'éditeur de rôles.
10. **RBAC** : le poste ne donne aucun droit ; aucune règle de périmètre modifiée sans décision (D4).
11. **Multi-tenant** : les postes sont scopés à la société (déjà le cas).
12. **Compatibilité** : projection rôle → exigences **proposée et validée** ; colonnes du rôle figées en lecture seule.
13. **Migrations** : aucune de schéma.
14. **Tests** :
    - parité rôle/poste (rapport) ;
    - « aucune écriture lors d'une lecture » ;
    - poste sans exigence = règles de la société ;
    - salarié sans compte couvert.
15. **Recette** : attribuer « Permis » au poste Chauffeur-livreur → la fiche, Mon espace et la conformité l'affichent.
16. **Dépendances** : L0.
17. **Complexité** : L.
18. **Validation** : une seule page de configuration des exigences ; parité ≥ 100 % sur les sociétés de recette.
19. **Rollback** : drapeau d'autorité → retour au calcul par rôle.

## L2 — Embaucher, sur place ou à distance (BLQ)

1. **Objectif** : recruter sans jongler entre écrans.
2. **Actuel** (mesuré) :
   - « Ajouter une personne » : 3 à 6 clics, sans poste, contrat ni pièce [R parcours 4, 9] ;
   - contrat ensuite : 5 clics, autre onglet [R parcours 6] ;
   - poste ensuite : 7 clics, autre formulaire [R parcours 22] ;
   - « Inviter une recrue » : crée un **second** salarié, et sa date obligatoire n'est pas signalée (bouton désactivé sans explication) [R parcours 5] ;
   - aucune finalisation.
3. **Cible** : un assistant **Personne → Poste → Mode (sur place | lien) → Dossier → Contrat → Finaliser** (spécification dans `06`).
4. **Conserver** : le portail `/onboarding`, la revue du dossier (`_OnboardingReviewDialog`), le composant contrat.
5. **Fusionner** : `_PersonAddDrawer` + `_OnboardingInviteDialog` dans l'assistant.
6. **Reconstruire** : l'onglet « Embauche » de la fiche (checklist existante `file.onboarding`, complétée des pièces).
7. **Retirer** : le bouton « Inviter une recrue » isolé.
8. **Backend réutilisé** : `OnboardingInvitationService` (snapshot, révision, validation via `PersonFieldWriter`), `OnboardingPlanner`, portail, `MembershipInvitation`, contrats.
9. **Modifications** :
   - mode « sur place » : dossier sans envoi de lien ;
   - **Finaliser** en une transaction : activation du salarié préparatoire, contrat brouillon prérempli, accès facultatif, audit ;
   - finalité, base légale et texte d'information préremplis par la société.
10. **RBAC** : embaucher = `workforce.manage` ; rôle d'accès choisi à la finalisation, par `members.invite` (anti-escalade).
11. **Multi-tenant** : salarié préparatoire scopé (déjà le cas).
12. **Compatibilité** : aucun dossier existant.
13. **Migrations** : aucune.
14. **Tests** :
    - modes A et B → mêmes `field_values` et mêmes pièces ;
    - préparatoire exclu des populations opérationnelles jusqu'à la finalisation ;
    - date d'arrivée signalée obligatoire.
15. **Recette** : embaucher un chauffeur sur place en ≤ 3 écrans ; un autre à distance (lien, code, dépôt, validation).
16. **Dépendances** : L1.
17. **Complexité** : L.
18. **Validation** : 1 assistant au lieu de 4 écrans ; zéro double création.
19. **Rollback** : les anciens dialogues restent derrière une option tant que la recette n'est pas validée.

## L3 — Une seule chaîne documentaire (BLQ)

1. **Objectif** : obtenir et valider les pièces de tout le monde.
2. **Actuel** :
   - 6 chemins de création de demande, dont une écriture lors d'une lecture ;
   - deux écrans de validation (Documents et revue d'intégration) ;
   - conformité « par rôle » ;
   - « 67 manquants » contre « aucun document requis » [R].
3. **Cible** :
   - demandes créées à l'affectation d'un poste, à l'embauche, à une campagne ou à un renouvellement ;
   - file « À traiter » unique ;
   - conformité par poste, une seule fonction de calcul.
4. **Conserver** : Documents (onglets de travail), Mon espace › Mes documents.
5. **Fusionner** : dépôts d'intégration dans À traiter.
6. **Reconstruire** : widgets de conformité (même source que la page).
7. **Retirer** : la création implicite à la lecture.
8. **Backend réutilisé** : `DocumentRequestService`, `DocumentLifecycleOrchestrator`, `ReviewMemberDocumentUseCase`, `DocumentComplianceReadModel`.
9. **Modifications** : déclencheurs de création ; agrégation par poste ; suppression de l'écriture dans `MemberDocumentWorkflowReadModel`.
10. **RBAC** : inchangé (`documents.*`).
11. **Multi-tenant** : demandes scopées (déjà le cas).
12. **Compatibilité** : demandes existantes conservées, jamais régénérées.
13. **Migrations** : aucune.
14. **Tests** : invariant « aucune écriture sur un GET » ; salarié sans compte = même traitement ; même chiffre widget / page.
15. **Recette** : dépôt depuis le portail → visible dans À traiter → approuvé.
16. **Dépendances** : L1, L2.
17. **Complexité** : M.
18. **Validation** : un seul chiffre de conformité partout.
19. **Rollback** : déclencheurs désactivables.

## L4 — Fiche personne et Équipe refondues (ERG)

1. **Objectif** : comprendre et mettre à jour une personne sans chercher.
2. **Actuel** :
   - onglet « Informations & emploi » à **3 formulaires et 3 boutons d'enregistrement** [R parcours 22] ;
   - doublons prénom/nom, poste (liste et texte), date d'embauche, type de contrat, statut ;
   - Équipe : 3 onglets hétérogènes et 2 entrées de création [R] ;
   - la fiche appelle l'API Signatures alors que le module est désactivé (403) [R].
3. **Cible** :
   - onglets Profil (un formulaire) · Emploi & contrat · Documents · Temps & absences · Accès ;
   - Équipe = liste + Embaucher + actions en lot.
4. **Conserver** : la structure à onglets, l'en-tête.
5. **Fusionner** : les 3 formulaires.
6. **Reconstruire** : l'onglet Profil.
7. **Retirer** : les champs personnalisés redondants de la fiche (dépubliés, valeurs conservées) ; l'onglet Rôles d'Équipe (déplacé, voir L7).
8. **Backend réutilisé** : `PersonFieldWriter`, `EmployeeFieldStore`, `PersonController`.
9. **Modifications** : filtres poste, service et statut dans `people` ; pas d'appel aux modules inactifs.
10. **RBAC** : permissions par onglet (existantes).
11. **Multi-tenant** : aucun.
12. **Compatibilité** : valeurs des champs dépubliés conservées et proposées à la reprise.
13. **Migrations** : aucune.
14. **Tests** : un seul `PUT` par enregistrement ; aucune requête vers un module inactif.
15. **Recette** : modifier le poste et le responsable en ≤ 3 clics.
16. **Dépendances** : L1.
17. **Complexité** : M.
18. **Validation** : zéro champ en double dans la fiche.
19. **Rollback** : ancienne disposition derrière un drapeau d'affichage.

## L5 — Un seul accueil, des vues par profil (ERG)

1. **Objectif** : arriver sur ce qu'on a à faire.
2. **Actuel** : `/home` et `/dashboard` (même grille) ; arrivée par archétype ; écriture de disposition lors de l'ouverture (deux endroits) [P].
3. **Cible** : `/home` = Moi · À traiter · Entreprise · Démarrage.
5. **Fusionner** : `/dashboard` → alias.
6. **Reconstruire** : `home.vue`.
7. **Retirer** : `WorkspaceResolver` comme règle d'arrivée.
8. **Backend réutilisé** : surface-engine, `HomePrioritiesReadModel`, `/profile/space`.
9. **Modifications** : portée des widgets (`self`, `team`, `company`) avec l'utilisateur et le salarié dans le contexte ; plus d'écriture lors d'une lecture.
10. **RBAC** : bloc Entreprise = droit collectif.
11. **Multi-tenant** : salarié résolu dans la société courante.
12. **Compatibilité** : dispositions conservées.
13. **Migrations** : aucune.
14. **Tests** : matrice profil × bloc ; aucun `PUT` lors de l'affichage.
15. **Recette** : 9 profils.
16. **Dépendances** : L0, L1.
17. **Complexité** : M.
18. **Validation** : un accueil par utilisateur ; aucune donnée collective sans droit.
19. **Rollback** : l'alias `/dashboard` peut reprendre l'ancienne page.

## L6 — Travail : gestion d'un côté, personnel de l'autre (ERG)

1. **Objectif** : séparer « gérer l'équipe » et « mon activité ».
2. **Actuel** :
   - le hub « Planning & temps » contient l'onglet personnel « Mon activité » ;
   - l'écran de gestion du temps est servi au salarié ;
   - Absences = demandeur + valideur + types ;
   - actions en icônes sans libellé ; barre d'outils du planning surchargée [R].
3. **Cible** :
   - **déblocages (BLQ)** :
     - clôturer et corriger une entrée de pointage restée « en travail » (oubli de sortie), avec motif, au lieu du refus anglais brut [R parcours 14] ;
     - ouvrir les soldes de congés des contrats déjà actifs quand le module Congés est activé (ou message exact) [R parcours 15] ;
   - Planning, Pointages & feuilles et Absences : gestion uniquement, avec des actions libellées ;
   - Mon activité dans le menu personnel ;
   - les types d'absence passent dans Réglages.
4. **Conserver** : moteurs et vues du planning.
5. **Fusionner** : —.
6. **Reconstruire** : les en-têtes et barres d'outils.
7. **Retirer** : l'onglet « Mon activité » du hub.
8. **Backend** : aucun nouveau.
9. **Modifications** :
   - gardes de page (temps : `time_manage`, `timesheet_approve` ou `timesheet_approve_team`) ;
   - transition « en travail → corrigée » dans le cas d'usage de correction existant ;
   - ouverture des soldes à l'activation du module (service d'acquisition existant) ;
   - **aucun changement des règles de calcul de paie**.
10. **RBAC** : on restreint l'écran de gestion.
11. **Multi-tenant** : aucun.
12–13. **Compatibilité et migrations** : aucune.
14. **Tests** :
    - salarié → 403 sur l'écran de gestion, 200 sur Mon activité ;
    - oubli de sortie corrigeable ;
    - activation du module Congés après un contrat actif → solde présent.
15. **Recette** : planifier → publier → pointer → corriger → demander → valider.
16. **Dépendances** : L5.
17. **Complexité** : M.
18. **Validation** : aucun écran de gestion servi à un profil personnel.
19. **Rollback** : gardes réversibles.

## L7 — Réglages et modules : une seule porte de configuration (ERG)

1. **Objectif** : configurer une fois, au même endroit.
2. **Actuel** :
   - 3 assistants qui se recoupent (assistant RH, assistant de module, Règles RH) ;
   - Rôles dans Équipe ;
   - types de pièces dans Documents ;
   - types d'absence dans Absences ;
   - « Assistant terminé » mais convention « non confirmée » [R] ;
   - modules non livrés proposés à la découverte [R].
3. **Cible** :
   - Réglages = Entreprise · Accès · Règles RH · Documents · Modules · Abonnement · Notifications · Automatisations · Audit ;
   - un seul assistant par module.
4. **Conserver** : le hub Réglages, l'assistant de module, Paramètres RH.
5. **Fusionner** : l'assistant RH dans l'assistant du module.
6. **Reconstruire** : —.
7. **Retirer** : les modules non livrés du catalogue.
8. **Backend réutilisé** : `ModuleSetupService`, `WorkforceOnboardingService` (ses étapes deviennent celles de l'assistant de module).
9. **Modifications** : routes Réglages (alias pour l'existant).
10. **RBAC** : inchangé.
11. **Multi-tenant** : aucun.
12. **Compatibilité** : `workforce_onboardings` conservé en lecture.
13. **Migrations** : aucune.
14. **Tests** : « pas de nouveau 403 » ; liens profonds historiques.
15. **Recette** : trouver chaque réglage depuis la recherche du hub.
16. **Dépendances** : L1.
17. **Complexité** : M.
18. **Validation** : chaque règle n'a qu'un écran de configuration.
19. **Rollback** : alias.

## L8 — Accès et cycle de vie des comptes sans impasse (BLQ/ERG)

1. **Objectif** : retirer, rendre ou suspendre un accès sans laisser quelqu'un bloqué.
2. **Actuel** :
   - après un retrait d'accès puis une nouvelle invitation, la personne peut se connecter mais reçoit des **400** (`/api/nav`, `/api/modules`) et des écrans vides, sans être dirigée vers l'invitation à accepter [R parcours 26b/26c] ;
   - après la définition du mot de passe, retour à la page de connexion [R parcours 9].
3. **Cible** : à la connexion, une invitation en attente est proposée en premier ; sans société active, un écran explicatif s'affiche.
8. **Backend réutilisé** : `memberships.acceptance_pending_at`, `/invitations`.
9. **Modifications** : garde de démarrage côté interface (lecture de l'état d'appartenance) ; décision D14 pour la connexion automatique.
14. **Tests** : retrait → nouvelle invitation → connexion → invitation proposée → acceptation → accès.
16. **Dépendances** : aucune.
17. **Complexité** : S.
18. **Validation** : aucun 400 visible pour une appartenance en attente.
19. **Rollback** : garde d'interface seule.

## L9 — Cohérence transversale (ERG)

- **Noms** :
  - un nom par module : la carte et « Requis par » diffèrent aujourd'hui pour 5 modules [R] ;
  - glossaire de `05` §6 ;
  - URL `/organization/roles` → `positions` (alias).
- **États** :
  - libellés communs ;
  - `canceled` contre `cancelled` (traduction unique, codes inchangés).
- **Actions** : libellées (plus d'icônes seules pour Approuver / Refuser) ; « champ obligatoire » toujours signalé.
- **Messages** :
  - messages métier traduits ;
  - pluriels (« 5 salarié inclus ») ;
  - message hors contexte sur le profil de l'entreprise [R parcours 2] ;
  - « Aucune proposition disponible » sur une société Logistique neuve [R].
- **Mobile** : patterns de `05` §5 (aucun débordement constaté ; à préserver).
- **Tests** : clés i18n sans brut (test existant étendu) ; un nom de module par clé.
- **Complexité** : M. **Dépendances** : en continu.

## L10 — Rationalisation technique prouvée (TEC)

- **Pages résiduelles** : code mort des pages de redirection (`members/[id].vue` 461 lignes, `employees/[id].vue` 429) ; `dev-lab` et `ui-lab` retirés de la production.
- **Découpage sans changement fonctionnel** : `planning/index.vue` (7 061 lignes ; 8 565 avec composants), `documents/[tab]` (8 367 avec composants), `modules/index.vue` (2 167).
- **Données de compatibilité** : unification `companies.jobdomain_key` / `company_jobdomain` ; gel de `doc_config`, `field_config` et `required_tags` après la parité de L1.
- **Après chaque étape** : suite complète, build, « pas de nouveau 403 ».

## Hors périmètre (FUT)

- Établissements juridiques multi-SIRET.
- Équipes distinctes des services.
- Échanges de créneaux entre salariés.
- Heures supplémentaires calculées (audit séparé existant).
- Modules `logistics_tracking`, `logistics_fleet` et `logistics_analytics` (non livrés).
- Deuxième verticale (ADR-564).

Ces sujets sont mentionnés pour mémoire, et ne seront traités ni conçus dans ce plan.
