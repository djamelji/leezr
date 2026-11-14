# Company Experience 2.0 — UX3 : Documents, parcours directs, recherche transversale (ADR-778)

**Date** : 2026-11-14 · **Base** : `3076ca9c` · **Suite de** : `COMPANY-EXPERIENCE-2.0-UX2.md` (ADR-777) · **Preuves** : `docs/bmad/ux3-778/`

## 0. Décisions UX2 appliquées

| Décision | Réalisation |
|---|---|
| Cinq rubriques de la fiche conservées | inchangé |
| Historique replié, libellés métier | 46 actions du journal traduites (« Invitation envoyée », « Contrat activé », « Document signé »…) ; un code inconnu reste lisible ; l'auteur est dit en clair |
| Anciens rôles Driver | aucune permission retirée |
| Actions directes | « Ajouter un document » ouvre le dépôt, « Demander un document » ouvre le choix de la pièce, « Faire signer » ouvre l'assistant avec le document sélectionné (section 5) |

## 1. Audit ciblé de l'existant

Page `documents/[tab].vue` à `3076ca9c` : 8 onglets (Vue d'ensemble, Demandes, Campagnes, Import bulletins, Conformité, Coffre-fort, Documents RH, Paramètres), plus 3 sous-onglets dans Documents RH (documents générés, modèles, signatures).

| Constat | Détail |
|---|---|
| Vue d'ensemble sans action | compteurs, deux grandes cartes de renvoi (« Accéder au coffre-fort », « Paramètres documents »), puis conformité par rôle et par type. On n'y traite rien. |
| File à valider enfouie | sous « Demandes », non filtrée par défaut |
| Trois lieux pour un même besoin | une pièce se cherche dans le coffre, dans Documents RH ou dans la fiche ; aucune recherche commune |
| Dépôt sans formulaire | uniquement par le bouton « Téléverser » d'une ligne, à condition de savoir où se trouve la ligne |
| Signatures à trois niveaux | Documents → Documents RH → Signatures → Nouvelle demande, document à rechoisir |
| Ligne de document | jusqu'à quatre puces (cycle de vie, demande, analyse, format) et cinq boutons ; déborde à 1440 px ; sur mobile, seule la première colonne était visible |
| Réglages au premier niveau | Paramètres, Campagnes, Import bulletins et Conformité au même rang que les gestes quotidiens |

Réutilisable tel quel et conservé : coffre de l'entreprise, file des demandes (visualisation, validation, refus, actions groupées), campagnes, import de bulletins, conformité, registre RH et page Signatures (déjà intégrables), assistant de signature (accepte un document présélectionné), panneau documentaire d'une personne, moteurs et politiques d'accès.

## 2. Architecture Documents avant / après

| Avant | Après |
|---|---|
| 8 onglets + 3 sous-onglets | **4 vues** : À traiter · Entreprise · Collaborateurs · Signatures |
| Paramètres, Campagnes, Import, Conformité, Modèles en onglets | menu **Outils** (chaque outil reste une vue complète, avec un retour) |
| aucun point d'entrée de création | bouton **Nouveau** |
| aucune recherche | **recherche** en tête de page, exécutée par le serveur |
| Vue d'ensemble (compteurs) | **À traiter** : la file à valider en place + les suivis |

| Vue | Contenu | Visible si |
|---|---|---|
| À traiter | file des pièces à valider (validation et refus sur place) ; suivis : demandes en attente de dépôt, expirés, expirent bientôt, renouvellements de l'entreprise, manquants, signatures en attente — chaque ligne ouvre la vue déjà filtrée | `documents.view` ou signatures |
| Entreprise | coffre de l'entreprise (dépôt, versions, renouvellement, prolongation) | `documents.view` |
| Collaborateurs | pièces demandées aux personnes ; documents générés (contrats, attestations) | `documents.manage` ; registre : `workforce_documents.view` + module |
| Signatures | suivi par état (toutes, en attente, en cours, terminées, refusées, expirées) | `workforce_signature.view` + module |

Les états ne sont pas mélangés : « à valider » est une file, les suivis sont des compteurs qui renvoient chacun vers son traitement. Ranger deux fonctions dans une vue ne fusionne ni leurs permissions ni leurs données : chaque bloc garde sa condition d'origine.

Anciens liens conservés : `overview` → À traiter, `vault` → Entreprise, `requests` → Collaborateurs (filtre conservé), `hr` → Collaborateurs, `hr?tab=signatures` → Signatures, `hr?tab=templates` → Modèles. Vérifié en navigateur.

Le prestataire de signature externe (M6) n'est présenté nulle part comme opérationnel : aucun libellé ni réglage ajouté à ce sujet.

## 3. « Nouveau »

| Entrée | Ouvre | Droit |
|---|---|---|
| Déposer un document | le dépôt (section 4) | `documents.manage` |
| Demander un document | le formulaire de demande existant | `documents.manage` |
| Lancer une demande collective | la création de campagne existante | `documents.manage` |
| Faire signer | l'assistant de signature existant | `workforce_signature.request` + module |
| Importer des bulletins | la vue d'import | `payslips.import` |

Cinq entrées au plus ; une entrée absente n'est pas grisée, elle n'existe pas. Les actions d'accueil « Demander un document » et « Déposer un document » ouvrent désormais ces formulaires au lieu d'un onglet.

## 4. Dépôt simplifié

Un dialogue unique (`DocumentDepositDialog`), qui connaît son contexte :

| Origine | Déjà renseigné | Demandé |
|---|---|---|
| Documents → Entreprise | l'entreprise | type, fichier |
| Fiche d'une personne | la personne (affichée, jamais redemandée) | type, fichier |
| Autre vue de Documents | — | entreprise ou personne, type, fichier |

La date de validité n'est demandée que si le type l'exige dans le catalogue ; aucune date n'est déduite. Types, formats et tailles viennent du catalogue. Les endpoints sont ceux qui existaient (coffre, pièces d'une personne) ; le dépôt d'une pièce soumise à validation reste « à valider ».

