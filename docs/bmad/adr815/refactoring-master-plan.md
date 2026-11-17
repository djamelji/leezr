# ADR-815 — Plan directeur de refonte de l'espace Company

> **Soumis à validation produit. Aucun lot n'est engagé.**
>
> Les lots correspondent à des **résultats métier complets**, et non à des modules techniques. Chaque lot réutilise l'existant ; aucun nouveau moteur n'est prévu. La complexité est relative (S < M < L < XL).

## Ordre retenu et justification

```
R1 (P0)  Confidentialité & contexte de l'accueil ──┐
R2 (P1)  Le poste porte les exigences ─────────────┼─▶ R3 (P1) Embaucher : un seul parcours (sur place / à distance)
                                                   │            └─▶ R4 (P1) Une seule file documentaire « À traiter »
R5 (P2)  Un seul accueil, des vues adaptées ◀──────┘
R6 (P2)  Équipe & navigation lisibles
R7 (P3)  Autres parcours (temps, congés, paie, expéditions, réglages)
R8 (P4)  Nettoyage technique prouvé
```

**Pourquoi l'accueil vient en premier, mais seulement pour sa partie P0.** L'audit montre que la confusion « Alice » vient d'un **compte personnel utilisé comme compte d'administration**, pas d'une faille. En revanche, l'accueil expose des **agrégats de l'entreprise** à des profils personnels (C2, R0) : c'est un P0 de confidentialité, d'où R1. La refonte de l'accueil (R5) vient ensuite, parce qu'elle dépend du modèle poste/rôle (R2). Sans lui, les blocs de l'accueil continueraient de se baser sur l'archétype RBAC.

L'embauche (R2 → R3 → R4) est le **parcours métier prioritaire**. Ses causes racines sont prouvées : deux créations de salarié, deux sources d'exigences (rôle RBAC et poste), six chemins de création de demandes.

---

## R1 — P0 : confidentialité de l'accueil et lectures sans droit

| Rubrique | Contenu |
|---|---|
| 1. Problème | C2/R0 : agrégats d'entreprise servis sous des droits personnels. R2 : réglages, devis et impact de désactivation des modules lisibles par tout membre. R1 : annuaire complet pour le chauffeur (e-mail de connexion, rôle, indicateur administrateur). |
| 2. Résultat utilisateur | Un salarié ne voit que ses propres chiffres ; un chauffeur ne voit que ce que son poste nécessite. |
| 3. Pages | `/home`, `/dashboard`, Équipe (annuaire), Modules |
| 4. Réutiliser | `DashboardCatalogService`, `WidgetManifest::permissions()`, `EnsureCompanyAccess`, `PersonController` (présentation), bundles de permissions des modules |
| 5. Composants | Aucun déplacement |
| 6. Backend indispensable | (a) Les widgets `workforce.clock_live`, `anomalies`, `hours_trend` exigent une permission de lecture **collective** existante (`workforce.view` ou `time_manage`) au lieu de `timesheet_view` ; `leave_pending` exige `leave_view_all` ou `leave_approve`. (b) `modules/{key}/settings`, `quote` et `deactivation-preview` exigent `modules.manage`. (c) Un annuaire réduit pour `members.view` sans `members.manage` : nom et avatar uniquement, à arbitrer (décision D3). |
| 7. Migration / compatibilité | Aucune donnée. Effet : des profils perdent des widgets ou des lectures qu'ils ne devaient pas avoir. **Risque** : un rôle de recette qui s'appuyait dessus. |
| 8. RBAC / confidentialité | N'**ouvre** rien ; ne fait que restreindre. Le propriétaire est inchangé. |
| 9. Tests | Pour chaque widget : catalogue + `batchResolve` par profil (salarié, chauffeur, manager, RH, propriétaire). Routes modules : 403 pour les non-gestionnaires. Annuaire : champs servis selon les droits. |
| 10. Critère navigateur | Sur le clone, le salarié n'a plus de « Vue d'ensemble » d'entreprise ; `GET /api/modules/workforce/settings` → 403 pour le chauffeur. |
| 11. Dépendances | Aucune |
| 12. Complexité | S |
| 13. Gain UX | Confiance ; pas d'information parasite pour les profils terrain |

