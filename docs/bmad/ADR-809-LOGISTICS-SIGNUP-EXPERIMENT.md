# ADR-809 — Bascule expérimentale Logistique (DEV) : une inscription simple et fonctionnelle

- **Date** : 2026-11-16, DEV local uniquement, base `d2ae575f`.
- **Garanties du lot** :
  - aucune modification PROD, staging ou VPS ;
  - aucune Company existante touchée ;
  - aucun nouveau moteur ;
  - aucun changement Billing, paie ou Requirements.
- **Bascule** : activée le temps de la recette, puis **remise à l'état désactivé** (`.env` local restauré à l'identique, vérifié). Les presets et les recommandations restent publiés pour validation.

**Critère final atteint** : une entreprise Logistique s'inscrit par le vrai parcours, reçoit sa configuration minimale, confirme ses accès en **une décision**, crée sa première expédition et la fait livrer, sans intervention technique.

## 1. Presets Logistique : contrôle et recommandations

| Famille | Version | Contrôle | Lien `logistique` (DEV) |
|---|---|---|---|
| Rôles `logistique_rbac` | v2 publiée (v1 archivée) | 5 rôles. Tous les bundles existent dans les manifestes, aucun n'est inerte. `owner` administratif ; Chauffeur-livreur opérationnel ; Responsable, Répartiteur et Responsable d'exploitation administratifs (sur décision) | **Créé** |
| Exigences `logistique_requirements` | v1 publiée | 41 éléments, **identiques à l'historique** ; tous les codes de champ et de document existent ; niveaux d'origine (3 « obligatoire configurable », 38 « par défaut ») | **Créé** |
| Organisation `logistique_base_v1` | v1 publiée | Direction, Exploitation, Responsable d'exploitation | **Créé** (facultatif : décision explicite, non appliquée à l'inscription) |
| Planning `logistique_planning` | v1 publiée | Non applicable : le module Planning n'est pas dans le métier | Non lié |
| Modules | — | Métier : 5 modules cœur et Expéditions (inclus). Aucun module payant | — |

Aucune permission ni obligation inventée. Aucun preset appliqué aux sociétés existantes. Un lien de recommandation ne fait qu'afficher des suggestions dans les assistants ; il n'attribue rien.

## 2. Bascule DEV

- `.env` local : `JOBDOMAIN_VERSIONED_INIT=true` et `JOBDOMAIN_VERSIONED_INIT_JOBDOMAINS=logistique`, pendant la recette seulement.
- Pas de cache de configuration : effet immédiat ; aucun autre environnement touché.
- Pendant la recette, `shouldUse('logistique')` valait vrai et `shouldUse('beta-services')` faux : le chemin historique est resté actif pour les autres métiers.
- Fin de recette : lignes retirées, `.env` **identique à la copie d'avant**, `shouldUse('logistique')` à faux.

## 3. Inscription réelle (E2E, parcours utilisateur, société fictive)

| Vérification | Résultat |
|---|---|
| Parcours | 6 étapes de l'écran existant : métier, offre, modules optionnels, entreprise, compte, récapitulatif → `POST /api/register` **201** → accueil |
| Propriétaire | Créé, connecté, rattaché au rôle `owner` |
| Métier | Logistique |
| Rôles initiaux | Propriétaire et Chauffeur-livreur. Les rôles de gestion ne sont pas créés sans décision |
| Presets appliqués | rôles et exigences (tracés) ; organisation non appliquée (décision explicite) |
| Modules | 5 modules cœur et Expéditions ; **0 abonnement de module** |
| Exigences | 36 activations de champs, 5 de documents, 41 lignes de registre. SIRET obligatoire à l'inscription (France), enregistré |
| Offre | Starter, 1 abonnement actif, **0 facture**, 0 portefeuille |
| Droits opérationnels | **Aucun droit effectif** avant confirmation (chauffeur : `my-deliveries` en 403) |
| Accueil propriétaire | Carte « Accès à confirmer » ; un clic mène à Équipe → Rôles |
| Confirmation | **Une seule** revue groupée : « Chauffeur-livreur — Informations entreprise, Gestion du thème, Annuaire de l'équipe, Documents, Livraisons ». Un bouton ; pivots identiques ; audit |
| Activité | Première expédition **créée par l'écran**, marquée « planifiée », affectée. Le chauffeur se connecte, ouvre sa livraison, la passe « en transit » puis « livrée » (écran). État en base : `delivered` |
| Accès interdits | Chauffeur : liste générale des expéditions 403, rôles 403, revue des accès 403, page Modules refusée par le routeur |
| Désactivation et réactivation | Non pertinent ici : le module inclus n'a pas de désactivation à l'écran. Le cas est couvert par ADR-806/807 |

**Écart de recette documenté** : le compte chauffeur a été créé par fixture, rattaché au rôle issu de l'inscription, au lieu d'une invitation par e-mail. Tout le reste passe par l'interface réelle.

## 4. Confirmation groupée des accès

- **Où** : en tête de l'onglet Équipe → Rôles (vues par capacité et avancée), et carte d'accueil « Accès à confirmer » (fournisseur de priorités existant `RolesModule`). Aucune page ajoutée.
- **Ce qu'elle montre** :
  - les rôles concernés et leurs capacités principales, en libellés métier ;
  - les **accès sensibles à part**, en rouge ;
  - les droits de base hors capacité complète, en nombre ;
  - les rôles qu'**on ne peut pas confirmer soi-même**, d'après la politique de délégation.
