# Leezr 5.0 — 03 · Expérience Company cible : un espace de travail métier

> Inspiration : référence « V22 ». Repris :
> - un **bandeau de marque profond** porteur du message du jour, avec présence de l'équipe et anneau de progression ;
> - un calendrier en bande de jours avec l'agenda ;
> - les membres en cartes à statut ;
> - un fil de notifications à onglets ;
> - des barres arrondies pour les heures ;
> - des actions rapides en tuiles.
>
> L'autre référence (bandeau chaleureux en dégradé) a inspiré le héros et la grille d'actions.
>
> Prototype : `/company/design-lab/workspace` — [desktop](captures/proto/company-workspace-desktop.jpg) · [mobile](captures/proto/company-workspace-mobile.jpg) · [sombre](captures/proto/company-workspace-desktop-dark.jpg).
> Écran actuel : [accueil 4.2](captures/current/shots/c1-owner/home.jpg).
> Le prototype permet de basculer **Métier** (Logistique / Services), **Rôle** (Dirigeant / Manager / Chauffeur) et **Données** (société active / nouvelle).

## 1. Promesse

En arrivant, chacun voit **sa journée** : ce qui se passe maintenant, ce qui l'attend et un raccourci vers chaque action. Le dirigeant pilote son activité, le manager son équipe, le chauffeur sa tournée.

## 2. Ce qui change par rapport à l'accueil 4.2

| 4.2 | 5.0 |
|---|---|
| « Bienvenue, Djamel » en titre gris | bandeau de marque avec la phrase du jour, calculée selon le métier : « 14 livraisons prévues, 9 terminées, 2 retards » |
| le dirigeant voit d'abord **son** créneau et **son** pointage | le pointage personnel n'apparaît que pour les rôles qui pointent ; le dirigeant voit l'activité de la société |
| 3 tuiles « 0 » | 4 KPI **du métier**, chacun avec sa variation et sa mini-courbe ; une valeur nulle ne s'affiche que si elle a un sens (« 0 retard » est une bonne nouvelle) |
| « À traiter » en liste plate et 4 boutons tonaux « Actions » | **une seule grille d'actions contextualisées** avec compteurs (« Valider les feuilles de temps · 3 »), filtrée par permission |
| widgets sous la ligne de flottaison | agenda du jour à droite du héros ; widgets métier juste en dessous |
| société débutante = écran de zéros | **démarrage guidé en 3 étapes** à la place des widgets vides (bascule « Nouvelle société ») |

## 3. Composition selon le métier et le rôle

Aucun widget n'est affiché sans donnée réelle derrière ; la colonne « Source » le prouve.

### Logistique — Dirigeant / Manager

| Bloc | Source existante |
|---|---|
| Héros : livraisons du jour, part terminée (anneau), équipe en activité | `shipments`, `DriversActive` |
| KPI : livraisons du jour · en retard · chauffeurs actifs · non assignées | `ShipmentsToday`, `ShipmentsLate`, `DriversActive`, `ShipmentsUnassigned` |
| Agenda du jour (bande de jours et créneaux) | `PlanningShifts`, publications de planning |
| Actions : valider les heures, assigner, congés, relancer des documents, ajouter une personne, exporter | `TimesheetPending`, `ShipmentsUnassigned`, `LeavePending`, `CompliancePending`, export temps (ADR-844) |
| Livraisons — état du jour (anneau) | `ShipmentsOverview` |
| Heures pointées et planifiées (barres arrondies) | `HoursTrend`, `ClockLive` |
| Équipe en ce moment (présence) | pointage en cours, absences |
| Fil d'activité (Tout / Équipe / Documents) | événements existants (livraisons, pointages, documents, congés) |

### Logistique — Chauffeur

Héros « Bonne tournée » avec la prise de poste, puis :
- KPI : mes arrêts · terminés · mes heures · congés restants ;
- actions : démarrer ma tournée · pointer · demander un congé ;
- mes heures et le fil.

Pas de vision d'équipe : moindre privilège, ADR-823/840.

### Services (2ᵉ vertical, ADR-564)

Héros centré sur les présences ; KPI : présents, absents, pointages en anomalie, heures à valider.

Le prototype montre une **carte « Module proposé »** pour les rendez-vous et missions du jour. Ce widget demande le module horizontal **scheduling** (ADR-563), qui **n'existe pas** : il n'est pas simulé, et sa source et ses dépendances sont indiquées (voir 04 §5).

### Nouvelle société

Démarrage guidé en 3 étapes : ajouter l'équipe, régler le temps de travail (assistant Workforce), première opération du métier.

Chaque widget vide est remplacé par son **état vide actionnable** (« Aucun créneau planifié — Créer le planning »).

## 4. Page Personnes

Voir 01 §3 et le prototype `/company/design-lab/people` : [liste](captures/proto/company-people-desktop.jpg), [consultation rapide](captures/proto/company-people-quickview-desktop.jpg), [mobile](captures/proto/company-people-mobile.jpg).

- **Titre honnête** : « Personnes · 34 ».
- **4 indicateurs** : effectif, accès actifs, intégrations, documents manquants.
- **Segments à compteur** (Tous · À traiter · Sans accès · En intégration · Fiche incomplète), à la place des filtres par listes déroulantes.
- **Ligne utile** : poste et contrat, service, accès et rôle, complétude **par rapport aux exigences du poste**, **prochaine action** en un clic.
- **Consultation rapide** en tiroir : complétude ligne par ligne avec « Demander », accès, semaine (heures, congés). « Ouvrir la fiche » reste disponible.
- **« Outils » disparaît** : les champs se gouvernent dans Réglages › Catalogues, l'obligation par poste (05 §1).

## 5. Autres pages Company

| Page | Cible |
|---|---|
| **Planning** | <ul><li>Un seul en-tête : titre et semaine, avec « Nouveau créneau » en action principale et le reste dans « ⋯ ».</li><li>Le choix de vue (Semaine / Jour / Postes / Mois) et le choix par salariés / postes sont fusionnés en une barre.</li><li>« 41 à couvrir » devient une **bannière d'action** (« Couvrir automatiquement »).</li><li>L'équipe « Sans équipe » est mise en tête si elle contient des créneaux à couvrir.</li></ul> |
| **Modules** | <ul><li>Cartes de hauteur égale ; « Configurer » en action principale ; « Désactiver » dans « ⋯ ».</li><li>La pastille « Configuré » passe en vert avec une coche.</li><li>Section en tête : **« Recommandé pour la logistique »** (dépendances résolues, ADR-821).</li></ul> |
| **Facturation Company** | une seule page « Abonnement » : offre et prochaine échéance en en-tête, avec factures, moyens de paiement et clôture en sections ; le titre suit la section (aujourd'hui « Abonnement & factures » × 7) |
| **Documents** | « À traiter » en tête (demandes, signatures, échéances), registre en dessous ; titre par onglet |
| **Mon espace** | même bandeau que l'accueil, en version personnelle : mes documents, mes bulletins, mes signatures, ma semaine |

## 6. Identité Company

- **Plus chaleureuse que Platform** : le héros utilise le primaire Leezr en dégradé profond, des avatars d'équipe et un anneau de progression.
- Cartes plus arrondies (16–20 px pour les éléments héros), actions en tuiles.
- Même jetons, même typographie et mêmes composants que Platform (06) : **un seul produit**, deux postures.