## R2 — P1 : le poste porte les exigences (une seule source de vérité)

| Rubrique | Contenu |
|---|---|
| 1. Problème | Pièces de la fiche = rôle RBAC (`DocumentResolverService`) ; intégration = poste (`EffectiveRequirementResolver`). Champs requis = société + rôle + poste. 40 rôles portent `doc_config` et `field_config`. Aucune exigence propre aux sociétés. |
| 2. Résultat utilisateur | « Je configure **une fois** ce qu'un Chauffeur-livreur doit fournir, dans Organisation → Postes ; cela s'applique à l'embauche **et** au suivi documentaire. » |
| 3. Pages | Organisation → Postes (fiche poste, nouvel onglet « Exigences » : réutilisation du contenu de `/company/workforce/requirements`) ; fiche personne → Documents |
| 4. Réutiliser | Table `requirements` (`subject_scope`, `stage`, `level`, `source`, `derived_from_id`) ; `EffectiveRequirementResolver::subjectForJobRole` ; `RequirementConfigService` ; `RequirementParityComparator` ; preset `logistique_requirements` ; `workforce_job_roles` |
| 5. Composants | Le contenu de `pages/company/workforce/requirements/index.vue` va dans la fiche poste ; la page devient un alias |
| 6. Backend indispensable | `DocumentResolverService::requirementMap` reçoit le **poste** de la personne et lit les exigences du poste (via `EffectiveRequirementResolver`) **en plus** des règles de la société. Le rôle RBAC n'est plus lu qu'en repli de compatibilité tant que la parité n'est pas atteinte. La bascule est mesurée par `RequirementParityComparator`. |
| 7. Migration / compatibilité | Proposition de projection rôle → exigences de poste, **validée par la société** ; affectation suggérée des postes (voir `data-compatibility-matrix.md`). Demandes existantes intactes. |
| 8. RBAC / confidentialité | Configurer les exigences d'un poste : `settings.manage` ou `documents.configure`. Le poste ne donne **aucun** droit. |
| 9. Tests | Parité rôle/poste sur les sociétés 1 et 101 (rapport) ; exigence modifiée = ni régénération ni suppression ; personne sans poste = règles société seules ; personne sans compte couverte. |
| 10. Critère navigateur | Ajouter « Permis » au poste Chauffeur-livreur → la fiche d'un chauffeur affiche la pièce requise, et un nouveau dossier d'intégration pour ce poste la propose. |
| 11. Dépendances | Après R1 (indépendant techniquement) |
| 12. Complexité | L |
| 13. Gain UX | Plus qu'un seul endroit à configurer (aujourd'hui 4 pour les champs, 5 pour les pièces) |

## R3 — P1 : « Embaucher », un seul parcours, sur place ou à distance