- **Ce qu'elle fait** : `POST /company/pending-access/confirm` (`roles.manage`) passe par `PendingModuleAccess::confirm`, avec la même politique de délégation et le même audit.
  - Une **empreinte** lie la confirmation exactement à ce qui a été affiché : si quelque chose a changé, la réponse est 409 et rien n'est confirmé.
  - Aucun droit ajouté ni retiré : seule l'attente est levée.
- **Réutilisé** : `CapabilityAccessService::capabilities` (capacités et sensibilité), `PendingModuleAccess`, `MembershipSecurityPolicy`, priorités d'accueil, `useCatalogText`. Pas de nouveau moteur ni d'assistant indépendant.

## 5. Mesures de simplicité (parcours réel mesuré)

| Mesure | Valeur | Détail |
|---|---|---|
| Étapes d'inscription | 6 | dont 1 facultative (modules optionnels, « Suivant » sans choix) |
| Réglages obligatoires à l'inscription | 6 champs | nom de l'entreprise, pays, SIRET (France), prénom, nom, e-mail + mot de passe ; adresse renseignée |
| Décisions du propriétaire après l'inscription | **1** | « Confirmer les accès » (un bouton pour tous les rôles) |
| Validations techniques | 0 | aucune permission, aucun module, aucun assistant obligatoire pour commencer |
| Pages pour commencer l'activité | 3 | Accueil (carte), Équipe → Rôles (confirmation), Expéditions (création) |
| Éléments techniques visibles | 0 | vérifié sur la revue : pas d'identifiant de preset, de version, de permission interne ni de diagnostic |

## 6. Comparaison historique / versionné

Mesure `CreationParity`, transaction annulée, presets et liens finaux :

| | Historique | Versionné |
|---|---|---|
| Défauts | — | **0** |
| Modules, champs et documents activés | identiques | identiques |
| Rôles | 5 rôles créés, **effectifs immédiatement** | owner et Chauffeur-livreur ; rôles de gestion sur décision ; **droits opérationnels en attente de confirmation** |
| Permissions | capacités sans effet incluses | retirées (ADR-807) |
| Noms | « Driver » | « Chauffeur-livreur » |
| Prescriptions champs/documents par rôle | oui | non (volontaire, ADR-797) |
| Traçabilité | aucune | `preset_applications`, audit |

## 7. Constat de sécurité non corrigé (décision requise)

- **Limites de débit partagées.** Dans Laravel, toutes les limites numériques (`throttle:N,1`) d'un même visiteur partagent **un seul compteur** (domaine + IP), quelle que soit la route. Vérifié dans `ThrottleRequests::resolveRequestSignature`.
- **Effet observé en recette** : les appels publics chargés par la page d'inscription (thème, offres, marchés, champs…) consomment le quota de 10 de `POST /register`. La validation a reçu un **429** ; un vrai utilisateur rapide peut être bloqué au dernier clic. Même risque pour la connexion, la double authentification et la réinitialisation du mot de passe.
- **Correctif proposé** : un préfixe par route sensible (`throttle:10,1,register`, `throttle:15,1,login`…), mécanisme natif de Laravel, avec les mêmes limites par IP.
- **Non appliqué** : la modification a été refusée par le contrôle de sécurité automatique de l'environnement. C'est un changement de contrôle de sécurité ; il doit être validé explicitement. La recette a respecté la limite : relecture du récapitulatif pendant plus d'une minute.

## 8. Tests

- `Adr809LogisticsSignupTest`, 2 tests :
  - **inscription par l'API réelle** : chemin versionné tracé, rôle de gestion non créé, aucun module payant ;
  - **avant confirmation** : aucun droit opérationnel ; carte d'accueil pour le propriétaire seulement ;
  - **revue groupée** : capacités, aucune sensible, empreinte ; refusée au chauffeur (403) ; empreinte périmée refusée (409) ;
  - **confirmation** : pivots identiques, audit ;
  - **activité** : première expédition, affectation, transit, livraison ; accès interdits ;
  - **délégation** : un administrateur sans droits Expéditions voit le rôle « bloqué », rien n'est confirmé ;
  - **autre métier** non listé : chemin historique.
- Régression ciblée et suite complète : voir `04-decisions.md`.
- **E2E** `adr809` : **2/2** (inscription par l'écran, puis confirmation et activité), captures dans `docs/bmad/adr809/`.
  - Société 1 inchangée.
  - Fixture supprimée par identifiants exacts : société, propriétaire, chauffeur, expédition, abonnement Starter. Contrôle final : 0.
  - Deux tentatives intermédiaires (sociétés 91 et 92, erreurs de mon script de recette) ont été supprimées de la même façon.

## 9. Écarts restants

1. **Limites de débit partagées** (§7) : décision requise.
2. **Rôles de gestion proposés** (Responsable, Répartiteur, Responsable d'exploitation) : non créés à l'inscription, à juste titre (sensibles). Il n'y a pas encore de moyen simple de les créer **depuis le preset** après l'inscription ; seule la création manuelle d'un rôle est possible.
3. **Invitation du chauffeur** : recette faite par fixture ; le parcours d'invitation existant n'a pas été rejoué dans ce lot.
4. **Écran de création d'expédition** : origine et destination sont obligatoires à l'écran, alors que l'API les accepte vides. C'est une incohérence mineure, existante, conservée.
5. **Dette Billing distincte** : le devis crée un portefeuille.
6. **ControllerSizeInvariantTest** : 3 échecs historiques conservés. Le nouveau contrôleur est petit et n'utilise pas `DB`.
