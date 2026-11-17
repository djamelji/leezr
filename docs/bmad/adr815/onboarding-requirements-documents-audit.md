# ADR-815 — Embauche, intégration, exigences et documents

> Audit en lecture seule. Code : `70ec3dbe`. Données : base DEV au 2026-11-17.
>
> Les extraits cités sont des chemins `fichier:ligne`.

## 1. Les moteurs et parcours qui existent aujourd'hui

| Moteur / parcours | Rôle réel | Donnée possédée | Moment | Interfaces | Déclencheur | Doublons / concurrences | Usage réel (DEV) |
|---|---|---|---|---|---|---|---|
| **Ajouter une personne** (`_PersonAddDrawer.vue`) | Créer une personne avec ou sans accès | `workforce_employees` (actif), `memberships` et invitation si accès | Avant ou à l'embauche | Équipe → « Ajouter » | Manuel | 2ᵉ création de salarié via le dossier d'intégration | Usage courant |
| **Invitation d'un membre** (`MembershipInvitation`) | Donner un accès au logiciel | `memberships`, jeton du broker | Quand on choisit « Oui, inviter » | Ajout, fiche → Accès | Manuel | Aucune | 26 tâches d'invitation en file (ADR-814) |
| **Dossier d'intégration** (`OnboardingInvitationService`, `OnboardingPlanner`, portail `/onboarding`) | Faire compléter champs et pièces **à distance** par une recrue sans compte | `onboarding_invitations` (snapshot versionné des éléments), `onboarding_values` (valeurs en attente), `onboarding_uploads` (quarantaine, puis DMS) ; il **crée un salarié préparatoire inactif** (`:375`) | Avant la prise de poste | Équipe → onglet Intégration (`_OnboardingPanel`, `_OnboardingInviteDialog`, `_OnboardingReviewDialog`) | Manuel | Crée un 2ᵉ type de salarié (préparatoire) | **0 dossier** en base (fixtures supprimées) |
| **Assistant RH « onboarding »** (`/company/workforce/onboarding`, `workforce_onboardings`) | **Configurer le module RH de la société** : organisation, temps de travail, convention | `workforce_onboardings` | Activation du module | Page dédiée | Manuel | **Homonyme** du dossier d'intégration | Société 1 terminée ; société 101 n'en a pas (assistant de module ADR-803 à la place) |
| **Démarrage société** (`/dashboard/onboarding`) | Checklist de démarrage du propriétaire | État calculé | Après l'inscription | Accueil | Automatique | 3ᵉ sens du mot « onboarding » | — |
| **Catalogue des champs** (`field_definitions`, `FieldDefinitionCatalog`) | Définir les champs (identité, emploi, entreprise) | 46 définitions, toutes système | Configuration Platform | Platform → Catalogue | — | — | — |
| **Activation des champs** (`field_activations`) | Champs activés et requis **pour toute la société** | `enabled`, `required_override` | Configuration société | Équipe → réglages des champs (`_ProfileFieldsSettings`) | Copie à la création | Concurrence : `company_roles.field_config` (par rôle RBAC), `requirements` (par poste) | Société 1 : 45 activés, 1 requis |
| **Champs par rôle** (`company_roles.field_config`, `field_definitions.visibility_roles`) | Affichage et obligation des champs **selon le rôle RBAC** | JSON sur le rôle | Configuration des rôles | Rôles → tiroir d'un rôle | Copie du métier | Concurrence avec l'activation et les exigences | 40 rôles sur 49 |
| **Catalogue des types de documents** (`document_types`, `DocumentTypeCatalog`) | Définir les types de pièces | 14 types système ; types personnalisés par société possibles | Platform et société | Documents → Paramètres (`_DocumentsSettings`, `CompanyDocumentActivationCatalog`) | — | — | 0 type personnalisé |
| **Activation des documents** (`document_type_activations`) | Pièces actives et requises **pour toute la société** | `enabled`, `required_override` | Configuration | Documents → Paramètres | Copie à la création | Concurrence : `doc_config` et `required_tags` du rôle, `requirements` | Société 1 : 8 actives, 3 requises |
| **Pièces par rôle** (`company_roles.doc_config`, `required_tags` + archétype) | Pièces visibles, requises ou obligatoires **selon le rôle RBAC** | JSON sur le rôle | Création de la société | **Aucune interface d'édition côté société** (seulement par le métier) | Copie du métier | Concurrence directe avec le poste | 40 rôles sur 49 |
| **`DocumentResolverService::requirementMap`** | **Autorité actuelle** des pièces requises d'une personne | Calcul | À chaque lecture | Fiche, Mon espace, conformité | — | Clé = rôle RBAC (`:32`, `:59`, `:67`) | — |
| **Exigences (Requirements L1–L2)** (`requirements`, `EffectiveRequirementResolver`, `CompanyRequirementResolver`, `RequirementConfigService`) | Exigences par **poste**, avec étape (embauche, intégration), niveau et base légale | 48 lignes, **toutes globales** (modèles et preset) ; **0 exigence propre à une société** | Configuration | `/company/workforce/requirements` (« mode parallèle ») | Adoption manuelle | Ne pilote **que** l'intégration (`OnboardingPlanner.php:34`). La conformité opérationnelle reste « historique » (`CompanyRequirementResolver.php:81`). | 0 adoption |
| **Demandes de documents** (`document_requests`, `DocumentRequestService`) | Une demande précise pour une personne et un type de pièce | statut : `requested` → `submitted` → `in_review` → `approved` / `rejected` / `to_replace` / `cancelled` | À tout moment | Documents → À traiter et Demandes, fiche, Mon espace | **6 chemins**, voir §3 Q5 | — | Société 1 : 58 ouvertes, dont 35 sur des salariés sans compte |
| **Campagnes** (`DocumentCampaignService`) | Demander une pièce à une population | demandes | Ponctuel | Documents → Demandes | Manuel | Recoupe la demande en lot par rôle | — |
| **Conformité** (`DocumentComplianceReadModel`) | Taux et retards | Calcul | Lecture | Widgets, Documents | — | Agrégée **par rôle RBAC** (`:181`) | — |
| **Documents RH générés** (`generated_documents`, modèles de contrat) | Documents produits par l'entreprise | Fichiers et versions | Contrat, attestations | Documents → Modèles, fiche → Contrats | Manuel | — | — |
| **Contrats** (`workforce_employment_contracts`) | Conditions d'emploi | brouillon → actif | Après la création de la personne | Fiche → Contrat | Manuel | **Absent** des parcours d'ajout et d'intégration | Société 1 : 12 salariés sans contrat |
| **Signatures** (`SignatureService`, adaptateurs) | Signer un document généré | Demandes de signature | Après génération | Documents → Signatures, Mon espace | Manuel | — | — |
| **Presets métier** (`presets`, `jobdomain_preset_links`) | Proposer organisation, postes, droits et exigences | Versions publiées | Adoption | Organisation, Exigences, Rôles | Manuel (bascules OFF) | — | Liens logistique : organisation, rbac, requirements |

