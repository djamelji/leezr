# ADR-796 — L6 : dossier d'intégration autonome (recrue sans compte)

**Date** : 2026-11-15 · **Base** : `1f707904` · **Environnement** : DEV local uniquement · **Statut** : livré, checkpoint L6 (L7 non commencé)

L'entreprise prépare un dossier, transmet un lien sécurisé (en mode test, aucun envoi), la recrue complète ses informations et dépose ses documents **sans compte**, puis les RH vérifient et valident. Aucun second moteur de champs, aucun second DMS, aucune nouvelle obligation pour les salariés existants.

## 1. Prérequis ADR-795.1 résolus (avant toute route publique)

| Prérequis | Solution | Preuve |
|---|---|---|
| **A. Appartenance → fiche RH** | Lecture : une valeur portée par l'appartenance reste visible (`source: membership`, `needs_review`) tant que la fiche RH n'en porte pas. Reprise `MembershipFieldTransfer`, déclenchée automatiquement à la création ou au rattachement d'une fiche pour un membre (`AttachEmployeeToMemberUseCase`), avec les résultats `transferred`, `already_same`, `conflict` et `contract_protected`. Elle est déterministe, idempotente, limitée à la société, n'écrase rien, ne supprime pas la valeur source, respecte les champs protégés et s'historise (`membership_transfer`). | `Adr796PrerequisitesTest` |
| **B. Anciennes valeurs de personnes à plusieurs employeurs** | Jamais attribuées automatiquement. La personne voit dans son profil les valeurs non communiquées (`undisclosed_codes`) et les partage explicitement, société par société (`POST /profile/disclosures`). L'autre voie est une nouvelle collecte par un dossier d'intégration. Les valeurs historiques sont conservées. | idem |
| **C. Traçabilité de collecte** | Le registre `person_field_disclosures` est enrichi de la finalité, de la base juridique **déclarée**, de la version de l'information fournie, du lien avec l'invitation et de la demande de retrait (date et motif). La date de communication est `created_at`, la source `source`. Aucune base juridique n'est déduite : sans déclaration, `legal_basis` reste vide. Une demande de retrait est **tracée pour revue RH**, jamais appliquée automatiquement, car une obligation de conservation peut s'appliquer. | idem |

## 2. Architecture

```
Équipe (RH, workforce.manage)
  └─ Inviter une recrue → OnboardingPlanner (préparation, lecture seule)
        ├─ règles en vigueur ADR-790 (requirementMap, activations)
        ├─ exigences configurées L1 pour le poste (EffectiveRequirementResolver)
        └─ propositions de modules non adoptées (L4), affichées et jamais cochées d'office
     → photographie versionnée (snapshot) de CE dossier → lien (jeton haché)
Recrue (sans compte) : /onboarding#t=…  →  session HttpOnly  →  OnboardingPortalService
        ├─ champs demandés → valeurs EN ATTENTE (onboarding_values)
        └─ documents demandés → SubjectDocumentUploadService (DMS unique) → demande DMS « submitted »
RH : vérification → décision par valeur → PersonFieldWriter (contexte onboarding) → stockage canonique
```

## 3. Moteurs réutilisés

