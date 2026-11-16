# ADR-810 — Documents approuvés mais invisibles au salarié

- **Date** : 2026-11-16, DEV uniquement, base `28869cd4`.
- **Garanties du lot** :
  - aucune Company réelle modifiée ;
  - aucune migration ;
  - aucun nouveau moteur DMS ni Requirements ;
  - aucun droit élargi ;
  - aucun changement Billing ni paie.

## 1. Chaîne tracée (données DEV, société 1, lecture seule)

| Élément | Constat |
|---|---|
| Alice | `users.id = 2` (`admin@leezr.test`), `workforce_employees.id = 2` (`employee-2`), adhésion 3, rôle **`dispatcher`** (id 9) |
| Approbation du jour | Le propriétaire approuve la demande **4** (`id_card`) à 17:29:49. Événement `approved`, preuve `member_document_id = 2`, empreinte du fichier `ci_alice_martin.png` |
| Document 2 | `member_documents.id = 2`, sujet `user:2`, `published_state = published`, déposé le 17/10. **Visible** dans la fiche (« Valide ») et dans Mon espace (« Valide · Approuvé ») : reproduit au navigateur, captures `before-*.jpg` |
| Demande 5 | `driving_license`, **transmise** (`submitted`) le 11/11 à 16:58, sans acteur et **sans aucun fichier** (aucun `member_documents` ni `document_versions`) |
| Rôle `dispatcher` | `doc_config` : `driving_license` et `medical_certificate` → `visible: false` |
| Fiche et Mon espace | **Absence totale** de la ligne « Permis de conduire », alors qu'une demande est adressée à Alice |

**Sources des trois vues** (non identiques) :

| Vue | API | Source |
|---|---|---|
| Documents → À traiter | `GET /company/document-requests/queue`, `POST …/bulk-action` | **demandes** (`document_requests`) |
| Fiche → Documents | `GET /company/people/{ref}/documents` → `MemberDocumentWorkflowReadModel` | `DocumentResolverService::resolve` (catalogue filtré par le rôle), enrichi des demandes |
| Mon espace → Documents | `GET /profile/documents` → `SelfDocumentReadModel` | `DocumentResolverService::resolve`, même filtre |

**Approbation : ce qu'elle fait réellement.**
- Elle change l'état de la demande (`approved`), avec relecteur, date, événement et empreinte du fichier validé.
- Le document est **déjà** `published` depuis son dépôt. Il n'existe **aucune politique « visible après approbation ou signature »** dans le modèle (ni colonne, ni réglage, ni libellé) : une pièce fournie par la personne est consultable par elle dès le dépôt.
- La signature ne concerne que les **documents RH générés** (`workforce_signature`). Une pièce demandée n'a pas d'étape de signature.
- Aucune transition n'a été inventée.

## 2. Cause racine

`DocumentResolverService::resolve` écarte tout type marqué `visible: false` dans le `doc_config` du rôle. Ce réglage définit la **liste attendue par défaut** d'un rôle. Il s'appliquait aussi à un document **explicitement demandé** à la personne, ou **qu'elle détient**.

Conséquences :
- **Demandé, non fourni** : la personne ne voit pas la demande et ne peut pas fournir la pièce depuis Mon espace.
- **Transmis puis approuvé** : la demande passe à « approuvée » dans « À traiter », mais le document est absent de sa fiche et de son espace. C'est le symptôme rapporté.
- La file « À traiter » lit les demandes ; les deux autres vues lisent le catalogue filtré. D'où trois projections incohérentes de la même identité documentaire.

**Défaut annexe** : approuver en lot une demande sans fichier (demande 5) était ignoré côté serveur (`skipped`, garde existante), mais l'écran affichait un toast vert « 0 document approuvé ». Le vrai résultat n'était pas signalé.

## 3. Correction minimale

- **`DocumentResolverService::resolve`** (présentation uniquement ; `requirementMap` et la conformité sont inchangés). Un type masqué par le rôle reste affiché pour la personne quand elle a :
  - une **demande non annulée** de ce type ;
  - ou **un document** de ce type.

  Le masquage s'applique toujours au reste : type jamais demandé ni détenu.
- **Approbation groupée** : quand une demande n'a pas pu être approuvée (pièce absente ou déjà traitée), l'écran l'annonce par un avertissement en FR/EN/AR, au lieu d'un succès.
- **Inchangé** :
  - les contrôles d'accès : téléchargements, versions et fiche gardés comme avant (`documents.view`, sujet propre pour `/profile/…`, politique de confidentialité, isolation société) ;
  - aucune copie de fichier, aucun second registre, aucun droit ajouté.

