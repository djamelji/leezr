# ADR-815 — Audit directeur de l'espace Company

- **Date** : 2026-11-17. Base DEV `70ec3dbe`. **Mode audit strict : aucune implémentation.**
- **Méthode** :
  - lecture du code (routes front et API, gardes, manifestes, moteurs) ;
  - lecture seule de la base DEV ;
  - recette navigateur et sondes HTTP sur un **clone isolé** de la base (`leezr_audit815`, `http://127.0.0.1:8815`). Les connexions, sessions et journaux de la recette n'ont touché que le clone. La base DEV n'a reçu aucune écriture de l'audit.
- **Profils** (société 1) :
  - Propriétaire ;
  - Alice (administratrice non propriétaire, « Dispatcher ») ;
  - Responsable exploitation ;
  - Manager ;
  - RH ;
  - Salarié ;
  - Chauffeur ;
  - variantes mobiles (propriétaire en sombre, chauffeur).
- **Parcours public** : le portail recrue est audité par le code ; aucun dossier n'existe.

## Livrables

| Document | Contenu |
|---|---|
| `adr815/company-pages-inventory.md` | Matrice des 69 pages routées (50 sous `/company`, plus `/home`, `/dashboard` et les pages publiques) : objectif, public, module, droits, API, doublons, problèmes, décision, risque, priorité |
| `adr815/company-routes-permissions.md` | Chaîne d'autorisation, constats R0–R9, permissions et navigation par profil, **annexe : 473 routes API et leurs gardes** |
| `adr815/dashboard-context-audit.md` | Pourquoi « Alice » sur `/home` et `/dashboard` : cause prouvée, mécanique, constats C1–C7, cible |
| `adr815/people-jobrole-rbac-model.md` | Personne, salarié, poste, rôle : modèle réel, 8 invariants, confusions, modèle cible sans nouvelle table |
| `adr815/onboarding-requirements-documents-audit.md` | Tous les moteurs d'embauche et de documents, source de vérité, 15 réponses, parcours cible |
| `adr815/company-target-information-architecture.md` | Arborescence cible, sections, écrans supprimés, articulations |
| `adr815/refactoring-master-plan.md` | Lots R1–R8 (P0 → P4), 13 rubriques chacun, décisions D1–D8 |
| `adr815/data-compatibility-matrix.md` | Données des sociétés 1 et 101, du métier et des catalogues ; ce qu'il faut conserver, projeter ou décider |
| `adr815/evidence/`, `adr815/shots/` | Réponses HTTP, contextes de tableau de bord, navigation par profil, captures |

## Réponses au critère final

**1. Pourquoi l'espace Company est incohérent.**
Chaque concept a été construit dans son module, sans **modèle commun personne / poste / rôle** :
- **Le rôle RBAC décide de presque tout.** Il porte les droits, mais aussi l'atterrissage (archétype), les widgets, les pièces obligatoires (`doc_config`, `required_tags`), les champs (`field_config`, `visibility_roles`) et la conformité « par rôle ».
- **Le poste existe**, mais il ne pilote que le dossier d'intégration, dont 0 est ouvert.
- **Les exigences ont trois autorités** : la société, le rôle RBAC et le poste. La page des exigences se déclare elle-même « sans effet ».
- **« Onboarding » désigne quatre choses** : la checklist société, l'assistant RH, le dossier recrue, et la checklist « Intégration du collaborateur » de la fiche.
- **L'accueil existe deux fois.**

**2. Pourquoi `/home` et `/dashboard` affichent Alice.**
Le navigateur est connecté avec `admin@leezr.test`, **qui est Alice Martin**. La preuve tient en deux éléments : la dernière session active est celle de l'utilisateur 2, et sa disposition a été modifiée aujourd'hui à 01:07. Les cinq autres profils testés ne voient aucune donnée d'Alice.

La confusion est **aggravée** par la conception :
- `/home` et `/dashboard` partagent la même grille de widgets ;
- l'accueil « personnel » montre les indicateurs de l'entreprise.

L'audit a aussi révélé un **vrai P0 distinct** : des **agrégats de toute l'entreprise** sont servis à un salarié, sous des droits « own ».

**3. Comment simplifier radicalement l'embauche.**
Un seul assistant « Embaucher » (personne → poste → sur place ou lien → suivi → contrat → finaliser), sur le moteur d'intégration **existant**, qui écrit déjà par l'écrivain canonique de la fiche. Il remplace les deux créations actuelles (salarié actif / salarié préparatoire) et sort le contrat et l'accès de leur isolement. Voir le lot R3.

**4. Où configurer une seule fois les exigences d'un poste.**
**Organisation → Postes → [poste] → Exigences**, sur la table `requirements` existante (`subject_scope = job_role`). L'autorité de la fiche personne bascule du rôle RBAC vers le poste, mesurée par `RequirementParityComparator`. Voir le lot R2.

