# 00 — Contexte global

## Vision (référence produit officielle)

Leezr est une **plateforme SaaS flexible** destinée à un large spectre d'entreprises — des vendeurs indépendants et boutiques locales aux salons de beauté, restaurants et sociétés de logistique.

Chaque entreprise dispose d'un **outil de gestion adapté à son activité** (clients, commandes, employés, plannings, tâches, paiements), activé selon ses besoins via des **modules** et **add-ons**.

Via certains modules, Leezr permet également aux entreprises de créer une **présence en ligne opérationnelle** (pages publiques ou mini-site), accessible automatiquement sous un **sous-domaine dédié** (ex : `company.leezr.com`) ou via leur **propre nom de domaine**, entièrement géré et provisionné par la plateforme, **sans configuration technique** pour l'entreprise.

### Principes fondateurs
- Le **core est invariant** — il ne connaît pas les métiers
- Le **métier est porté par des modules** — autonomes et composables, incluant leurs propres widgets configurables
- Le **jobdomain est un sélecteur d'expérience** — il active des modules, configure la navigation et le vocabulaire, mais ne porte aucune logique
- La **bulle company (UX)** change selon le jobdomain — pages différentes, pas logique conditionnelle
- L'**UI est un stock fini** — exclusivement Vuexy, assemblée différemment par jobdomain
- La **présence en ligne** est un module — pages publiques / mini-sites servis par la plateforme, avec gestion automatisée des domaines
- L'**automatisation des domaines** (sous-domaine et domaine personnalisé) fait partie de la proposition de valeur produit, pas d'un détail d'infrastructure

### Stratégie de validation
Premier vertical concret : **Logistique**. Ce vertical sert à valider la séparation core/modules, l'activation par jobdomain, la bulle UX et les options de configuration avant d'ajouter d'autres métiers.

## Objectifs

- Construire un socle SaaS multi-tenant flexible, réutilisable par tout métier
- Offrir à chaque company un outil adapté à son activité via modules et jobdomain
- Permettre une présence en ligne opérationnelle avec gestion automatisée des domaines
- Appliquer la méthodologie BMAD pour garantir cohérence et traçabilité
- Maintenir une séparation stricte entre core, modules, UI et configuration
- Produire une documentation vivante dans `docs/` qui permet à n'importe quel agent de reprendre le projet
- Éviter la généricité prématurée : extraire les patterns du concret, pas de l'abstrait

## Stack

| Couche | Technologie |
|--------|-------------|
| Backend | Laravel 12 (PHP 8.3) |
| Frontend | Vue 3.5 + Vuetify 3.10 |
| Build | Vite 7 |
| Template UI | Vuexy v9.5.0 Full |
| State | Pinia 3 |
| Auth/ACL | Laravel Passport ou Sanctum (à venir) |
| Icons | Tabler via Iconify |
| Fonts | Public Sans (Google Fonts) |
| Package manager | pnpm |
| DB | MySQL |

## État actuel

- Infrastructure technique en place (Laravel 12, Vue 3.5, Vuexy, Vite)
- Inventaire UI Vuexy complet (`docs/ui/inventory.md`)
- Structure BMAD initialisée
- Audit BMAD de l'intention produit : **validé** (2026-02-11)
- Audit BMAD "Présence en ligne & Domaines" : **réalisé** (2026-02-11) — couche Public Serving documentée, ADR-012 à ADR-016 créées
- Décisions structurantes enregistrées (ADR-006 à ADR-020)
- Premier vertical choisi : **Logistique**
- Aucun code métier encore

## Prochaines étapes

1. ~~Définir les besoins métier (`01-business.md`)~~ — cadre posé, détail Logistique à compléter
2. ~~Modéliser le domaine (`02-domain.md`)~~ — LOT 1 Core SaaS documenté (Identity, Tenancy, Governance)
3. ~~Mettre à jour l'architecture technique (`03-architecture.md`)~~ — scopes Platform/Company, Sanctum, tenancy documentés
4. ~~Documenter les décisions (`04-decisions.md`)~~ — fait (ADR-006 à ADR-020)
5. **LOT 1 — Core SaaS** : implémenter le socle (auth, tenancy, governance) — EN COURS
6. Extraire les presets UI nécessaires pour le vertical Logistique
7. Implémenter le premier vertical (Logistique)
