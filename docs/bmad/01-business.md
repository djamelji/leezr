# 01 — Business (Besoins métier)

> Ce fichier est la source de vérité pour les besoins métier du projet Leezr.

## Problème à résoudre

Un large spectre d'entreprises — vendeurs indépendants, boutiques locales, salons de beauté, restaurants, sociétés de logistique — ont besoin d'outils de gestion adaptés à leur activité, mais les solutions existantes sont soit trop généralistes (ERP), soit trop verticales (SaaS niche impossible à étendre).

Leezr propose un **socle SaaS flexible** dont l'expérience s'adapte au métier via un système de **jobdomain** (sélecteur d'expérience), de **modules** (logique métier, incluant widgets configurables) et d'**add-ons**. Via certains modules, Leezr permet aussi aux entreprises de créer une **présence en ligne opérationnelle** (pages publiques ou mini-site), avec gestion automatisée des domaines par la plateforme.

## Modèle plateforme

### Concepts clés

| Concept | Rôle |
|---------|------|
| **Core** | Socle invariant : auth, companies, users, modules, configuration. Ne connaît aucun métier. |
| **Module** | Unité autonome de logique métier (ex : gestion de flotte, facturation, agenda). Activable par company. Inclut ses propres widgets configurables. |
| **Add-on** | Extension optionnelle d'un module. Activable par company pour enrichir un module existant. |
| **Jobdomain** | Sélecteur d'expérience. Détermine : modules par défaut, navigation, dashboard, vocabulaire. Ne porte aucune logique. |
| **Company** | Tenant. Unité d'isolation des données et de la configuration. Chaque company a un jobdomain et des modules actifs. Dispose d'un sous-domaine automatique (`company.leezr.com`) et peut rattacher un domaine personnalisé. |
| **Bulle UX** | L'expérience utilisateur résultante : des pages/presets différents selon le jobdomain, pas de logique conditionnelle dans les composants. |
| **Présence en ligne** | Module permettant aux companies de créer des pages publiques / mini-sites, servis par la plateforme sous leur domaine. |

### Invariant fondamental

> Le jobdomain **sélectionne**, il ne **calcule** pas.
> La logique métier vit dans les **modules**, jamais dans le jobdomain ni dans le core.

## Premier vertical : Logistique

### Pourquoi la logistique en premier

- Métier structuré avec des flux clairs (expéditions, tournées, suivi)
- Permet de valider : bulle UX, activation de modules, options, séparation core/modules
- Bon candidat pour tester le multi-tenant (plusieurs sociétés de transport)

### Utilisateurs cibles (vertical Logistique)

| Rôle | Description |
|------|-------------|
| Gestionnaire | Planifie, supervise, gère la flotte et les expéditions |
| Chauffeur | Consulte ses tournées, met à jour les statuts de livraison |
| Admin company | Configure la société, gère les utilisateurs |

### Cas d'usage principaux (vertical Logistique)

_À détailler lors de la phase Domain — liste indicative :_

- Gérer une flotte de véhicules
- Planifier des tournées / expéditions
- Suivre les livraisons en temps réel
- Gérer les clients et les adresses
- Facturer les prestations
- Consulter un dashboard opérationnel

### Modules pressentis (vertical Logistique)

_À valider lors de la phase Architecture :_

- `fleet` — Gestion de flotte (véhicules, maintenance)
- `dispatch` — Planification et affectation des tournées
- `tracking` — Suivi des livraisons
- `billing` — Facturation des prestations
- `contacts` — Clients, fournisseurs, adresses

## Contraintes métier

- Configuration simple pour la company, système puissant en dessous
- Stratégie de defaults : le jobdomain fournit des valeurs par défaut sensées, la company peut surcharger
- L'utilisateur ne voit que ce qu'il a besoin de toucher
- L'UI est limitée au stock Vuexy — pas d'invention
- Les widgets configurables font partie intégrante des modules (pas un système séparé)
- La gestion des domaines (sous-domaine automatique + domaine personnalisé) est automatisée par la plateforme, sans intervention technique de la company — c'est une proposition de valeur produit

## Priorités

1. Valider l'architecture core/modules/jobdomain sur le vertical Logistique
2. Livrer un premier parcours fonctionnel complet (de la création de company au dashboard)
3. Prouver la scalabilité en préparant (sans implémenter) un deuxième jobdomain

---

> **Rappel BMAD** : Aucun code métier ne doit être écrit tant que `02-domain.md` et `03-architecture.md` ne sont pas complétés et validés.