| Rubrique | Contenu |
|---|---|
| 1. Problème | Deux créations de salarié (« Ajouter une personne » actif, et dossier d'intégration préparatoire). Contrat et accès hors parcours. L'intégration demande finalité, base légale et texte d'information à chaque dossier. Pas d'option « sur place ». Pas de finalisation. |
| 2. Résultat utilisateur | Équipe → **Embaucher** : personne → poste → « compléter maintenant » **ou** « envoyer un lien » → suivi → contrat → finaliser (activer, ouvrir un accès si besoin). |
| 3. Pages | Équipe (`_PersonAddDrawer`, `_OnboardingInviteDialog`, `_OnboardingPanel`, `_OnboardingReviewDialog`), fiche personne (onglet « Embauche »), portail `/onboarding` |
| 4. Réutiliser | `OnboardingInvitationService` (snapshot, révision, validation par `PersonFieldWriter`), `OnboardingPlanner`, portail et OTP, contrats (`workforce_employment_contracts`, génération de modèle), `MembershipInvitation` |
| 5. Composants | Fusion de l'ajout et de l'invitation d'intégration dans un assistant unique ; « Ajouter une personne » reste en action secondaire (personne déjà en poste) |
| 6. Backend indispensable | (a) **Mode « sur place »** : le dossier existe sans lien envoyé ; la société saisit les valeurs dans le circuit « en attente → validé ». (b) **Finaliser** : activation du salarié préparatoire, création du contrat brouillon prérempli, accès facultatif, en une transaction et avec audit. (c) Finalité, base légale et texte d'information **préremplis** par réglage société, modifiables. |
| 7. Migration / compatibilité | Aucun dossier existant (0). Les salariés existants ne sont pas concernés. |
| 8. RBAC / confidentialité | Embaucher : `workforce.manage` ; voir les champs sensibles : `workforce.sensitive_read` ; le rôle d'accès n'est choisi qu'à la finalisation, par quelqu'un qui a `members.invite`. |
| 9. Tests | A et B produisent les mêmes `field_values` et `member_documents` ; aucune donnée en double ; le salarié reste exclu des populations opérationnelles jusqu'à la finalisation ; revalidation de l'anti-escalade. |
| 10. Critère navigateur | Embaucher un chauffeur sur place en moins de 3 écrans, et un autre à distance (lien, dépôt, validation) ; les deux fiches sont identiques en structure. |
| 11. Dépendances | R2 |
| 12. Complexité | L |
| 13. Gain UX | Aujourd'hui, 2 parcours disjoints, au moins 4 écrans et des choix juridiques à chaque dossier ; demain, 1 assistant guidé |

## R4 — P1 : une seule file documentaire

| Rubrique | Contenu |
|---|---|
| 1. Problème | 6 chemins de création de demande, dont une **écriture lors d'une lecture** (`MemberDocumentWorkflowReadModel.php:90`, comptes seulement) ; 2 écrans de validation (Documents et intégration) ; conformité « par rôle ». |
| 2. Résultat utilisateur | Toute pièce attendue apparaît au même endroit, pour toute personne, avec ou sans compte. |
| 3. Pages | Documents → À traiter et Demandes, fiche → Documents, Mon espace, widgets de conformité |
| 4. Réutiliser | `DocumentRequestService`, `DocumentLifecycleOrchestrator`, `ReviewMemberDocumentUseCase`, `DocumentComplianceReadModel` |
| 5. Composants | Les dépôts d'intégration sont intégrés à la file « À traiter » |
| 6. Backend indispensable | Création des demandes à l'affectation de poste ou à l'ouverture d'embauche (et non plus à la lecture) ; conformité agrégée par **poste** ; demandes en lot par poste |
| 7. Migration / compatibilité | Demandes existantes conservées ; aucune régénération |
| 8. RBAC | Inchangé (`documents.*`) |
| 9. Tests | Plus aucune écriture sur un GET (test d'invariant) ; personne sans compte = même traitement |
| 10. Critère navigateur | Un dépôt du portail apparaît dans Documents → À traiter |
| 11. Dépendances | R2, R3 |
| 12. Complexité | M |
| 13. Gain UX | Un seul endroit pour valider |

## R5 — P2 : un seul accueil, des vues adaptées

| Rubrique | Contenu |
|---|---|
| 1. Problème | `/home` et `/dashboard` partagent la même grille ; atterrissage par archétype RBAC ; `/home` écrit la disposition ; le contexte d'un widget ignore l'utilisateur. |
| 2. Résultat | `/home` : Moi · À traiter · Entreprise selon les droits ; tout le monde y arrive. |
| 3. Pages | `home.vue`, `dashboard.vue`, `home.store.js`, `dashboard.store.js` |
| 4. Réutiliser | surface-engine, `HomePrioritiesReadModel`, `/profile/space`, dispositions existantes |
| 5. Composants | Fusion des deux pages ; `/dashboard` devient un alias |
| 6. Backend | Portée de widget `self`, `team` ou `company`, avec l'utilisateur et le salarié dans le contexte ; plus d'enregistrement pendant une consultation ; `WorkspaceResolver` retiré de l'atterrissage |
| 7. Compatibilité | Dispositions conservées (bloc Entreprise) |
| 8. RBAC | Bloc Entreprise = lecture collective explicite |
| 9. Tests | Matrice profil × bloc ; aucun PUT lors d'un GET de page |
| 10. Critère | Le propriétaire n'a qu'un accueil ; le salarié n'a plus d'indicateurs d'entreprise |
| 11. Dépendances | R1, R2 |
| 12. Complexité | M |
| 13. Gain | Fin de la confusion entre « accueil personnel » et « tableau de bord » |

## R6 — P2 : Équipe et navigation lisibles

- **Problème** :
  - trois sens du mot « onboarding » ;
  - « Accueil » qui mène, selon le profil, à deux écrans différents ;
  - menu « Suivi du temps » de gestion ouvert au salarié (R5) ;
  - pages résiduelles (`members/:id`, `employees/:id`) ;
  - réglages dispersés : rôles, champs (Équipe), pièces (Documents), exigences (Workforce).
- **Résultat** :
  - arborescence de `company-target-information-architecture.md` ;
  - vocabulaire « Embauche » (dossier), « Démarrage » (checklist société), « Configuration du module » (assistant).
- **Réutiliser** : manifestes de navigation, `NavBuilder`, hub Réglages.
- **Tests** : `NavEndpointTest` par profil ; « pas de nouveau 403 » (invariant M7).
- **Complexité** : M.
- **Dépendances** : R3, R5.

## R7 — P3 : autres parcours

Les sujets ci-dessous ont été relevés pendant la recette (voir `company-pages-inventory.md`). Chacun forme un petit lot autonome, à arbitrer :
- Temps : écran de gestion vs écran salarié.
- Congés : écran unique de 1 577 lignes.
- Planning : écran de 7 061 lignes.
- Paie, Expéditions.
- Réglages : `/company/settings` sans garde de routeur.
- Finance, Notifications.

## R8 — P4 : nettoyage technique prouvé (après validation fonctionnelle)

- Retirer le code mort des pages de redirection (`members/[id].vue`, 461 lignes ; `workforce/employees/[id].vue`, 429 lignes).
- Retirer `dev-lab` et `ui-lab` de la build de production.
- Unifier `companies.jobdomain_key` et `company_jobdomain`.
- Geler `company_roles.doc_config`, `field_config` et `required_tags` (lecture seule) après la parité R2.
- Retirer les presets `e2e_preset_*` du catalogue Platform (par identifiants exacts, après décision).

## Décisions produit attendues avant R1

| # | Décision | Recommandation |
|---|---|---|
| D1 | Un salarié voit-il des chiffres collectifs ? | Non |
| D2 | `/dashboard` est-il fusionné dans `/home` ? | Oui |
| D3 | Contenu de l'annuaire pour `members.view` sans gestion | Nom, photo, poste ; pas d'e-mail de connexion ni d'état de compte |
| D4 | Le chauffeur garde-t-il `members.view` et `theme.manage` par défaut ? | Non pour `theme.manage` ; `members.view` réduit selon D3 |
| D5 | Un poste est-il obligatoire pour chaque salarié ? | Oui pour les **nouvelles** embauches ; facultatif pour l'existant |
| D6 | Projection rôle → exigences de poste | Proposée par le système, validée par la société |
| D7 | Finalité et base légale de l'intégration | Préréglées par la société, modifiables par dossier |
| D8 | Identifiants de démonstration nominatifs | Oui (`alice.martin@…`) |