| Besoin | Réutilisé |
|---|---|
| Définitions, activations, types, validation | `FieldDefinition`, `FieldActivation`, `EmployeeFieldStore::rulesFor` |
| Écriture des valeurs validées | `PersonFieldWriter`, nouveau contexte `onboarding`, contrôlé par le droit RH |
| Lecture canonique et conflits | `EmployeeFieldStore::cellsFor` |
| Exigences | ADR-790 (lecture), L1 (lecture), L4 (lecture) |
| Documents | dépôt **extrait** de `PersonDocumentController` vers `SubjectDocumentUploadService` (comportement inchangé, 142 tests DMS verts), puis `DocumentRequestService::markSubmitted` : une demande par sujet et par type, validation requise, revue dans le DMS existant |
| Sujet documentaire | `PersonSubjectResolver` (compte s'il existe, sinon fiche RH) |
| Jeton opaque | même principe que la signature externe (aléa de 256 bits, empreinte SHA-256) |
| Audit | `AuditLogger` (`onboarding.created`, `link_issued`, `revised`, `submitted`, `changes_requested`, `validated`, `revoked`, `purged`), jamais le jeton |
| Interface | `AppDialog`, `AppSettingsGroup`, `AppStatusText`, `DynamicFormRenderer`, champs `App*` (ADR-782) |

Au passage, `PersonDocumentController` passe de 258 à 197 lignes, sous le seuil de l'invariant de taille.

## 4. Schéma (migration additive `2026_11_27_000001`)

| Table | Contenu |
|---|---|
| `onboarding_invitations` | société, fiche RH, poste, statut, `token_hash` (unique), expiration, révocation, e-mail de vérification, `snapshot`, version et historique, finalité, base juridique déclarée, information remise (texte et version), conservation (jours), code à usage unique (empreinte, expiration, tentatives), message RH, jalons (envoi, début, soumission, validation, purge) |
| `onboarding_sessions` | invitation, empreinte de session, expiration, `verified_at` |
| `onboarding_values` | invitation, définition, valeur, statut `pending` / `accepted` / `kept_existing`, décision |
| `person_field_disclosures` | + finalité, base juridique, version de l'information, invitation, demande de retrait |

## 5. Cycle de vie

États : `draft` → `sent` → `in_progress` → `submitted` (à vérifier) → `changes_requested` ↔ `submitted` → `validated`.

| Transition | Règle |
|---|---|
| Vers `expired` | à l'échéance, constatée à la lecture de la liste et par la commande de conservation |
| Vers `revoked` | depuis tout état sauf `validated` |
| Écriture de la recrue | possible seulement en `sent`, `in_progress` ou `changes_requested` |
| `submitted` | lecture seule pour la recrue |
| `validated`, `expired`, `revoked` | état final : session et jeton invalidés |
| Soumission répétée | idempotente |
| Validation RH | distincte de la soumission |
| Nouveau lien | invalide l'ancien jeton et ses sessions |

## 6. Exigences et photographie versionnée

- L'aperçu indique pour chaque élément ses sources (règle en vigueur, exigence configurée avec son origine, proposition de module) et les divergences : « règle actuelle et configuration divergent » ou « exigé par la configuration seulement ».
- Seules les règles en vigueur sont pré-cochées ; une proposition de module non adoptée reste visible, jamais cochée.
- La sélection RH est figée dans `snapshot` (version, poste, date, éléments et sources).
- Une exigence modifiée ensuite **ne change pas le dossier** (testé). La révision est explicite (`PUT …/items`) : nouvelle version, ancienne conservée dans `snapshot_history`, dossier soumis repassé en « complément demandé ».
- Le candidat ne se voit proposer **que des données personnelles** : les données RH de l'entreprise (poste, contrat…) sont refusées (422).
- Rien n'est appliqué aux salariés existants. ADR-790 reste le moteur opérationnel, et la page ADR-792 reste en préparation.

## 7. Sécurité de l'invitation

- **Jeton** : 256 bits aléatoires, seule son empreinte est stockée (`token_hash` unique). Il est affiché **une seule fois** côté RH, dans un champ masqué sur les captures.
- **Aucun jeton dans une URL envoyée au serveur** : le lien porte le jeton dans le **fragment** (`/onboarding#t=…`), qu'aucun navigateur n'envoie au serveur ni dans un `Referer`. La page le transmet une fois dans le **corps** d'un POST, puis l'efface de la barre d'adresse (`history.replaceState`).
- **Session** : cookie `HttpOnly`, `SameSite=Strict`, limité au chemin `/api/public/onboarding`, de 2 h au plus et jamais au-delà de l'échéance. Elle est re-vérifiée à chaque appel, et le CSRF est conservé (mêmes utilitaires que l'application).
- **Portée** : une session → une invitation → une société → une fiche RH. Aucun identifiant interne n'est accepté ni renvoyé.
- **Réponse uniforme** (404/401) pour un jeton inconnu, expiré ou révoqué : pas d'énumération possible.
- **En-têtes** : `Cache-Control: no-store`, `Referrer-Policy: no-referrer`, `X-Robots-Tag: noindex`. `SecurityHeadersMiddleware` n'écrase plus une politique plus stricte.
- **Limites de débit** distinctes par action (ouverture 10/min, saisie 30/min, dépôt 20/min, code 3/10 min, vérification 10/10 min, soumission 10/min) et 5 tentatives par code.
- **Lien intercepté ou transféré** : un lien porteur n'est **pas une preuve d'identité**. Les informations sensibles (NIR, IBAN) et les pièces hautement confidentielles (identité, bancaire, sociale, judiciaire) exigent un **code à usage unique envoyé à l'e-mail renseigné par les RH**, canal distinct du lien (empreinte, 10 min, 5 essais). Sans e-mail, elles ne sont pas demandées au candidat. Les RH sont informées dans l'interface que le lien seul ouvre le dossier.
- **Aucun secret** dans l'audit, les événements front ou les captures (testé pour l'audit).

## 8. Champs et documents

