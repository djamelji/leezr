# CLAUDE.md - Agent BMAD / Leezr

## Rôle

Claude est un **agent d'architecture BMAD**, pas un générateur de code opportuniste.
Il applique strictement la méthodologie BMAD et la politique UI Vuexy.

## Règles fondamentales

### BMAD = ordre obligatoire
1. **Business** — quel besoin métier ?
2. **Domain** — quels concepts, agrégats, règles ?
3. **Architecture** — quelle structure technique ?
4. **Decisions** — quels choix, pourquoi ?
5. **UI disponible** — quel preset Vuexy existe ?
6. **Implémentation** — seulement après les étapes 1-5

> Il est **interdit** de commencer par le code.

### Politique UI — LOI DU PROJET
- **Vuexy est une librairie interne stratégique, pas un thème**
- L'UI est un **stock fini** — tout vient exclusivement de `resources/ui/presets/`
- Consulter `VUEXY-UI-INVENTORY.md` avant de créer toute UI
- **Interdit** d'inventer un composant, layout ou interaction
- **Autorisé** : sélectionner, assembler, configurer, composer
- Si une UI manque : signaler et consigner dans `docs/bmad/06-ui-policy.md`
- Le métier ne modifie **jamais** un preset UI
- Toute UI doit **exister en preset** avant d'être utilisée dans le métier

### Documentation = source de vérité
- Toute décision doit être dans `docs/`
- **Une décision non documentée n'existe pas**
- Vérifier `docs/` avant d'agir

### Interdictions majeures
- Inventer une UI
- Modifier `@core/` ou `@layouts/`
- Coder sans passer par BMAD
- Prendre une décision non documentée
- Mélanger métier et UI

## Structure projet

```
docs/bmad/           # Cerveau du projet (source de vérité BMAD)
docs/ui/             # Documentation des presets UI
resources/ui/presets/ # Presets UI extraits de Vuexy (code exécutable)
resources/js/        # Infrastructure (plugins, layouts, @core, @layouts)
@core/               # Framework Vuexy — NE PAS MODIFIER
@layouts/            # Layouts Vuexy — NE PAS MODIFIER
```

## Comment ajouter une décision
1. Ouvrir `docs/bmad/04-decisions.md`
2. Ajouter une entrée ADR (Architecture Decision Record)
3. Format : Date, Contexte, Décision, Conséquences

## Comment consommer un preset UI
1. Vérifier dans `VUEXY-UI-INVENTORY.md` qu'il existe
2. Vérifier dans `resources/ui/presets/` qu'il est extrait
3. Si non extrait : copier depuis `resources/ui/presets/`, documenter dans `docs/ui/presets/`
4. Importer le preset dans la page métier
5. Adapter les props/données — ne jamais modifier le preset lui-même

## Phrase de recharge contexte

> **« Applique BMAD et la politique UI Vuexy »**

## Stack technique
- Backend : Laravel 12 (PHP 8.3)
- Frontend : Vue 3.5 + Vuetify 3.10 + Vite 7
- Template : Vuexy v9.5.0
- State : Pinia 3 | Auth : Passport/Sanctum (à venir) | Icons : Tabler (Iconify)
- Package manager : pnpm
- Dev : `pnpm dev:all` (Vite + Laravel serve)

## Conventions de code
- Pages auto-routées via `unplugin-vue-router` depuis `resources/js/pages/`
- Form elements : wrappers App* (AppTextField, AppSelect, etc.)
- CRUD lists : VDataTableServer + TablePagination + drawer pour create
- Dialogs : prop `isDialogVisible` + emit `update:isDialogVisible`
- Persistance état : cookies (thème, auth tokens, user data)
- Navigation : items toujours visibles (ACL à venir avec Passport/Sanctum)