## 4. Trois interfaces : avant / après

| Cas | À traiter | Fiche (propriétaire, RH) | Mon espace (Alice) |
|---|---|---|---|
| Avant : permis demandé, masqué par le rôle | demande listée | **absent** | **absent**, impossible de le fournir |
| Après : demandé, non fourni | demande listée | « À fournir » | ligne présente, « Téléverser » |
| Après : transmis | à valider | « Transmis », fichier | « Transmis » |
| Après : approuvé | disparaît de la file | **« Valide »**, même document (même id) | **« Valide · Approuvé »**, consultation et téléchargement |
| Approbation sans fichier | **avertissement** (avant : succès vert trompeur), demande inchangée | « Transmis », pas de fichier | « Transmis », peut refournir |
| Type masqué, jamais demandé | — | absent | absent |

## 5. Statuts vérifiés (modèle réel)

| Statut | Résultat |
|---|---|
| Demandé, non fourni | visible pour la personne et sur sa fiche |
| Fourni, en attente | visible, état « transmis » |
| Refusé | visible avec le motif ; refournissable |
| Approuvé | visible des deux côtés, même document |
| Type non publiable (masqué, jamais demandé) | reste invisible |
| Signature en attente / signé | sans objet pour une pièce demandée (pas d'étape de signature dans le modèle) ; les documents RH générés relèvent d'ADR-805 |
| Expiré | visible, `lifecycle_status = expired` |
| Remplacé | dernière version affichée ; ancienne version conservée et téléchargeable par la RH |
| Demande annulée sans fichier | le masquage du rôle s'applique de nouveau |

## 6. Tests

- `Adr810ApprovedDocumentVisibilityTest`, 4 tests, 75 assertions :
  - **parcours complet** : type masqué invisible ; demandé, visible ; fourni ; approuvé par la file (`processed` 1) ; même identifiant de document dans la fiche (propriétaire **et** RH avec `documents.view`) et dans Mon espace ; téléchargements 200 avec le bon contenu ; type non demandé toujours masqué ; collègue non concerné ;
  - **statuts** : refusé avec motif, remplacé (versions), expiré, annulé ;
  - **approbation sans fichier** : groupée avec `skipped` 1, unitaire en 422, demande inchangée ;
  - **sécurité** :
    - Alice n'accède ni à la liste, ni au téléchargement, ni aux versions de Bob (403) ; sa route personnelle ne sert jamais le fichier de Bob ;
    - isolation multi-société (404) ;
    - salarié sans compte : document rattaché à sa fiche, refusé à Alice.
- **Preuve de pertinence** : sans la correction, 3 des 4 tests échouent ; le test de sécurité passe dans les deux cas.
- Régression documents (DMS, conformité, exigences, personnes, espace personnel, onboarding) : **554 verts**. Suite complète et build : voir `04-decisions.md`.
- **E2E** (société fictive isolée, captures `a*` / `o*`), contrôlé par l'API et la base :
  1. le propriétaire demande le permis à Alice ;
  2. Alice voit la ligne et **fournit le fichier par l'écran** : demande « transmise », document créé ;
  3. le propriétaire **approuve dans Documents → À traiter** : demande « approuvée » ;
  4. la fiche d'Alice montre le permis « Valide » (même identifiant de document) ;
  5. Mon espace d'Alice affiche « Valide · Approuvé » ; le téléchargement renvoie 200 avec son contenu ; le certificat médical, jamais demandé, reste absent ;
  6. les documents de Bob sont refusés à Alice (liste, téléchargement, versions : 403).

  Société 1 inchangée (demandes, documents, événements). Fixture supprimée par identifiants exacts, fichiers compris.

## 7. Écarts restants

1. **Demande 5 (DEV, société 1)** : « transmise » sans fichier, données d'origine inconnues (antérieures à ce lot). Non corrigée, car c'est une société réelle de DEV. Désormais visible pour Alice, qui peut refournir la pièce ; l'approbation sans fichier reste refusée.
2. **Référence `employee-…` sans module Workforce** : la fiche répond 404 alors que l'API des documents l'accepte (contournement : `user-…`). Écart mineur, existant, hors périmètre.
3. **Fiche** : la colonne « Statut » affiche l'état du fichier (« Valide ») sans la mention « Approuvé » visible dans Mon espace.
4. **Fiche** : le bloc « Aucun document. » (documents RH générés) suit la liste des pièces et peut prêter à confusion.
5. **ControllerSizeInvariantTest** : 3 échecs historiques conservés.
