# Leezr 5.0 — 14 · Livraison L5 · Personnes et rationalisation des champs (ADR-858)

Livré en local sur `dev`, sans push ni déploiement. Aucun nouveau moteur de champs, aucun nouveau RBAC, Billing inchangé. Aucune écriture sur les sociétés historiques réelles : l'audit, la migration et la recette tournent sur le clone marqué `leezr_audit842`.

## 1. Company › Personnes : avant / après

| | Avant | Après |
|---|---|---|
| En-tête | titre « Équipe », phrase générique, bouton « Outils » | « Personnes » et une phrase calculée (`59 personnes · 58 actives · 5 invitations en attente · 22 dossiers incomplets`), « Configurer les champs » (catalogue), « Ajouter une personne » |
| Synthèse | bandeau « 22 membres avec un profil incomplet » | tuiles cliquables : toute l'équipe, à traiter, dossiers incomplets, invitations en attente, intégrations en cours ; chacune filtre la liste |
| Filtres | « Compte » et « Emploi », sans compteurs | statut, équipe, poste et accès, chacun avec son compteur ; recherche ; réinitialisation |
| Colonnes | personne, accès, poste, emploi | personne, poste et équipe, statut RH (avec le contrat), accès à Leezr (avec le rôle), **dossier** (complétude), **prochaine étape** (bouton qui ouvre l'onglet utile), menu |
| Clic sur une ligne | ouvre directement la fiche | **aperçu rapide** (panneau V22) : prochaine étape, poste et rattachement, contrat (type, date, état ; jamais la rémunération), intégration étape par étape, dossier, actions autorisées, « Ouvrir la fiche complète » |
| Mobile | tableau compressé | cartes compactes (identité, poste, statut, prochaine étape), tuiles sur 2 colonnes, filtres empilés |
| Onglets | dans la carte | pastilles sous l'en-tête (Personnes, Gestion RH, Rôles & autorisations) |

Toutes les données viennent du serveur et respectent les permissions :
- **Synthèse, compteurs, complétude et prochaine étape** : calculés sur ce que l'acteur peut déjà voir.
- **Chiffre non lisible** (invitations ou complétude sans administration des accès) : `null`, donc pas de tuile, jamais un 0 trompeur.
- **Prochaine étape** : déduite uniquement de la ligne visible (invitation expirée ou en attente, intégration en cours, dossier incomplet). « À jour » ne s'affiche que si le dossier est mesuré complet.
- **Aperçu rapide** : il lit la même fiche que la page complète. La rémunération et les champs sensibles n'y figurent pas.

## 2. Configuration des champs : une interface par responsabilité

Implémentations vérifiées avant de décider :
- la validation des formulaires (`FieldResolverService`, `FieldValidationService`) applique `required_override`, `validation_rules.required`, `required_by_modules`, `required_by_jobdomains` (ignoré en autorité « poste ») et le calque `field_config` par rôle ;
- le registre des exigences (`job_role:*`) alimente la complétude et l'intégration.

| Responsabilité | Source de vérité | Interface unique |
|---|---|---|
| Définition, format, contraintes, obligation partout | `field_definitions.validation_rules.required` | Platform › Catalogue global |
| Obligation liée à un module | `validation_rules.required_by_modules` | Platform › Catalogue global (s'applique si le module est actif) |
| Recommandation du métier | `required_by_jobdomains`, presets JobDomain | Platform › JobDomain |
| Disponibilité dans la société | `field_activations.enabled` | **Company › Catalogue › Champs** |
| Obligation pour **toute** la société | `field_activations.required_override` | **Company › Catalogue › Champs** (« Rendre obligatoire pour toute la société ») |
| Obligation **propre à un poste** | registre `requirements` `job_role:*` | fiche du poste, ou « Exiger pour un poste » depuis le catalogue (même registre) |
| Obligation historique par rôle d'accès | `company_roles.field_config` | Rôles & autorisations (encart explicatif ajouté) |

Distinctions rendues visibles dans le catalogue :
- **Disponible ≠ obligatoire** : le statut « Actif » et la colonne « Obligation » sont séparés.
- **Obligation globale ≠ obligation de poste** : « Toute la société » d'un côté, les puces de postes de l'autre.
- **Héritage** : un encart décrit la chaîne Leezr → votre métier → votre société → le poste. Chaque obligation porte son origine (puce et infobulle) : Leezr, module, métier, toute la société, postes, rôles (historique). Un champ exigé mais **masqué pour un rôle** est signalé.

### « Outils → Champs » : réglages conservés, déplacés, dépréciés

| Fonction de l'ancien tiroir | Devenir |
|---|---|
| Activer ou masquer un champ | **existait déjà** dans le catalogue (Activer / Désactiver) : doublon supprimé |
| Créer un champ propre à la société | **existait déjà** dans le catalogue : doublon supprimé |
| Obligatoire (case par champ) | **déplacé** dans le catalogue : « Rendre obligatoire pour toute la société » (même stockage, même effet) |
| Modifier libellé et options d'un champ propre | **déplacé** dans le catalogue (« Modifier le champ » ; les valeurs sont conservées) |
| Supprimer un champ propre | **déprécié** dans l'interface : la suppression efface toutes les valeurs, sans retour possible. « Désactiver » (réversible) la remplace. L'API reste inchangée. |
| Ordre des champs | **déprécié** : valeurs stockées conservées, plus aucune interface (l'ordre suit le catalogue et le calque des rôles) |

Le composant `_ProfileFieldsSettings.vue` est retiré. Aucune donnée n'a été supprimée.

## 3. Migration des règles historiques

Outil : `php artisan fields:rules-audit`.
- **Lecture et simulation** : par défaut, n'importe où.
- **Écriture** (`--apply` et `--rollback=backup.json`) : refusée hors d'un clone marqué, garde `RecipeDatabaseGuard`.
- **Ce qui est appliqué** : seules les règles **redondantes** changent, et seulement si chaque compte garde exactement les mêmes champs requis et manquants (sinon, retour arrière automatique).

Classes :

| Classe | Signification | Action |
|---|---|---|
| `company_redundant` | obligation « toute la société » sur un champ déjà exigé par Leezr, un module actif ou le métier | effacée (réversible) |
| `role_redundant` | obligation par rôle sur un champ déjà exigé par Leezr ou la société | effacée (réversible) |
| `company_global` | véritable obligation de toute la société | **conservée** (catalogue) |
| `company_dormant` | obligation enregistrée sur un champ que la société ne propose plus | **conservée et signalée** (ambiguë) |
| `conflict_hidden` | champ exigé mais masqué pour certains rôles (le formulaire l'écarte pour eux) | **conservé et signalé** |
| `role_historical` | obligation par rôle | **conservée** : opérationnelle en autorité « rôle » ; ambiguë en autorité « poste ». **Jamais convertie automatiquement** en exigence de poste. |

Résultat sur le clone, état réel (`l5-fields-audit-clone.json`) :
- 26 sociétés, **aucune** obligation « toute la société » enregistrée : le tiroir n'avait jamais été utilisé pour cela ;
- les sociétés logistiques (autorité « rôle ») portent 31 règles par rôle chacune, opérationnelles et conservées ;
- 1 à 5 conflits `conflict_hidden` par société logistique : des champs du module flotte masqués pour des rôles de bureau. Ils sont signalés, rien n'est modifié ;
- les deux sociétés en autorité « poste » (105, 108) n'ont aucune règle historique ;
- **rien n'était à migrer**.

Preuve sur une ancienne configuration (société fictive 101, fixture `audit842-fields858`, reproduction de ce que le tiroir permettait d'enregistrer) :

| Étape | Résultat |
|---|---|
| Simulation | 1 `company_redundant` (date d'embauche, déjà exigée par le métier), 1 `role_redundant` (Chauffeur), 1 `company_global` (nationalité), 1 `company_dormant` (commune de naissance) |
| Application | 2 règles changées, comportement **identique** pour les 5 comptes (mêmes champs requis et manquants) |
| Après application | redondances disparues ; obligation globale et règle ambiguë conservées |
| Retour arrière | 2 lignes restaurées ; plan identique à l'original |

L'état « ancienne configuration » est laissé sur le clone pour la recette.

## 4. Ajouter une personne

Le parcours M6 est conservé : « Embauche complète » (assistant) et « Ajout rapide » (tiroir).
- L'ajout rapide propose maintenant le **poste**, facultatif, quand une fiche RH est créée. Il montre ce que le poste exige (« Ce poste demande : Nationalité »), si l'utilisateur peut le lire.
- Rien de plus n'est demandé à la création : ces informations sont collectées ensuite dans le dossier.
- La fiche est créée avec son poste ; la navigation va vers la fiche créée.

## 5. Recette navigateur (clone, vrais clics) : `audit842-people858-recipe` 5/5

| # | Vérification | Résultat |
|---|---|---|
| 1 | équipe avec dossiers complets et incomplets ; synthèse = chiffres du serveur | ✅ |
| 2 | recherche, tuile « Dossiers incomplets », filtre par poste | ✅ |
| 3 | aperçu rapide : poste, intégration, sans donnée de paie, ouverture de la fiche | ✅ |
| 4 | création sans accès, avec poste ; exigences du poste affichées | ✅ |
| 5 | création avec invitation, statut d'accès dans la liste | ✅ |
| 6 | exigence de poste posée depuis le catalogue, visible dans le catalogue et dans l'ajout | ✅ |
| 7 | héritage expliqué ; origines (métier, toute la société) | ✅ |
| 8 | ancienne configuration (société 101) ; obligation globale retirée puis remise au seul endroit prévu ; « Outils » absent | ✅ |
| 9 | manager, RH sans paie, utilisateur limité, dispatcher : rien de plus que permis, aucun montant dans l'aperçu ; salarié et chauffeur sans liste | ✅ |
| 10–12 | desktop, tablette, mobile, sombre : 0 débordement, 0 erreur JavaScript, 0 requête refusée | ✅ |

Captures à largeur égale :
- `captures/l5/avant/` : liste propriétaire, manager et limité, en desktop et mobile ; tiroir « Outils → Champs » ; catalogue ; clic sur une ligne ;
- `captures/l5/apres/` : mêmes vues, plus tablette, sombre et ancienne configuration ;
- `captures/l5/recipe-*` : captures de la recette.

## 6. Tests

- PHPUnit `Adr858PeopleAndFieldRulesTest` 6/6 :
  - synthèse, compteurs et prochaine étape ;
  - chiffres non lisibles à `null` ;
  - filtre par poste ;
  - origine des obligations ;
  - migration (redondances seules, comportement identique, retour arrière exact) ;
  - écriture refusée hors clone.
- `Adr802OneFunctionOnePathTest` mis à jour : le tiroir est retiré et la configuration renvoie au catalogue.
- E2E `audit842-people858-recipe` 5/5 ; `m7-lot4-recette` mis à jour (configuration via le catalogue, clic → aperçu → fiche).

## 7. Limites restantes

- **Autorité « rôle »** : les obligations historiques par rôle restent la règle opérationnelle des sociétés en autorité « rôle ». Les convertir en exigences de poste demande une décision de la société (bascule d'autorité, ADR-818). Ce n'est pas fait automatiquement.
- **Registre `all` et formulaires** : une exigence « tout le monde » posée dans le registre (portée `all`) compte pour la complétude, mais le formulaire ne la rend pas obligatoire. Seul `required_override` le fait : c'est un écart d'origine (ADR-817), signalé et non corrigé ici.
- **Conflits `conflict_hidden`** : signalés dans le catalogue et l'audit ; la décision revient à la société.
- **Complétude** : calculée uniquement pour les personnes ayant un compte (moteur ADR-168b). Une personne sans compte n'a pas de jauge.
- **Manager sans équipe** sur le clone : liste vide, conforme à son périmètre réel (ADR-767).
- **Embauche complète** (`/company/workforce/hiring`) : non redessinée dans ce lot.
