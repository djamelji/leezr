# ADR-792 — L2 : exigences par poste, configuration Company, mode parallèle

> Base `42193f08` · DEV uniquement · **mode parallèle** : la configuration est enregistrée dans le registre L1 (`requirements`) et n'a **aucun effet** sur les fiches, demandes, conformité, rappels, campagnes, tableau de bord ni création de collaborateurs. `DocumentResolverService::requirementMap` (ADR-790) reste autoritaire (testé).
> Tests : `tests/Feature/Adr792RequirementConfigTest.php`, `tests/e2e/adr792-requirements.spec.js` · captures `docs/bmad/adr792/`.

## 1. Architecture par poste

- **Poste = `JobRole`** (déjà rattaché à la fiche par `workforce_employees.job_role_id`, éditable par les RH). Aucun rattachement ajouté : seules 7 fiches sur 236 ont un poste en DEV ; **aucun poste n'est attribué ni déduit** (ni d'un rôle RBAC, ni d'un nom).
- Une exigence Company porte une **portée** : tous les collaborateurs (`applicability` vide) ou un poste (`applicability.job_role_ids`). Les autres dimensions L1 (service, contrat, marché) restent supportées par le résolveur ; l'interface L2 n'expose que la portée « tous / poste » (le besoin démontré).
- Résolution : **résolveur L1** sur un sujet « poste » (`subjectForJobRole`) ; aucun calcul côté interface.

## 2. Modèle des données

Aucune migration nouvelle. Une ligne Company par (cible, portée) dans `requirements` :

| Action | Effet dans le registre |
|---|---|
| Ajouter obligatoire / facultatif | ligne `company`, niveau `configurable_required` / `recommended` |
| Modifier | ancienne ligne `superseded` (+ `effective_until`), nouvelle ligne (`customized_at`, `derived_from_id` conservé) — **historique conservé** |
| Retirer une exigence Company | ligne `inactive` (jamais supprimée) |
| Retirer une exigence d'une autre source (module, preset, Platform, « tous les postes ») | **dispense** Company (`waive`) tracée |
| Retirer une exigence impérative | **refusé** (422), rien écrit |
| Ré-enregistrer le même état | aucun changement (idempotent) |

Enregistrement en transaction avec verrou (`lockForUpdate`) sur les lignes Company de la société ; journal d'audit `requirements.updated` (portée, nombres, `parallel_mode: true`). Cibles contrôlées : définitions visibles par la société seulement (catalogues Platform ou types propres), postes de la société seulement.

## 3. Interface Company

**Réglages › Travail & RH › Informations & documents demandés** (`/company/workforce/requirements`, entrée du hub « essentielle »).

