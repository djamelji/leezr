# Leezr 4.2 — 07 · Expérience cible Platform & Company

> Principe directeur : **pas de seconde architecture.** Le modèle actuel est sain — menus construits depuis les manifestes de modules, Plan = droits commerciaux, permissions = autorisations opérationnelles, un JobDomain par société, dépendances automatiques entre modules, personnalisations Platform protégées par ADR-832. L'audit montre que les défauts viennent de **règles appliquées à moitié** (widgets qui ignorent les permissions, pages de module qui ignorent l'activation, actions commerciales sans aperçu), pas du modèle. La cible consiste à **généraliser trois règles** et à **ranger** l'existant.

## 1. Trois règles transverses à généraliser

| Règle | Énoncé | Corrige | Où elle vit |
|---|---|---|---|
| **R1 — Ce qu'on voit = ce qu'on peut** | Un menu, un onglet, un widget ou un bouton déclare la permission (et le module) dont il dépend ; sans elle, il n'est pas rendu. Une adresse saisie sans droit affiche une page de refus explicite (Platform comme Company). | UXP-03, UXP-04, SEC-08, UXC-07 | Manifestes (nav) → même déclaration pour onglets et widgets (`requires: { permission, module }`) |
| **R2 — Une fonction indisponible s'explique** | Quatre états distincts, un seul composant : *permission manquante* (« demandez à … »), *module non activé* (« Activer » si `modules.manage`), *hors offre* (« Disponible avec Pro » + aperçu), *donnée absente* (état vide avec action). | UXC-02, UXC-05, FUN-03 | Garde de route de module + `EntitlementResolver` (déjà la source des statuts inclus/option/hors offre) |
| **R3 — Une décision commerciale passe par un aperçu** | Changer de plan, de périodicité, accorder une dérogation, résilier : toujours aperçu chiffré → confirmation → journal. Jamais depuis une liste. | UXP-01, FUN-05 | Fiche société › Facturation (Platform), Abonnement (Company), `plan-change-preview` existant |

## 2. Qui porte quoi (responsabilités)

| Objet | Défini dans | Personnalisable par | Jamais par |
|---|---|---|---|
| Champs, types de documents (définition, contraintes légales) | **Catalogue global** (Platform) | JobDomain (affichage, obligation, marchés, valeur par défaut — ADR-835) ; société (ses propres champs) | Code en dur (une fois l'ADR-835 généralisée) |
| Composition d'un métier (modules de départ, rôles, presets, champs) | **JobDomain** | Société à l'initialisation (choix proposés, jamais imposés) | Plan |
| Droits commerciaux (quels modules, quotas, essai) | **Plan** | Dérogation commerciale tracée (ADR-834) | JobDomain, rôle |
| Dépendances fonctionnelles | **Module** (manifeste) | — | Plan, JobDomain |
| Autorisations opérationnelles | **Rôle / permissions** de la société | Société (rôles personnalisés) | Plan |
| Marchés (TVA, devise, formats, champs légaux) | **Marché** (Platform) | — | `if('FR')` dans le code |

## 3. Menus cibles

### Platform (6 groupes, entrées existantes regroupées — aucune page nouvelle)

| Groupe | Entrées | Changement |
|---|---|---|
| **Cockpit** | Tableau de bord · Messagerie | Widgets soumis à R1 ; titre obligatoire par widget |
| **Clients** | Entreprises (liste, fiche, nouvelle société, utilisateurs, journaux) · Communications (support, documentation) | Plan modifiable seulement depuis la fiche (R3) |
| **Finances** | Facturation — 3 sous-groupes : *Quotidien* · *Comptabilité* · *Contrôle* | 15 onglets → 3 groupes (UXP-05) |
| **Catalogue** | Modules · Métiers · Presets · Champs · Documents · **Modèles de contrat** · Plans · **Marchés** | Tout ce qui se publie vers les sociétés au même endroit (UXP-13) |
| **Accès** | Utilisateurs Platform · Rôles · Journaux d'accès | inchangé |
| **Système** | Paramètres · Opérations | inchangé |

### Company (groupes déclarés par les modules — inchangés dans leur principe)

| Groupe | Entrées | Changement |
|---|---|---|
| Accueil | À traiter, actions rapides, vue d'ensemble | modèle de référence pour Platform |
| Équipe | Personnes (une action « Ajouter » : *embauche complète* / *accès seulement*), Embauches, Contrats, Rôles | supprimer le doublon « Équipe › Équipe » (UXC-04) |
| Planning & temps | Planning, pointage, feuilles de temps, congés | export période temps/absences (FUN-06) |
| Documents · Paie · Expéditions | inchangés | — |
| Modules | catalogue avec les 4 états de R2 et la montée en gamme | UXC-02/03/05 |
| Réglages | rangés par sujet (déjà bien fait) | lecture seule explicite quand le droit d'écriture manque |
| Mon espace / Mon travail | inchangés (bon niveau) | — |

## 4. Écrans à fusionner / à garder distincts

| Fusionner ou ranger | Garder distinct |
|---|---|
| Panneau gauche + carte « L'entreprise » de la fiche Platform (UXP-06) | Création assistée (Platform) ≠ inscription publique : même moteur, deux parcours |
| « Embaucher » + « Ajouter une personne » en une action à deux choix | Employé (fiche RH) ≠ compte utilisateur (accès) — séparation à conserver |
| Les 3 MRR en un indicateur (UXP-08) | Journal d'audit Company ≠ journaux Platform |
| Marchés et Modèles de contrat dans Catalogue | Opérations (santé, incidents) ≠ Paramètres (configuration) |

## 5. Dépendances entre changements (à respecter dans la roadmap)

```
R1 (permissions visibles) ──► widgets Platform, onglets Facturation, réglages Company
R2 (fonction indisponible) ──► garde de route de module ──► montée en gamme (dépend de R3)
R3 (décisions commerciales) ──► fiche société Platform ──► retrait du sélecteur de plan de la liste
ADR-835 (champs) ──► onboarding / inscription / assistant Platform (déjà alignés)
Rôles existants (SEC-04 / FUN-04) ──► presets RBAC ──► nav Company (le menu suit automatiquement)
MRR unique ──► tableau de bord, Facturation, Entreprises
```

Toute modification de module (manifeste) se répercute **automatiquement** sur les menus, les plans et les permissions : c'est la force de l'architecture actuelle, à préserver.
