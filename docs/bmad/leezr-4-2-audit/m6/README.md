# Leezr 4.2 — M6 : espace Company (ADR-843)

Recette navigateur (`tests/e2e/audit842-m6-recipe.spec.js`, 4/4) sur le clone marqué `leezr_audit842`, sociétés **fictives** 105 (Logistique) et 106 (Beta Services — second métier), 8 profils : propriétaire (×2 métiers), dispatcher, chauffeur (mobile), manager, salarié, membre limité, responsable paie. Fixtures : `audit842-m2/m3/m6-fixture.php`. Aucune donnée historique modifiée, aucun moteur RBAC / Billing / Modules touché.

## Constats traités

| Réf. | Avant | Après | Pourquoi |
|---|---|---|---|
| UXC-04 | Menu « Équipe › **Équipe** » | « Équipe › **Personnes** » (libellé seulement, manifeste inchangé) | le menu ne se répète plus |
| UXC-04 | Deux boutons voisins « Embaucher » / « Ajouter une personne » aux résultats proches | **Un** bouton « Ajouter une personne » ; s'il y a deux parcours possibles, il propose « Embauche complète — poste, contrat, dossier d'intégration » ou « Ajout rapide — fiche et, si besoin, invitation ; sans contrat » | un parcours principal, la différence expliquée au moment du choix |
| UXC-04 | Statuts « Compte activé » / « Compte connecté » / « Connecté » / « Invitation en attente » selon l'écran | un vocabulaire unique liste ↔ fiche : « Aucun accès à Leezr », « Invitation envoyée », « Invitation à accepter », « Accès prêt, jamais utilisé », « Accès actif », « Accès suspendu » | User / Membership / Employee : on lit l'état de l'**accès**, pas un terme technique |
| UXC-04 | Personne sans nom affichée par son e-mail (deux fois) | « Nom non renseigné » + e-mail | lisibilité |
| UXC-06 | Libellés anglais | l'audit avait été mené avec un propriétaire en interface **anglaise** (« New Request », « Cancel » y sont normaux) ; une seule clé manquait réellement en français — ajoutée ; un test garantit désormais qu'aucune clé anglaise ne manque en français | — |
| UXC-08 | `/onboarding` ouvert par un membre connecté : « Lien indisponible » après un refus d'API | « Lien réservé à la personne invitée » + retour à son espace | la vraie raison |
| UXC-09 | Boutons-icônes sans nom (accueil) | nommé (« Masquer le guide de démarrage ») ; mesure axe sur Accueil / Personnes : il ne reste que le bouton de fermeture du composant `@core` `AppDrawerHeaderSection` | `@core` non modifiable sans décision |
| Logistique (recette) | Après « Démarrer », la livraison disparaissait de « À faire » : le chauffeur devait changer de filtre pour la livrer | l'écran suit la livraison dans « En cours » : « Livrée » est à un geste | moins de gestes sur le terrain |

## Parcours joués de bout en bout

- **Propriétaire (Logistique)** : Accueil → Équipe › Personnes → « Ajouter une personne » → « Ajout rapide » → fiche créée (sans accès) ; vocabulaire d'accès unique ; ancien lien d'intégration.
- **Dispatcher → Chauffeur** : création d'une expédition, affectation au chauffeur, planification, recherche dans la liste ; puis le chauffeur, **sur téléphone (390 px)**, la démarre et la livre, avec un message à chaque étape ; l'annuaire lui reste refusé.
- **Manager / Salarié / Membre limité** : accueil adapté, aucun appel de facturation, pas d'action « Ajouter ».
- **Responsable paie (Beta Services)** : préparation de la paie ; **propriétaire Beta Services sur mobile** : accueil sans défilement horizontal.
- Aucune erreur JS ni refus d'API inattendu (seul refus attendu : le lien d'intégration ouvert par un membre).

Captures : `m6/apres-*.jpg` ; « avant » : `captures/` de l'audit (ex. `c1-owner__company_people.jpg`).

## Limites restantes

- Rôles système en anglais (« Driver », « Dispatcher ») : décision C4 (FUN-11) toujours en attente — non modifié.
- Bouton de fermeture des tiroirs (`@core` `AppDrawerHeaderSection`) sans nom accessible : correction possible seulement en modifiant `@core`.
- FUN-06 (export des temps / absences par période) : fonctionnalité nouvelle, non traitée dans cette mission UX.
- Workforce (planning, pointage, congés, feuilles de temps, paie, signature) : parcours vérifiés par profil sans défaut bloquant relevé dans cette mission ; aucune règle légale ni calcul modifié.
- Le libellé du « Status » de la page Modèles de documents (relevé par l'audit) n'est pas une clé manquante — non reproduit.