Le dépôt par le bouton d'une ligne reste disponible.

## 5. Actions directes depuis la fiche

| Action | Comportement | Lien direct |
|---|---|---|
| Ajouter un document | ouvre le dépôt, personne renseignée | `?do=deposit` |
| Demander un document | ouvre la section sur le choix de la pièce ; fonctionne aussi pour une fiche sans compte | `?do=request` |
| Faire signer | cherche le document le plus récent de la personne qui n'est ni signé ni en cours ; ouvre l'assistant avec ce document sélectionné. L'envoi passe toujours par le récapitulatif de l'assistant. Sans document : message et bouton « Générer un contrat » | `?do=sign` |

Les liens fonctionnent en navigation directe et après rechargement ; l'action n'est exécutée que si elle figure dans le menu calculé à partir des capacités serveur, puis elle est retirée de l'adresse.

## 6. Dossier documentaire de la personne

Rubrique « Documents & contrats » : Contrat → Documents (pièces à fournir et fournies, documents générés, signatures) → Bulletins de paie (autorité paie).

Ligne de pièce refaite :

- **Un état**, celui que le serveur consolide déjà : À fournir, Demandé, À valider, Refusé, Valide, Expire bientôt, Expiré.
- **Une action principale** selon l'état (« Approuver » si à valider, sinon « Téléverser » / « Remplacer le fichier »).
- **Un menu** pour le reste : aperçu, télécharger, remplacer, prolonger, rejeter, supprimer. Une pièce confidentielle non autorisée y apparaît verrouillée.
- Sur petit écran, une pièce est un bloc : intitulé, état, actions — rien hors écran (vérifié par position).

Aucune pièce n'est fusionnée ni déplacée : pièces, documents générés et signatures restent trois listes, chacune avec ses versions, ses preuves et sa classification.

## 7. Recherche transversale

`GET /api/company/documents/search` — critères : texte (nom, type, personne), provenance, type, personne, statut, période, échéance. Pagination serveur.

Les modules contribuent leurs **sources** par manifeste (`Capabilities::$documentSearchSources`) ; le Core assemble sans connaître ni type ni règle d'accès :

