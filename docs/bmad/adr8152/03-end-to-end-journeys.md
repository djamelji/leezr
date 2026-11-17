# ADR-815.2 — 03. Parcours de bout en bout

> **Environnement** :
> - clone isolé de la base DEV (`leezr_audit8152`), servi sur `http://127.0.0.1:8815` ;
> - file synchrone ; e-mails reçus dans la boîte de recette (Mailpit) ;
> - société **neuve** créée par une vraie inscription (« E2E Refonte 8152 130350 », Logistique, Starter) ;
> - personnes de recette : Olivia (propriétaire), Paul (sans accès), Claire (chauffeur-livreur avec accès), Rémi (recrue).
>
> **Mesures** (instrumentées) :
> - clics = actions de clic exécutées ;
> - saisies = champs remplis ;
> - choix = sélections dans une liste ou boutons radio ;
> - pages = URL distinctes traversées ;
> - écritures = appels API non-GET.
>
> Brut : `evidence/journeys-all-passes.json` (4 passages). Captures : `shots/journeys/jNN.jpg`.
>
> **Statuts** :
> - **REJOUÉ** : terminé au navigateur.
> - **PARTIEL** : rejoué jusqu'à un point précis.
> - **NON REJOUÉ** : la raison est donnée.
>
> Un échec de l'outil de recette (sélecteur) n'est **jamais** compté comme un défaut du produit ; seuls les blocages visibles à l'écran le sont.

## 1. Tableau de synthèse