- En-tête compact, bandeau **« Configuration en préparation »** (pas d'effet opérationnel, règles actuelles appliquées).
- Sélecteur de poste (« Tous les collaborateurs » + postes) avec le nombre de collaborateurs et de salariés sans compte.
- Deux groupes ADR-782 : **Informations**, **Documents** ; lignes compactes : libellé métier, provenance en clair, un seul contrôle d'état (● Obligatoire / Facultatif ⌄, menu avec Retirer), cadenas et justification pour une exigence imposée.
- Ajout par recherche dans les catalogues existants (aucun éditeur de champs ni de types en double).
- Barre « N modification(s) non enregistrée(s) » → **Prévisualiser** (calcul serveur) → **Enregistrer** explicite.
- « Reprise de l'existant » (repliée) : correspondance en lecture seule.
- Aucun identifiant technique, JSON, nom de classe ni clé de module affiché ; libellés des documents traduits par la convention existante (`documents.type.<code>`), libellés des champs dans la langue de la requête.

## 4. Provenance et personnalisations

| Provenance affichée | Source registre |
|---|---|
| Votre entreprise · tous les postes / ce poste | `company` |
| Proposé par la configuration initiale Leezr | `preset` (copie appliquée uniquement) |
| Apporté par le module *Nom du module* | `module` |
| Règle Leezr / Règle du pays | `platform` / `market` |
| Recommandé pour votre activité | `jobdomain` |

Règles L1 respectées : impératif non retirable, justification consultable ; recommandation acceptée (ajout) ou ignorée ; modification d'une exigence configurable conservant historique et origine ; aucune recommandation transformée en obligation impérative.

## 5. Prévisualisation

`POST /api/workforce/requirements/preview` exécute **le même code d'écriture dans une transaction toujours annulée**, puis le résolveur L1 : ajoutées, modifiées, retirées, état avant / après, collaborateurs concernés (dont sans compte), refus impératif. Aucun second moteur.

## 6. Salariés sans compte

Inclus partout : comptés dans chaque portée, résolus par leur poste réel (L1), présents dans l'aperçu. Les valeurs de leurs champs restent pour L5.

## 7. Permissions

| Action | Permission |
|---|---|
| Ouvrir la page, lire, aperçu, reprise | `settings.manage` **ou** `documents.configure` (+ module Workforce, rôle de gestion) |
| Modifier les **informations** | `settings.manage` (même règle que les activations de champs) |
| Modifier les **documents** | `documents.configure` (même règle que les activations documentaires) |

Contrôle serveur élément par élément (403 sur la section non autorisée). `settings.manage` n'est pas élargi. Un livreur : ni entrée ni accès.

## 8. Tests et résultats

| Niveau | Résultat |
|---|---|
| `Adr792RequirementConfigTest` (10 tests, 68 assertions) — scénarios 1 à 20 : société sans exigence ; recommandation JobDomain ; permis obligatoire configurable pour Livreur ; administratif sans permis ; deux postes ; salarié sans compte ; salarié sans poste ; propriétaire salarié sans poste (aucun poste inventé) ; exigence commune ; module (nom affiché, retrait = dispense) ; impératif justifié verrouillé ; retrait impératif refusé ; preset non appliqué ; module désactivé (rien supprimé) ; poste et type d'une autre société refusés, configuration de B jamais lue ; permissions par section ; personnalisation puis modification (historique, audit) ; aperçu sans écriture ; enregistrement explicite idempotent ; **moteur opérationnel inchangé** (décision ADR-790, conformité, demandes, activations identiques avant / après) ; reprise en lecture seule sans déduction depuis le nom du rôle | 10/10 |
| E2E `adr792-requirements.spec.js` (société 1, lecture + aperçu, **aucun enregistrement**) : parcours propriétaire depuis Réglages, aperçu serveur, annulation ; affichage tablette, mobile, clair, sombre sans débordement ; livreur sans accès | 6/6 |
| Suite complète | 4406 réussis, 12 ignorés, 3 échecs historiques `ControllerSizeInvariantTest` (le nouveau contrôleur, d'abord détecté par l'invariant `->save()`, a été corrigé : aucune aggravation) |
| Build | propre |
| Registre DEV | **0 ligne** après recette (rien écrit en DEV partagé) |

Défauts trouvés en recette et corrigés : libellés de documents en anglais (recherche « Permis » sans résultat) ; bascule Obligatoire/Facultatif tronquée et état affiché deux fois → un seul contrôle d'état.

## 9. Captures

`docs/bmad/adr792/after-requirements-desktop-light.jpg` (poste Livreur, ajout en attente), `after-requirements-preview.jpg` (aperçu), `after-requirements-{tablet-light,mobile-light,desktop-dark,mobile-dark}.jpg`. Pas de capture « avant » : l'écran n'existait pas.

## 10. Écarts historiques toujours présents

Inchangés et non appliqués (ADR-791, société 1) : 131 configurations manquantes, 144 ambigus, 9 attendus, 4 arbitrages. La « Reprise de l'existant » les présente : 71 règles de rôles — **0 « déjà reprise »**, les autres « ambiguës » (ex. 12 livreurs dont 6 seulement ont un poste, « Responsable d'exploitation »), « conflit » (permis exigé par la Company, masqué par 4 rôles) ou « décision nécessaire » (aucune personne avec poste). Rien n'est converti.

## 11. Limites

1. La configuration n'a encore aucun effet (par conception).
2. Portées exposées : tous / poste ; service, contrat et site restent disponibles côté moteur.
3. 229 fiches DEV sans poste : sans exigence de poste tant que les RH n'ont pas renseigné le poste.
4. Concurrence couverte par verrou et idempotence ; pas de test multi-processus dédié (le patron a été prouvé sur la numérotation, ADR-787).
5. Champs : la sensibilité (NIR, IBAN) est rappelée par le catalogue ; l'accès aux valeurs reste régi par les règles existantes.

## 12. Conditions de bascule

Celles d'ADR-791, plus : exigences de poste saisies pour les postes réellement utilisés ; postes renseignés sur les fiches concernées ; arbitrages « Company exige, rôle masque » tranchés ; rapport de parité sans bug ; validation produit société par société.

## 13. Préparation du lot suivant (L3)

Presets d'exigences versionnés (modèles `preset` sans Company, copie à l'application, `update_available` déjà calculé en L1), en réutilisant l'aperçu et l'enregistrement de L2 ; la reprise de l'existant pourra proposer des copies à valider.
