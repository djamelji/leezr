# Leezr 4.2 — 04 · Fonctionnalités manquantes, incomplètes ou incohérentes

> Ne figurent ici que des besoins **constatés** (navigation, parcours, routes) avec le problème concret qu'ils résolvent. Classement : **1** indispensable avant lancement · **2** amélioration importante · **3** évolution ultérieure · **4** inutile ou trop complexe à ce stade.

## Ce qui existe déjà (vérifié)

| Domaine | Existant prouvé |
|---|---|
| Cycle de vie société (Platform) | Création assistée (J1), mise en service suivie, suspension / réactivation, résiliation et annulation de résiliation de l'abonnement, onglet facturation par société |
| Abonnement (Company) | Plan et périodicité, aperçu de changement d'offre, résiliation avec aperçu, moyens de paiement, factures, paiement des impayés |
| Modules | Activation avec dépendances automatiques, statut inclus / option / hors offre |
| Mise en service (Company) | Checklist d'accueil 5 étapes (J2), invitation de l'administrateur |
| Collaborateurs | Embauche, personnes sans compte, invitations, rôles, dossier d'intégration (L6) |
| Exports | Salariés, période de paie, DSN, conformité documentaire ; Platform : factures, audit facturation, métriques, marchés, traductions |
| Supervision Platform | Santé, alertes (accusé, résolution), sécurité, usage, IA, automatisations, temps réel |
| Support | Tickets Company → Platform (J5), notes internes, documentation / centre d'aide |
| Audit | Journal Company, journaux d'accès Platform, logs entreprises |

## Manques et incohérences

| ID | Domaine | Constat | Problème concret résolu | Classe | Dépendances |
|---|---|---|---|---|---|
| FUN-01 | Cycle de vie société | Après résiliation : **aucune clôture** (archivage, purge ou anonymisation des données, délai de conservation) ni procédure documentée. | Obligation RGPD et contractuelle de restituer / supprimer les données d'un client parti ; aujourd'hui les données restent indéfiniment. | **1** (procédure documentée + export) puis **2** (outil Platform) | FUN-02, Billing |
| FUN-02 | Portabilité | Pas d'**export complet** des données d'une société (membres, salariés, documents, temps, expéditions). | Demande client « récupérer mes données » (RGPD art. 20), réversibilité exigée en B2B. | **1** (export à la demande par Platform) | FUN-01 |
| FUN-03 | Montée en gamme | Aucune incitation ni parcours depuis une fonction hors offre ou non activée (UXC-02, UXC-05). | Le client ne découvre pas les modules payants ; la page vide crée de la frustration. | **2** | Modules, Plans, Billing |
| FUN-04 | Droits des rôles existants | Les corrections de rôles (moindre privilège chauffeur, ADR-823) ne s'appliquent qu'aux **nouvelles** sociétés ; les sociétés existantes gardent les anciens droits (SEC-04). | Un chauffeur voit l'annuaire complet. | **1** | RBAC, Presets |
| FUN-05 | Changement de plan Platform | Changement direct depuis la liste sans aperçu ni confirmation (UXP-01). | Erreur commerciale silencieuse sur un client. | **1** | Billing |
| FUN-06 | Exports opérationnels | Pas d'export des **feuilles de temps / absences** (période) ni des expéditions. | Les clients dont la paie est externalisée doivent transmettre les temps et absences à leur cabinet. | **2** (temps/absences) · **3** (expéditions) | Workforce |
| FUN-07 | Assistance | Pas d'accès « assisté » de Platform à l'espace d'une société (lecture seule, consentement, audit). | Le support doit aujourd'hui deviner ce que voit le client. | **2** (lecture seule auditée, avec accord du client) | Sécurité, Audit |
| FUN-08 | Indicateurs | MRR calculé différemment selon l'écran (UXP-08). | Le propriétaire ne peut pas se fier à son chiffre principal. | **1** | Billing |
| FUN-09 | Audit | Opérations sensibles non journalisées (mot de passe d'un membre fixé par un administrateur, SEC-07). | Traçabilité en cas de litige / incident. | **1** | Sécurité |
| FUN-10 | Communication incident | Mode maintenance existant, mais pas de **page de statut** ni de message ciblé aux sociétés touchées. | Informer les clients pendant un incident. | **3** | Opérations |
| FUN-11 | Rôles traduits | Rôles système affichés en anglais (« Driver », « Dispatcher ») — décision C4 toujours en attente. | Vocabulaire incohérent pour un client français. | **2** | i18n, Presets |
| FUN-12 | Synchronisation catalogue | Un module ajouté dans le code mais non synchronisé casse sa fiche Platform (UXP-02). | Fiche vide / 404 après déploiement partiel. | **2** | Déploiement, ADR-832 |
| FUN-13 | Export du journal d'audit Company | Consultable mais non exportable. | Demande d'un auditeur ou d'un client. | **3** | Audit |
| FUN-14 | Second métier | Beta Services fonctionne comme métier de démonstration (non public). Le 2ᵉ vertical léger (ADR-564) n'existe pas encore. | Preuve de la promesse multi-vertical. | **3** (feuille de route produit, hors audit) | JobDomain, Presets |

## Jugés inutiles ou prématurés (classe 4)

| Idée | Pourquoi l'écarter maintenant |
|---|---|
| Connexion « en tant que » (impersonation) en écriture | Risque de sécurité élevé ; FUN-07 (lecture seule auditée) couvre le besoin du support. |
| Constructeur de tableaux de bord libre côté Company | L'accueil « À traiter » répond déjà au besoin ; widgets existants sous-exploités (ADR-741). |
| Marketplace de modules tiers | Un seul vertical vivant ; pas de demande. |
| Multi-JobDomain par société | Contraire à la règle « un seul JobDomain par Company ». |