**5. Onboarding sur place ou à distance.**
Deux modes du **même dossier** :
- **B (à distance)** existe déjà : portail, OTP, quarantaine, consentement.
- **A (sur place)** demande seulement de pouvoir ouvrir le dossier sans envoyer de lien ; la société saisit dans le même circuit « en attente → validé ».

**6. Comment réunifier les documents.**
- **Une seule règle de création** : à l'affectation d'un poste ou à l'ouverture d'une embauche. Elle remplace les 6 chemins actuels, dont **une écriture pendant une lecture**.
- **Une seule file « À traiter »**, qui inclut les dépôts d'intégration.
- **Une conformité par poste.**

Voir le lot R4.

**7. Pages à conserver ou à reconstruire.**
- **Conservées** : les outils spécialisés (Planning, Pointage, Congés, Paie, Expéditions, Documents, Finance, Modules, Rôles).
- **Reconstruites** : Accueil (fusion), Équipe (assistant Embaucher, onglet Embauche), fiche Poste (exigences).
- **Retirées ou réduites à des alias** : `/dashboard`, les quatre anciennes routes de personnes, `/company/workforce/requirements`, l'assistant RH « onboarding », les laboratoires.

Voir `company-pages-inventory.md`.

**8. Architecture cible.**
Accueil · Équipe · Organisation · Documents · Planning & temps · Paie · Expéditions · Finance · Réglages, plus un menu personnel. Le tout est **construit par les manifestes**, et chaque bloc est gardé par une permission. Voir `company-target-information-architecture.md`.

**9. Ordre d'exécution.**
1. **R1 (P0)** : confidentialité des agrégats et des lectures sans droit.
2. **R2 → R3 → R4 (P1)** : poste = exigences ; embauche ; une seule file documentaire.
3. **R5 → R6 (P2)** : accueil unique ; Équipe et navigation.
4. **R7 (P3)** : autres parcours.
5. **R8 (P4)** : nettoyage technique prouvé.

**10. Ce qu'il faut préserver.**
- **Données** : toutes les demandes et pièces existantes, sans régénération ; les rôles et leurs permissions ; les dispositions ; les salariés sans compte ; les colonnes `doc_config`, `field_config` et `required_tags`, figées en lecture seule et jamais supprimées avant la parité.
- **Navigation** : les alias de routes historiques ; l'invariant « pas de nouveau 403 ».
- **Sécurité** : l'anti-escalade d'attribution de rôle.

Voir `data-compatibility-matrix.md`.

## Constats confirmés, par gravité

| Gravité | Constats |
|---|---|
| **P0** | **C2/R0** : agrégats d'entreprise servis sous des droits personnels (salarié). |
| **P1** | **R1** : annuaire complet (e-mail de connexion, rôle, indicateur administrateur) pour le chauffeur. **R10** : les rôles RH, Responsable exploitation et Manager (`workforce.view` sans `workforce.manage`) ne peuvent ouvrir **aucune** fiche de collègue (404), alors que le chauffeur lit l'annuaire. **C3** : un salarié arrive sur le tableau de bord de gestion. Deux sources d'exigences (rôle / poste). Deux créations de salarié. Demande créée lors d'une lecture. |
| **P2** | **C1** : grille dupliquée sur les deux accueils. **C4** : `/home` écrit la disposition. **C5** : le contexte d'un widget ignore l'utilisateur. **R2** : réglages, devis et impact de désactivation des modules lisibles par tous. **R4** : `theme.manage` donné au chauffeur. **R5** : écran de gestion du temps ouvert au salarié. Page des exigences sans effet. Quatre sens du mot « onboarding ». |
| **P3** | **C6** : incohérence « 1 document à régulariser » contre « aucun document requis » (cause non établie). **R3** : configuration lisible par tous. **R6** : `/company/settings` sans garde de routeur. **R7** : laboratoires livrés. Onglet « Postes » nommé `roles` dans l'URL. Presets `e2e_*` dans le catalogue Platform. |

## Recette navigateur (résumé)

- **Couverture** : 9 profils × 41 URL directes, plus toutes les entrées de menu de chaque profil (`evidence/crawl/`).
- **Mobile** (propriétaire en sombre, chauffeur) : aucun débordement horizontal.
- **Erreurs** : aucune erreur console applicative. Seules les réponses 404 attendues apparaissent (fiche hors périmètre, ancienne URL de membre).
- **Accès refusés** : toutes les URL interdites mènent à la page « Accès refusé ».

## Hypothèses non prouvées

- **C6** : cause exacte de « Aucun document requis configuré » dans les widgets de conformité, alors que des pièces sont requises.
- Parcours public de la recrue : non rejoué au navigateur (aucun dossier ; en ouvrir un aurait exigé une écriture, même sur le clone). Il est couvert par le code et par les recettes ADR-796 et 796.1.
- Le nombre de clics des parcours cibles est une **estimation** de conception, pas une mesure.

## Décisions produit attendues

D1 à D8 : voir `refactoring-master-plan.md`, fin du document.
