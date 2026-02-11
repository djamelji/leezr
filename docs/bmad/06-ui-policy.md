# 06 — Politique UI (LOI DU PROJET)

> Ce document est **non négociable**. Il définit les règles absolues de gestion de l'UI.

---

## Principe fondamental

**L'UI est un stock fini. Toute UI provient exclusivement de Vuexy.**

## Source

- Source unique : `resources/ui/presets/` et `resources/js/` (extrait de Vuexy Full v9.5.0)
- Inventaire : `docs/ui/inventory.md` (vue globale) + `resources/ui/presets/` (code)
- Presets extraits : `resources/ui/presets/`

## Règles

### Ce qui est AUTORISÉ
- Sélectionner un composant/page/layout depuis `resources/ui/presets/`
- Assembler plusieurs presets existants ensemble
- Configurer un preset via ses props/slots
- Composer une page à partir de presets

### Ce qui est INTERDIT
- Inventer un composant UI
- Créer un layout inédit
- Designer une nouvelle interaction
- Modifier `@core/`, `@layouts/`
- Modifier un preset extrait (le fork est interdit)
- Mélanger logique métier dans un preset

## Workflow d'extraction d'un preset

1. Identifier le besoin UI
2. Chercher dans `docs/ui/inventory.md` et `resources/ui/presets/`
3. Localiser le composant dans `resources/ui/presets/{catégorie}/`
4. Renommer si nécessaire (suffixe `.preset.vue` optionnel)
5. Documenter dans `docs/ui/presets/{catégorie}.md`
6. Le preset est maintenant disponible pour le métier

## Si un composant UI manque

1. Vérifier qu'il n'existe pas sous un autre nom dans l'inventaire
2. Vérifier si un assemblage de presets existants peut couvrir le besoin
3. Si réellement manquant :
   - Consigner ci-dessous dans le registre des manques
   - Proposer une solution d'assemblage
   - Ne JAMAIS inventer

## Registre des manques UI

| Date | Besoin | Existe dans Vuexy ? | Solution proposée | Statut |
|------|--------|---------------------|-------------------|--------|
| 2026-02-11 | Page profil entreprise (mini-site company) | Non — `front/` = landing page SaaS uniquement | À définir après ADR-015 (choix rendering) | En attente |
| 2026-02-11 | Catalogue de services | Non | À définir après ADR-015 | En attente |
| 2026-02-11 | Page contact avec formulaire personnalisé | Partiel — `front/contact-us` = formulaire SaaS | À définir après ADR-015 | En attente |
| 2026-02-11 | Galerie / portfolio | Non | À définir après ADR-015 | En attente |
| 2026-02-11 | Carte et localisation | Non | À définir après ADR-015 | En attente |
| 2026-02-11 | Section horaires / disponibilités | Non | À définir après ADR-015 | En attente |

> **Note (2026-02-11)** : Les presets `front/` (11 fichiers) couvrent la landing page Leezr.com, pas les mini-sites company. La question est plus fondamentale que des manques ponctuels : si ADR-015 tranche en faveur de Laravel Blade, les mini-sites company n'utiliseront pas de presets Vue du tout. Ce registre sera réévalué après ADR-015.

## Structure des presets

```
resources/ui/presets/
├── atoms/        # Inputs, boutons, indicateurs (AppTextField, IconBtn...)
├── molecules/    # Groupes composés (TablePagination, AppStepper, TiptapEditor...)
├── organisms/    # Sections complexes (Notifications, TheCustomizer...)
├── dialogs/      # Modals (ConfirmDialog, UserInfoEditDialog...)
├── tables/       # Patterns tables CRUD
├── forms/        # Patterns formulaires
├── cards/        # Presets cards (stats, advanced, widgets)
├── dashboards/   # Widgets dashboard standalone
├── layouts/      # Composants layout (Navbar, Footer, Sidebar)
├── auth/         # Pages/composants authentification
├── pages/        # Presets pages complètes
├── charts/       # Composants graphiques
├── apps/         # Views spécifiques apps (ecommerce, invoice, user...)
├── navigation/   # Composants navigation
├── wizards/      # Patterns wizard multi-step
└── front/        # Sections front/marketing
```

## Convention de nommage

- Nom descriptif du rôle UI : `UserCrudTable.vue`, `ConfirmDeleteDialog.vue`
- Pas de préfixe métier dans un preset (ex: pas de `LeezrUserTable.vue`)
- Le nom décrit la fonction UI, pas le contexte métier