## 2. Source de vérité des exigences d'embauche

**Il n'y en a pas une seule.** Pour une même personne, ce qui est exigé dépend de l'endroit où l'on regarde :

```
Fiche personne / Mon espace / conformité ──▶ DocumentResolverService(rôle RBAC)
        = catalogue obligatoire ∩ required_tags(rôle) ∪ activation société.required_override, surcouche doc_config(rôle)

Dossier d'intégration ──▶ OnboardingPlanner → EffectiveRequirementResolver(POSTE)
        = requirements (modèles, preset, adoptions société) pour le poste choisi, sélection modifiable au cas par cas

Champs requis ──▶ field_activations.required_override (société) ∪ company_roles.field_config (rôle) ∪ requirements(poste, intégration)
```

`RequirementParityComparator` existe précisément pour mesurer l'écart entre les deux calculs. L'écart est donc connu, mais il n'est pas résorbé.

## 3. Réponses aux 15 questions

| # | Question | Réponse actuelle (prouvée) | Cible |
|---|---|---|---|
| 1 | Où configure-t-on les champs exigés pour un poste ? | Seulement dans `/company/workforce/requirements` (parallèle, ne vaut que pour l'intégration). Ailleurs, les champs requis sont par société (`_ProfileFieldsSettings`) ou par rôle RBAC (tiroir des rôles). | **Organisation → Postes → [poste] → onglet « Intégration »**, seul endroit. |
| 2 | … les pièces exigées pour ce poste ? | Même page parallèle. Les pièces **réellement** exigées dans la fiche suivent le rôle RBAC, sans aucune interface société pour `doc_config`. | Même onglet du poste. |
| 3 | … les documents à fournir ? | Pièces = types de documents. Les documents **produits par l'entreprise** (contrat) sont configurés à part : Documents → Modèles. | Même onglet du poste, en deux listes : « à fournir par la personne » et « produits par l'entreprise ». |
| 4 | Type de document ou demande individuelle ? | Le type (`document_types`) est le modèle : libellé, formats, expiration. La **demande** (`document_requests`) est l'occurrence pour une personne, avec son statut et son historique. | Inchangé. |
| 5 | Comment une demande est-elle créée automatiquement ? | **6 chemins**, sans règle unique : (a) **à la lecture**, `MemberDocumentWorkflowReadModel.php:90` crée la demande des pièces requises lorsqu'on consulte un **compte**, mais pas un salarié sans compte ; (b) demande manuelle (`RequestDocumentUseCase`) ; (c) en lot **par rôle** (`BatchRequestByRoleUseCase`) ; (d) campagne ; (e) renouvellement (`DocumentLifecycleOrchestrator`, `documents:auto-renew`) ; (f) dépôt d'intégration versé au DMS. | Une seule règle : **à l'affectation d'un poste ou à l'ouverture du dossier d'intégration**, les exigences du poste deviennent des demandes. Plus aucune écriture lors d'une lecture. |
| 6 | Ajouter une exigence exceptionnelle à une personne ? | Demande manuelle depuis la fiche, ou case cochée dans la sélection du dossier d'intégration. | Inchangé : « Ajouter une demande » sur la fiche ou le dossier. |
| 7 | Modifier les exigences sans casser les dossiers ouverts ? | **Bien résolu pour l'intégration** : le dossier fige un snapshot versionné, et une révision est explicite avec aperçu des effets (`previewRevision`, `revise`). Pour la fiche, une modification de rôle ou d'activation change le calcul immédiatement. | Même principe partout : les demandes existantes ne bougent pas ; une nouvelle exigence s'applique aux nouvelles affectations, ou par une action explicite « appliquer aux personnes en poste ». |
| 8 | Comment le salarié transmet-il une pièce ? | Avec compte : Mon espace → Mes documents (`/profile/documents/{code}`). Sans compte : portail d'intégration (`/onboarding`, jeton puis code). Ou dépôt par la société sur la fiche. | Inchangé. |
| 9 | Comment le gestionnaire la valide-t-il ? | Documents → À traiter, ou fiche → Documents (`…/review`, `ReviewMemberDocumentUseCase`). Pour l'intégration : `_OnboardingReviewDialog`. Il y a donc **deux écrans de validation**. | Une seule file « À traiter » : les dépôts d'intégration y apparaissent comme les autres. |
| 10 | Pièce refusée, remplacée, expirée ? | `rejected`, puis nouvelle transmission ; `to_replace` ; l'expiration est surveillée (`documents:check-expiration`), avec renouvellement automatique (`documents:auto-renew`) et relances (`documents:auto-remind`). Ces commandes ne s'exécutent qu'avec le scheduler, absent en DEV et en PROD (ADR-814). | Inchangé ; scheduler obligatoire. |
| 11 | Quand le dossier est-il complet ? | Intégration : `submitted` puis `validated` par décision RH. Fiche : aucune notion de « dossier complet ». Seul existe le taux de conformité par rôle. | **Dossier d'embauche complet** = toutes les exigences **bloquantes** du poste satisfaites (données validées, pièces approuvées, contrat signé si exigé). |
| 12 | Personne sans compte ? | Portail `/onboarding` : lien sécurisé (jeton en fragment, puis cookie), code par e-mail, quarantaine antivirus, consentement et base légale (ADR-796 et 796.1). | Inchangé. C'est l'**option B**. |
| 13 | Éviter de demander deux fois ? | Champs : écrivain canonique (`PersonFieldWriter`) ; la validation propose « accepter » ou « garder l'existant ». Pièces : `PersonDocumentContinuity` et identité partagée (ADR-795.1). | Inchangé ; le snapshot exclut ce qui est déjà validé. |
| 14 | Confidentialité ? | Champs sensibles chiffrés (`field_definitions.encrypted`), divulgation tracée (`person_field_disclosures`), `DocumentConfidentiality`, droits `documents.confidential_*`, finalité et base légale du dossier. **Mais** l'affichage des champs par `visibility_roles` RBAC mélange confidentialité et rôle. | La confidentialité reste portée par des **permissions** ; le poste ne donne aucun droit de lecture. |
| 15 | Étapes indispensables avant la prise de poste ? | Le code n'impose **aucune** étape. Seule la planification exige un contrat **actif** (`CreateShiftUseCase` I4). | Données d'identité, contrat actif, exigences **bloquantes** du poste (`level` imperative ou blocking, `stage = hire`). Le reste peut être complété après la prise de poste (`stage = onboarding`). |

## 4. Concepts à ne pas confondre (glossaire cible)

| Concept | Support | Exemple | Qui le décide |
|---|---|---|---|
| **Champ de donnée** | `field_definitions` + valeurs | N° de sécurité sociale | Poste (exigence), personne (saisie) |
| **Pièce justificative** | `document_types` + `document_requests` + `member_documents` | Permis de conduire | Poste (exigence), personne (dépôt), gestionnaire (validation) |
| **Document produit par l'entreprise** | modèles + `generated_documents` | Contrat de travail | Entreprise |
| **Exigence de conformité** | `requirements` (`level`, `legal_reference`) | « Permis obligatoire pour Chauffeur » | Règle applicable, métier, puis société |
| **Validation administrative** | statut d'une demande ou d'une valeur | « Approuvé » | Gestionnaire habilité |
| **Signature** | demandes de signature | Contrat signé | Signataires |
| **Droit d'accès au logiciel** | `memberships` + rôle RBAC | « Accès chauffeur » | Société, indépendamment de tout ce qui précède |

## 5. Parcours cible « Embaucher »

```
Équipe ▸ « Embaucher »
 1. Personne : prénom, nom, e-mail facultatif, date d'entrée
 2. Poste    : choix dans Organisation → Postes  ⇒ affiche les exigences du poste (champs, pièces, documents produits)
 3. Mode     : ( A ) Compléter maintenant avec la personne     ( B ) Envoyer un lien sécurisé
               │                                                │
               │  même formulaire, même snapshot, mêmes valeurs en attente, mêmes dépôts
               ▼                                                ▼
 4. Dossier d'embauche (fiche personne → onglet « Embauche ») : progression, vérification, demandes de compléments
 5. Contrat  : brouillon prérempli (poste, date) → génération → signature (si le module est actif)
 6. Finaliser : salarié activé ; accès au logiciel facultatif (rôle d'accès choisi ICI, jamais avant)
```

- **A et B utilisent le même moteur** : `OnboardingInvitationService`. En mode A, le dossier est saisi par la société, sans lien envoyé ; les valeurs passent par le même circuit « en attente → validé ». En mode B, c'est le portail existant.
- **L'étape 1 remplace les deux créations actuelles.** Le salarié préparatoire devient l'unique point de départ ; « Ajouter une personne » sans embauche reste possible (personne déjà en poste, import).

## 6. Fonctions jamais utilisées ou inertes (constat)

- `requirements` propres à une société : 0 ligne dans toute la base.
- Dossiers d'intégration : 0 (les fixtures ont été supprimées, ADR-814 et ADR-796) ; le moteur n'a pas d'usage réel.
- `jobdomain_market_overlays` et `jobdomain_dashboard_defaults` : 0 ligne.
- `field_value_changes` : 0 ligne. `field_values` : société 1, une seule valeur de champ de personne enregistrée, pour 52 salariés.
