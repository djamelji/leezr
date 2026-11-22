# Leezr 5.0 — 02 · Expérience Platform cible : un cockpit de pilotage

> Inspiration : référence « V35 ». Repris :
> - l'accueil typographique (« Bon retour, *Djamel* », prénom en italique d'accent) ;
> - des KPI à mini-courbe ;
> - **un panneau sombre de supervision en direct** qui tranche avec le reste ;
> - des cartes aérées et des listes de responsables avec statut.
>
> Pas repris : l'identité médicale, les couleurs et la marque du produit de référence.
>
> Prototype : `/platform/design-lab/cockpit` — [desktop](captures/proto/platform-cockpit-desktop.jpg) · [mobile](captures/proto/platform-cockpit-mobile.jpg) · [sombre](captures/proto/platform-cockpit-desktop-dark.jpg).
> Écran actuel : [cockpit 4.2](captures/current/shots/p-superadmin/platform_dashboard_overview.jpg).

## 1. Promesse

Le propriétaire du SaaS ouvre Leezr et sait **en 5 secondes** :
1. si la plateforme va bien ;
2. si l'argent rentre ;
3. ce qu'il doit faire aujourd'hui.

Tout le reste est un clic plus loin.

## 2. Composition du cockpit (desktop, 12 colonnes)

| Zone | Contenu | Source existante | Visibilité |
|---|---|---|---|
| **Héros** (8 col.) | accueil, état en une phrase, puces « 26 sociétés actives » et date, actions « Ce mois » / « Rapport mensuel » | sociétés actives, alertes | tous |
| **Panneau en direct** (4 col., sombre, 2 rangées) | état global, onglets Base / Files / E-mail, mini-courbe, 4 mesures (planificateur, e-mails échoués, jobs en file, jobs échoués), lien Opérations | `SystemHealthService` (file d'attente, planificateur, base, disque, e-mail, alertes) | `view_system_health` |
| **3 KPI** sous le héros | MRR (tuile d'accent), sociétés actives, conversion des inscriptions | `recurringPortfolio` (MRR unique, ADR-841), sociétés, parcours mesuré (ADR-849) | MRR : `view_billing` |
| **Revenus et encaissements** (8 col.) | MRR (courbe) avec facturé / encaissé (barres) sur 12 mois ; ARR, encaissé du mois, taux d'encaissement, impayés | factures, paiements, `recurringPortfolio` | `view_billing` |
| **À traiter en priorité** (4 col.) | paiements échoués, essais sans moyen de paiement, abonnements à valider, tickets non attribués — **triés par impact**, action en ligne | relances, abonnements, support | chaque ligne filtrée par sa permission |
| **Parcours d'inscription** (4 col.) | 5 étapes en barres animées, avec volume et % | `registration_funnel_events` (ADR-849) | `view_billing` ou `manage_companies` |
| **Essais qui se terminent** (4 col.) | J-x, préparation du compte, moyen de paiement présent ou non | abonnements `trial_ends_at`, assistant de mise en service | `manage_companies` |
| **Adoption des modules** (4 col.) | % des sociétés actives qui utilisent chaque module | `company_modules` | tous |
| **Activité récente** (8 col.) | journal d'audit Platform, lisible | `platform_audit_logs` | ligne financière masquée sans `view_billing` |
| **Répartition par offre** (4 col.) | anneau et MRR par offre | `PlatformPlanDistribution` | `view_billing` |

**Permissions (démontrées dans le prototype par « Voir comme »).**
- En profil Support, toutes les briques financières **disparaissent** : MRR, revenus, répartition, paiements échoués, ligne de facture du journal.
- La grille se recompose sans trou : les KPI restants passent de 4 à 6 colonnes.
- Règle : on n'affiche jamais un indicateur financier « flouté » ou « 0 » à un profil sans droit. On ne l'affiche pas du tout.

**Mesure nouvelle, signalée comme telle.** L'**historique** de la latence de la base, et plus largement des temps de réponse de l'API, n'est pas stocké aujourd'hui : `SystemHealthService` ne donne qu'une valeur instantanée. La mini-courbe du panneau en direct demande un petit échantillonnage (une ligne par minute, purgée après 24 h). C'est noté au lot P2 de la roadmap.

## 3. Pages Platform hors cockpit

| Espace | Cible |
|---|---|
| **Entreprises** | <ul><li>Les 5 tuiles géantes deviennent **une bande de 5 KPI compacts** cliquables comme filtres. La rangée de filtres rapides en double disparaît.</li><li>La colonne slug est masquée par défaut.</li><li>« Nouvelle société » passe dans l'en-tête de page.</li><li>Ligne : santé en anneau, MRR, offre, état, **prochaine échéance** (fin d'essai, relance).</li></ul> |
| **Fiche entreprise** | <ul><li>En-tête horizontal : logo ou initiales, nom (**le nommage par l'e-mail est refusé à la création**), offre, état, MRR, santé, actions.</li><li>Synthèse en widgets (utilisation, modules, facturation, activité, mise en service).</li><li>Onglets groupés en 5 : Synthèse · Abonnement · Modules · Personnes · Journal.</li></ul> |
| **Facturation** (11 onglets) | <ul><li>Trois groupes en navigation de section (ADR-842 a déjà posé 5 groupes) : **Suivre** (vue d'ensemble, abonnements, factures et paiements), **Recouvrer** (relances, prélèvements, récupération, avoirs), **Contrôler** (rapprochement, grand livre, analyse, gouvernance, revue tarifaire).</li><li>Le titre suit l'onglet.</li><li>Le bandeau d'explication est supprimé ; les 3 alertes deviennent la carte « À traiter » de la vue d'ensemble.</li></ul> |
| **Catalogue** | <ul><li>Une seule entrée « Catalogue », avec Modules · Offres · Métiers · Champs · Documents · Préréglages en navigation de section.</li><li>Chaque objet a une fiche à en-tête homogène (même gabarit que la fiche entreprise).</li></ul> |
| **Opérations** | devient la page de détail du panneau en direct : mêmes mesures, historiques, alertes, journaux |
| **Paramètres** (12 onglets) | regroupés en 4 : Général et marque · Sécurité et sessions · International · Avancé (fonctionnalités, maintenance) |
| **Messagerie / Communications** | une boîte de réception à 3 colonnes (liste, conversation, fiche société), à la place de 7 onglets de même niveau |

## 4. Identité Platform

- **Cockpit, et non vitrine** : fond clair, cartes blanches surélevées.
- **Un seul** élément sombre (supervision en direct), comme dans V35 : il devient le repère visuel de la page.
- Accent : le primaire Leezr (`--v-theme-primary`), jamais une couleur codée en dur.
- Typographie : titres très serrés (-0,03 em), prénom de l'accueil en italique à empattement (accent éditorial), chiffres tabulaires.
- Mouvement : entrée échelonnée des cartes (60 ms d'écart), compteurs progressifs **une seule fois**, barres du parcours qui se remplissent. Tout est coupé si `prefers-reduced-motion`.

## 5. Mobile

Une seule colonne, dans l'ordre : héros, KPI (2 par ligne), panneau en direct, à traiter, puis le reste.

Le panneau en direct garde sa courbe ; les graphiques détaillés passent en mode `compact` (valeur et courbe simple).

Capture : [mobile](captures/proto/platform-cockpit-mobile.jpg).