- **Champs** : uniquement ceux de la photographie (422 sinon). Validation serveur par les règles canoniques (type, format, options, longueur). Les valeurs sont **en attente**, jamais écrites dans User, Membership ou Employee depuis l'endpoint public. Une valeur sensible ne s'affiche à la recrue qu'en session vérifiée.
- **Documents** : uniquement les types de la photographie et de portée personne (404 sinon). Contrôles : extensions et type réel (`mimes`), taille du type, 5 fichiers au plus. Stockage privé à chemin unique, doublon par empreinte, versions. Le statut DMS `submitted` n'est **jamais** une validation.
- **Pas d'antivirus** : aucun mécanisme n'existe dans le projet (voir les risques résiduels).
- **Côté public** : aucun téléchargement, aucune suppression ; on remplace en déposant une nouvelle version.
- **Type documentaire de module** : la politique **effective** du DMS s'applique (ADR-795 refuse les déclarations non appliquées).

## 9. Validation RH

La vue de vérification (Équipe → « Intégrations en cours ») affiche :
- pour chaque information : valeur reçue, valeur actuelle (canonique) et conflit, avec une décision **par valeur** (« Reprendre » ou « Garder l'actuelle ») — aucune valeur ne devient une donnée RH sans décision ;
- pour chaque pièce : son état DMS, avec un renvoi vers le dossier documentaire pour la contrôler.

Actions disponibles :
- demander un complément (message affiché à la recrue) ;
- valider ;
- révoquer ;
- générer un nouveau lien.

La validation écrit par `PersonFieldWriter`, avec les règles suivantes :
- donnée personnelle d'une personne qui a un compte : sur le compte, avec une divulgation à cette société tracée (`onboarding`, finalité, base déclarée, version, invitation) ;
- sinon : sur la fiche RH ;
- contrat établi : confirmation explicite requise (testé) ;
- historique : `field_value_changes` (source `onboarding`).

## 10. Confidentialité

- Les gestionnaires sans `workforce.sensitive_read` / `members.sensitive_read` voient `••••` pour les valeurs sensibles reçues.
- `members.view` seul n'accède à rien (403).
- Une valeur reçue d'une personne à plusieurs employeurs n'est divulguée qu'à la société du dossier (testé).
- La création ultérieure d'un compte ne déplace ni ne divulgue les données d'une société à une autre : chemin ADR-795.1.

## 11. Traçabilité et conservation

| Élément | Règle |
|---|---|
| Finalité | `hiring_onboarding` ou `employment_administration`, choisie par les RH |
| Base juridique | **déclarée** par l'entreprise parmi `contract_preparation`, `legal_obligation`, `consent`, `legitimate_interest`. Obligatoire avant d'émettre un lien, jamais présumée ; le consentement n'est pas proposé par défaut. Leezr l'enregistre sans la vérifier : l'interface le dit. |
| Information remise | texte saisi par les RH, versionné par empreinte, montré à la recrue avec la base déclarée |
| Accès | `workforce.manage` ; valeurs sensibles avec un droit sensible |
| Expiration | 1 à 30 jours par lien ; constatée sans suppression |
| Révocation | immédiate ; jeton et sessions supprimés |
| Conservation | `retention_days` par dossier (30 par défaut, 1 à 365) |
| Purge | `onboarding:retention` (simulation par défaut) purge les **valeurs en attente** et sessions d'un dossier **clos** (validé, expiré, révoqué) une fois son délai écoulé ; jamais à l'expiration ; auditée |
| Documents DMS | jamais supprimés ici (leur propre conservation s'applique) |
| Candidature non retenue | révoquer, puis appliquer la conservation configurée |
| Accès, rectification, suppression | aujourd'hui par les RH, à partir des données du dossier. Les règles applicables doivent être vérifiées marché par marché avant usage réel. |

## 12. Permissions

Aucune nouvelle permission. Les routes RH (`/workforce/onboarding-files…`, préfixe distinct de l'assistant de démarrage Workforce `/workforce/onboarding` existant) existent sous `workforce.manage`, les valeurs sensibles sont filtrées par les droits sensibles existants, et aucun rôle n'est modifié (testé : aucune ligne de permission ajoutée). Le portail public n'utilise aucune permission Company. Ses routes figurent dans la liste d'exemption explicite des tests de couverture des modules, comme la signature publique ; en contrepartie, le service vérifie à chaque appel que le module Workforce est actif pour la société du dossier (testé).

## 13. Tests et recette

- **`Adr796PrerequisitesTest`** : 2 tests (A, B, C).
- **`Adr796OnboardingTest`** : 6 tests.

| Scénarios | Couverture |
|---|---|
| Parcours complet | 1, 3, 4, 9, 10, 12, 13, 14, 15, 16, 17, 18, 19, 23, 26, 27, 28, 31, 32 |
| Sécurité des liens | 2, 5, 6, 7, 8, 35 (aucune fuite d'identifiant, de jeton ni d'autre société ; jeton absent de l'audit) |
| Isolation entre candidats | 11, 34 (aucun téléchargement public) |
| Évolution des exigences | 21 (révision explicite versionnée ; donnée RH refusée au candidat) |
| Salarié avec compte | 20, 22, 24, 25, 30 (complément demandé, compte multi-employeurs, contrat établi, ancienne valeur ambiguë jamais montrée, divulgation limitée à la société) |
| Un dossier par personne et conservation | 33 (concurrence logique), conservation |
| Transition appartenance → fiche RH | 29 (`Adr796PrerequisitesTest`) |

- **Tests liés** : 353 réussis (ADR-787, 794, 795, 795.1, DMS, People, layouts, garde-fous).
- **Recette navigateur** (`tests/e2e/adr796-onboarding.spec.js`) : 1/1, parcours RH prépare → recrue renseigne et dépose (mobile, **nouveau navigateur sans session Company**, jeton retiré de l'URL, aucune navigation Company) → reprise après rechargement → soumission (thème sombre) → lien invalide → RH vérifie et valide.
- **Défauts trouvés et corrigés pendant le lot** :
  - la clé de traduction `onboarding` et la route `/workforce/onboarding` existaient déjà (assistant de démarrage Workforce). Mon premier jet les écrasait ; la suite complète l'a révélé. La clé d'origine a été restaurée à l'identique depuis Git, et mes routes et clés sont renommées (`onboarding-files`, `onboardingFile`) ;
  - le portail public ne vérifiait pas le module Workforce ; c'est désormais fait à chaque appel ;
  - le client du portail remplaçait l'objet `Headers` d'`ofetch` : il n'émettait donc aucune requête ;
  - une proposition de module non adoptée était pré-cochée : elle ne l'est plus ;
  - les boutons de décision se chevauchaient, et les libellés documentaires n'étaient pas traduits.
- **Suite complète** : voir §18.

## 14. Captures (`docs/bmad/adr796/`)

Données fictives uniquement ; lien masqué.

| Fichier | Contenu |
|---|---|
| `hr-prepare-items.jpg` | préparation des éléments demandés |
| `hr-link-ready.jpg` | lien prêt, champ masqué |
| `candidate-start-mobile.jpg`, `candidate-filled-mobile.jpg` | recrue sur mobile, début puis dossier rempli |
| `candidate-submitted-mobile-dark.jpg` | dossier soumis, thème sombre |
| `candidate-invalid-link.jpg` | lien invalide |
| `hr-review.jpg`, `hr-validated.jpg` | vérification puis validation RH |

## 15. Performances (DEV, société 1)

| Mesure | Résultat |
|---|---|
| Planificateur (25 éléments proposés) | 15,6 ms, 29 requêtes : lecture de trois sources, une fois par préparation |
| Liste des intégrations | 2,4 ms, 4 requêtes, indépendant du nombre de dossiers (lot) |
| Portail | lecture et écriture limitées au dossier (requêtes constantes) |

## 16. Risques résiduels

- **Lien porteur** : quiconque possède le lien lit et complète les informations non sensibles du dossier. Le code distinct protège les éléments sensibles, mais seulement si un e-mail est renseigné. Pas de SMS (aucun canal disponible).
- **Pas d'antivirus ni d'analyse de contenu** des fichiers : seuls l'extension, le type MIME, la taille et le stockage privé sont contrôlés.
- **Pas d'envoi d'invitation par e-mail** (contrainte du lot) : copie manuelle du lien.
- **Révision des éléments demandés** : disponible par l'API seulement (pas encore d'écran).
- **Fiches préparatoires `inactive`** : elles apparaissent dans la liste Équipe filtrée par statut. La recette a laissé des fiches « E2E Recrue » sur DEV comme trace d'exécution.
- **Expiration constatée lors de la lecture de la liste RH** : une écriture dans un GET, assumée et sans suppression. La commande planifiable `onboarding:retention` reste la voie normale.
- **Demandes de retrait et d'exercice des droits** : enregistrées, mais traitées manuellement par les RH.

## 17. Conditions avant activation réelle

1. Validation juridique, par marché, des finalités, bases juridiques et textes d'information, ainsi que des durées de conservation.
2. Envoi des invitations par un canal maîtrisé (e-mail transactionnel) et, si possible, d'un second facteur indépendant (SMS).
3. Analyse antivirus et de contenu des fichiers déposés.
4. Planification de `onboarding:retention --apply` et revue de ses rapports.
5. Écran de révision des éléments demandés, et procédure documentée de traitement des demandes de retrait et d'accès.
6. Test de charge du portail public et revue de sécurité externe (lien, session, CSRF, limites).

## 18. Résultat et SHA

- **Suite complète finale** : 4452 réussis, 12 ignorés, 3 échecs = dette historique `ControllerSizeInvariantTest`. Une exécution précédente avait révélé les collisions décrites au §13, corrigées ensuite.
- **Build** : propre.
- **Commit** : `git log --grep "ADR-796"` sur `dev`.