| # | Parcours | Acteur | Statut | Clics | Saisies | Choix | Points de blocage / redondances constatés |
|---|---|---|---|---|---|---|---|
| 1 | Une entreprise s'inscrit | Futur propriétaire | REJOUÉ | 10 | 9 | 3 | Aucun ; arrivée sur `/dashboard` avec « Bienvenue sur Leezr 3/5 » |
| 2 | Configurer son activité (profil) | Propriétaire | REJOUÉ | 2 | 1 | 0 | Message hors contexte sur la page du profil (« informations à renseigner pour chaque membre ») |
| 3 | Activer un module | Propriétaire | REJOUÉ | 2 à 3 par module | 0 | 0 | Le **nom d'un module diffère** entre sa carte et la liste « Requis par » (5 modules) ; « 5 salarié inclus » |
| 3b | Configurer le module (assistant) | Propriétaire | PARTIEL | 0 | 0 | 0 | Assistant en 3 étapes affiché ; non parcouru par le script (« Conserver les accès actuels ») |
| 4 | Embaucher sur place (sans accès) | Propriétaire | REJOUÉ | 3 | 2 | 1 | Ni poste, ni contrat, ni pièce dans le tiroir : tout se fait ensuite, ailleurs |
| 5 | Intégrer à distance | Propriétaire → recrue | **PARTIEL** | 7 | 4 | 1 | Étape 1 : « Continuer » désactivé tant que la date n'est pas saisie, sans signalement d'obligation. Étape 2 : liste d'éléments à cocher. Étape 3 : finalité, **base juridique**, texte d'information, e-mail de vérification, validité. Envoi **non effectué** (base juridique non renseignée par le script) ; **portail non rejoué** |
| 6 | Créer un contrat | Propriétaire | REJOUÉ | 5 | 2 | 0 | Création puis **activation séparée** ; la fiche appelle l'API Signatures alors que le module est inactif (403) |
| 7 | Déposer une pièce | Claire | REJOUÉ | 2 | 0 | 0 | Aucun ; statut « Transmis » |
| 7b | Valider la pièce | Propriétaire | REJOUÉ | 2 | 0 | 0 | Le tableau « À traiter » affiche l'e-mail de connexion et une colonne « Rôle » (le rôle d'accès) |
| 8 | Attribuer un poste (création) | Propriétaire | REJOUÉ | 4 | 2 | 0 | « Taux horaire » dans le formulaire du poste ; l'URL de l'onglet Postes est `/organization/roles` |
| 22 | Attribuer ou changer le poste d'une personne | Propriétaire | REJOUÉ | 7 | 0 | 1 | L'onglet « Informations & emploi » a **3 formulaires et 3 boutons d'enregistrement** ; poste en double (liste + texte libre) ; aucune pièce affichée après affectation du poste |
| 9 | Donner accès au logiciel (à l'ajout) | Propriétaire → Claire | REJOUÉ | 6 | 5 | 2 | Le rôle d'accès est choisi **à l'embauche** ; après définition du mot de passe, retour à `/login` |
| 10 | Configurer des autorisations (nouveau rôle) | Propriétaire | REJOUÉ (3ᵉ passage) | 3 | 1 | 0 | Création seulement dans « Par rôle (avancé) » ; noms de modules **non traduits** (« Workforce Leave », « Theme ») |
| 11 | Organiser un planning | Propriétaire | REJOUÉ | 8 | 2 | 1 | Le module devait d'abord être activé (3 clics, 29 € HT, « facturé immédiatement ») |
| 12 | Le salarié consulte son planning | Claire | REJOUÉ | 0 | 0 | 0 | Aucun ; « mer. 18 nov. 08:00–12:00 Publié » |
| 13 | Le salarié pointe | Claire | REJOUÉ | 1 | 0 | 0 | Aucun |
| 14 | Le responsable corrige un pointage | Propriétaire | **REJOUÉ, BLOQUÉ** | 3 | 1 | 0 | La correction s'ouvre au clic sur la ligne (aucun bouton visible). **Un oubli de sortie ne peut pas être corrigé** : le serveur refuse avec un message **anglais brut** « Cannot update a time entry with status 'working'… » |
| 15 | Le salarié demande un congé | Claire | **REJOUÉ, BLOQUÉ** | 3 | 2 | 1 | « Aucun solde de congés n'est ouvert… activer votre contrat », alors que **le contrat est actif**. Le module Congés a été activé **après** le contrat, et les soldes n'ont pas été ouverts |
| 16 | Le manager valide le congé | Propriétaire | NON REJOUÉ | — | — | — | Conséquence du blocage 15 (aucune demande). Validation rejouée en ADR-811 (société 101) |
| 17 | Préparer les variables de paie | Propriétaire | NON REJOUÉ | — | — | — | Paie non activée dans la société de recette (la carte visée était en réalité celle des Congés, à cause des doubles noms) ; aucun calcul autorisé (contrainte) ; page observée sur la société 1 (`../shots/owner__workforce_payroll.jpg`) |
| 18 | Créer et assigner une expédition | Propriétaire | REJOUÉ | 5 | 2 | 1 | Assignation seulement après « Marquer comme planifiée » |
| 19 | Le chauffeur livre | Claire | REJOUÉ | 2 | 0 | 0 | Aucun |
| 20 | Consulter et régler l'abonnement | Propriétaire | REJOUÉ (consultation) | 0 | 0 | 0 | Clair ; **aucun paiement effectué** (contrainte Billing). Onglet « Aperçu consolidé V2 » |
| 21 | Consulter alertes et tâches | Propriétaire | REJOUÉ | 0 | 0 | 0 | « À traiter : anomalies de pointage, intégrations à compléter » ; notifications vides |
| 23 | Une personne quitte l'entreprise | Propriétaire | REJOUÉ | 4 | 1 | 0 | Motif obligatoire ; dossier conservé |
| 24 | Changer de responsable | Propriétaire | REJOUÉ | 6 | 0 | 1 | Seul responsable proposé : **Paul, sorti de l'entreprise** ; le propriétaire (sans fiche salarié) ne peut pas être responsable |
| 25 | Désactiver un module | Propriétaire | REJOUÉ | 3 | 0 | 0 | Impact clair ; **dates en anglais** (« December 17, 2026 ») ; nom « Documents Employés » contre « Documents RH » |
| 26 | Retirer l'accès sans supprimer le dossier RH | Propriétaire | REJOUÉ | 4 | 0 | 0 | Aucun ; fiche conservée, « Aucun compte » |
| 26b | Rendre l'accès | Propriétaire | REJOUÉ | 6 | 0 | 1 | Le compte existant reçoit une **invitation à accepter** |
| 26c | La personne se connecte avant d'accepter | Claire | REJOUÉ | 1 | 0 | 0 | **Écrans vides et réponses 400** (`/api/nav`, `/api/modules`) jusqu'à l'acceptation ; rien ne la dirige vers `/invitations` |

**Couverture** : 26 parcours demandés.
- **REJOUÉS** : 23 (1–4, 6–15, 18–26). Le 20 est en consultation seule, par contrainte (aucun paiement). Les 14 et 15 vont jusqu'à un blocage produit.
- **PARTIEL** : 1 (le 5, jusqu'à l'écran de choix juridiques ; envoi et portail non rejoués).
- **NON REJOUÉS** : 2 (16, 17), avec leur raison.

Sous-parcours supplémentaires : 3b (assistant de module, partiel), 7b, 26b et 26c.

## 2. Détail par parcours : actuel → idéal → gain

> Les valeurs « idéal » sont des **estimations de conception** [C] pour le parcours cible de `06`. Ce ne sont pas des mesures.

| # | Point d'entrée et prérequis | Étapes actuelles (pages) | Changements de contexte | Résultat métier | Parcours idéal | Gain estimé |
|---|---|---|---|---|---|---|
| 1 | `/register` | Métier → offre → modules → entreprise → compte → résumé → `/dashboard` | 0 | Société et propriétaire créés | Inchangé ; checklist « Démarrage » sur l'accueil | — |
| 2 | Réglages | Réglages → Profil → enregistrer | 1 | Profil complété | Depuis la checklist Démarrage, en un clic | −1 page |
| 3 | Modules | Modules → carte → dialogue (devis) → assistant | 1 | Module actif | Un nom par module ; assistant enchaîné | Fin des erreurs de lecture des dépendances |
| 4 + 6 + 8/22 + 9 | Équipe | Ajouter (3–6 clics) → fiche → contrat (5) → activer → Informations → Modifier → poste (7) → accès (choisi à l'ajout) | 4 | Salarié avec poste, contrat et accès | **Embaucher** : Personne → Poste → Mode → Dossier → Contrat (prérempli) → Finaliser | ≈ 21 clics et 4 écrans → ≈ 10 clics, 1 assistant [C] |
| 5 | Équipe | Inviter une recrue (3 étapes, 7 clics, 4 saisies, choix juridiques) → e-mail → portail → revue | 2 | Dossier reçu | Même assistant, mode « lien » ; finalité et base légale préréglées | −5 décisions par dossier |
| 7 / 7b | Mon espace / Documents | Dépôt 2 clics ; validation 2 clics | 1 | Pièce approuvée | Inchangé ; la file À traiter inclut aussi les dépôts d'intégration | Une seule file |
| 10 | Équipe › Rôles | Par capacité → Par rôle (avancé) → Ajouter un rôle | 1 | Rôle créé | Réglages › Accès › « Nouveau rôle » visible ; périmètre affiché | Découvrabilité |
| 11 | Planning | Activer le module (3 clics) → Planning → Nouveau créneau → Publier | 1 | Créneau publié | Inchangé ; indiquer d'avance qui est planifiable (contrat actif) | Moins d'essais-erreurs |
| 12 / 13 / 19 | Accueil / Mon activité | 0 à 2 clics | 0 | Consulté / pointé / livré | Inchangé (bon) | — |
| 14 | Pointages | Clic sur la ligne → « Corriger le pointage » → **refus** si oubli de sortie | 1 | **Non atteint** | Action « Clôturer / corriger » sur une entrée en cours, avec motif ; message traduit | Débloque le cas le plus fréquent |
| 15 / 16 | Mon activité / Absences | Demande impossible (pas de solde) | — | **Non atteint** | Ouvrir les soldes à l'activation du module pour les contrats actifs (ou explication exacte) | Débloque les congés |
| 18 | Expéditions | Créer → planifier → assigner | 0 | Expédition assignée | Assignation facultative dès la création | −2 clics |
| 23 | Fiche | Actions → Terminer la collaboration → motif | 0 | Salarié sorti | Inchangé ; exclure les sortis des listes de responsables | Cohérence |
| 24 | Fiche | Informations → Modifier → Responsable | 0 | Responsable changé | Champ « Responsable » dans Emploi & contrat ; actifs uniquement | — |
| 25 | Modules | Carte → Désactiver → impact → confirmer | 0 | Module désactivé | Dates et noms traduits | — |
| 26 / 26b / 26c | Fiche › Accès | Retirer (4) ; rendre (6) ; la personne se connecte : écrans vides jusqu'à l'acceptation | 1 | Accès retiré puis rendu | À la connexion : « Une invitation vous attend » avant tout écran | Fin de l'impasse |

## 3. Données saisies plusieurs fois (constat)

- **Prénom, nom** : tiroir d'ajout, formulaire 2 de la fiche, dossier d'intégration [R].
- **Date d'entrée** : tiroir, « Date d'embauche » des informations complémentaires, date d'arrivée du dossier, date de début du contrat [R].
- **Poste** : liste du formulaire 2, « Intitulé de poste » libre, poste du dossier d'intégration [R].
- **Type de contrat** : champ des informations complémentaires et module Contrats [R].