| Source | Documents | Autorité appliquée à chaque ligne |
|---|---|---|
| `company` | coffre de l'entreprise | `documents.view` + `DocumentAccessPolicy` |
| `person` | pièces des personnes, **fiches sans compte comprises** | `documents.view` + `DocumentAccessPolicy` (sujet, catégorie, relation) |
| `generated` | documents générés | `workforce_documents.view` + `GeneratedDocumentAccess` (périmètre d'équipe, rémunération) ; état de signature réel (règle ADR-773) |

Garanties :

- Une ligne refusée n'est pas renvoyée, même si elle correspond exactement au terme (test : une carte d'identité n'apparaît pas à un profil `documents.view` seul).
- Seules des métadonnées de liste sont renvoyées : ni chemin de fichier, ni texte extrait, ni instantané de génération.
- Le lien de téléchargement est la route gardée existante.
- Module inactif : sa source n'est pas interrogée.
- Profondeur bornée : 200 lignes autorisées par source ; au-delà, la réponse indique `truncated` et l'interface invite à préciser. Le navigateur ne reçoit que la page affichée.
- Une source en échec est isolée (`unavailable`).

Non couverts par la recherche : les bulletins externes publiés (ils restent accessibles dans la fiche, sous autorité paie).

## 8. Automatisations réutilisées

Aucune n'a été réécrite ; l'interface s'appuie dessus :

| Automatisme existant | Vérifié dans ce lot |
|---|---|
| Contrat généré → classé dans le dossier | navigateur : le contrat généré est retrouvé par la recherche et dans la fiche |
| Dépôt soumis à validation → file « à valider » | navigateur : état « À valider » puis « Valide » après approbation |
| Demande → suivi | navigateur : état « Demandé » |
| Fiche sans compte → dossier complet | navigateur : dépôt, demande, validation, contrat sur une fiche sans compte |
| Signature finalisée → version signée, expiration → renouvellement, campagne → demandes, rattachement → continuité | inchangés, couverts par les tests ADR-771 à 773 (suite complète) |

## 9. Campagnes et import de bulletins

Vues inchangées, atteintes par « Outils » ou « Nouveau ». Aucun contrôle de rapprochement, de revue ou de publication n'a été retiré ; l'import n'a pas été simplifié.

## 10. Matrice RBAC

| Opération | Droit (inchangé) |
|---|---|
| Voir qu'une pièce est attendue (obligation) | `documents.view` (ou soi, ou responsable direct) |
| Métadonnées d'une pièce | `DocumentAccessPolicy` : standard `documents.view` ; identité/bancaire `documents.view_confidential` ou `workforce.sensitive_read` ; bulletins : droits paie |
| Téléchargement | même politique, opération `download` |
| Validation, refus, prolongation, dépôt pour autrui | `documents.manage` |
| Publication de bulletins | `payslips.review` / `payslips.publish` |
| Signature | `workforce_signature.view` / `.request` |
| Administration (types, réglages) | `documents.configure` |
| Documents générés | `workforce_documents.view` + périmètre + rémunération |

| Scénario | Vérification |
|---|---|
| Propriétaire | navigateur : 10 parcours avec écriture |
| RH sans droit paie | navigateur : vue Collaborateurs seule (documents générés), recherche limitée à cette source, aucun refus réseau |
| Gestionnaire documentaire (Dispatcher) | navigateur : À traiter, Entreprise, Collaborateurs ; Outils : campagnes, conformité, réglages ; pas de signatures |
| Gestionnaire paie, manager limité | navigateur : pas d'espace Documents |
| Fiche sans compte | navigateur : dépôt, demande, validation, recherche |
| User lié à une fiche | navigateur : dossier mobile et desktop |
| Entreprise sans Workforce | navigateur : pas de vue Signatures, source « générés » non interrogée, anciens liens valides |
| Multi-company | navigateur : changement d'entreprise ; test : une autre entreprise ne voit rien, 403 hors adhésion |
| Confidentialité | test : pièce d'identité invisible sans droit confidentiel ; téléchargement inter-entreprises refusé |
| Entreprise sans module Documents | sans objet : `core.documents` est un module de base, toujours actif |

## 11. Parcours mesurés

« Après » : exécuté par `tests/e2e/ux3-documents.spec.js`, écritures réelles sous registre d'exécution, clics comptés par instrumentation. « Avant » : audit ADR-774 et mesures UX2.

| Parcours | Avant | Après (mesuré) |
|---|---|---|
| Déposer un document de l'entreprise | 3 clics (Documents, Coffre-fort, Téléverser sur la ligne) | 5 clics, 2 champs par « Nouveau » ; 2 clics par la ligne (toujours possible) |
| Déposer un document pour une personne | 3 clics, après avoir trouvé la ligne (UX2) | 5 clics, 2 champs (Actions, Ajouter, type ×2, Déposer), personne déjà renseignée |
| Demander un document | 4 clics | 3 clics, 0 champ |
| Valider | 1 clic depuis la fiche ; 3 depuis l'accueil | 1 clic depuis la fiche ; sur place dans « À traiter » |
| Retrouver et télécharger | pas de recherche : trois lieux possibles | 1 champ, 1 clic ; vrai PDF servi |
| Générer un contrat, puis le retrouver | 3 clics, puis onglet de la fiche | 3 clics ; retrouvé par la recherche |
| Préparer une signature | 4 clics et plus, document à rechoisir | 2 clics, document présélectionné |
| Suivre une expiration | 3 clics (Documents, Conformité, filtre) | 1 clic depuis « À traiter » quand une échéance existe ; 2 clics par Outils (cas mesuré : aucune échéance en DEV) |
| Lancer une campagne | 3 clics | 2 clics |
| Consulter un bulletin autorisé | 1 clic | 1 clic |

Lecture honnête : le dépôt par formulaire coûte plus de clics que le bouton d'une ligne déjà repérée ; son intérêt est de ne pas avoir à chercher la ligne ni la personne. Les baisses nettes portent sur la signature, la demande, la campagne et la recherche. Aucune durée n'est avancée. Requêtes en erreur sur les parcours : 0.

## 12. Tests

| Niveau | Contenu | Résultat |
|---|---|---|
| Backend | recherche : droits par catégorie, fiche sans compte, critères, pagination, cloisonnement, 403 sans droit documentaire, 422 sur statut inconnu, clés renvoyées, téléchargement réel et refus inter-entreprises | vert |
| Suite complète | une fois | voir checkpoint |
| Navigateur | `ux3-documents.spec.js` : 10 parcours avec écriture, 4 profils, entreprise sans Workforce, mobile | voir checkpoint |
| Régression | recettes UX0, UX1, UX2 et historiques portées sur les nouveaux flux | voir checkpoint |

Le démontage des recettes supprime désormais les pièces et demandes d'une fiche qu'il possède.

## 13. Captures

`docs/bmad/ux3-778/` : `j-owner-01-todo.jpg` à `08-deposit.jpg` (vues, Outils, Nouveau, dépôt), `file-documents.jpg`, `sign-preselected.jpg`, `search.jpg`, `campaign-open.jpg`, `hub-<profil>.jpg`, `hub-no-workforce.jpg`, `j-tablet-*.jpg`, `j-dark-*.jpg`, `j-desktop-*.jpg`, `mobile-todo.jpg`, `mobile-file-documents.jpg`.

## 14. Défauts résiduels

1. **Données de recette** : les documents générés par les recettes (contrats de test) restent en base après le démontage — ils sont immuables par conception et l'application n'offre pas de suppression. Sept documents orphelins du jour ont été identifiés ; leur suppression directe a été refusée par le garde-fou d'exécution et n'a pas été contournée. Décision attendue (section 15).
2. Dans la file « à valider », une pièce d'une fiche sans compte s'affiche sans nom de personne.
3. « Créer un type personnalisé » reste proposé dans le dossier d'une personne.
4. Les campagnes nécessitant une intervention ne figurent pas dans « À traiter ».
5. La recherche ne couvre pas les bulletins externes publiés ; profondeur bornée à 200 lignes par source.
6. Le dépôt par « Nouveau » demande plus de clics que le bouton d'une ligne.
7. « Faire signer » choisit le document le plus récent ; s'il y en a plusieurs, le choix se corrige dans l'assistant.
8. Vue Collaborateurs longue pour un profil qui a les deux blocs (demandes et documents générés).
9. Résiduels UX0 à UX2 inchangés.

## 15. Conditions pour UX4

- Décider du sort des documents générés par les recettes : les laisser, ou autoriser le démontage à supprimer les documents générés non signés d'une fiche qu'il possède.
- Valider les libellés : « À traiter », « Entreprise », « Collaborateurs », « Signatures », « Nouveau », « Outils », et les états d'une pièce.
- Confirmer que Campagnes, Import de bulletins, Conformité, Modèles et Types & réglages restent sous « Outils ».
