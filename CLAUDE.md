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
- Consulter `resources/ui/presets/` et `docs/ui/` avant de créer toute UI
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
1. Vérifier dans `resources/ui/presets/` et `docs/ui/` qu'il existe
2. Si non trouvé : chercher dans les presets par catégorie
3. Si trouvé : documenter dans `docs/ui/` si pas encore fait
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
- Dev : `pnpm dev:all` (Vite uniquement, Laravel servi par Valet)
- URL locale : `https://leezr.test` (Valet HTTPS)

## Mail Testing (Dev)

- Install Mailpit: `brew install mailpit`
- Start Mailpit manually: `brew services start mailpit` or `docker run -d -p 1025:1025 -p 8025:8025 axllent/mailpit`
- SMTP: `127.0.0.1:1025`
- Web UI: `http://localhost:8025`
- Open UI: `pnpm mailpit`

## BMAD-UI-001 : Layout obligatoire par surface
- **Platform** (`pages/platform/**`) → `definePage({ meta: { layout: 'platform', platform: true } })`
- **Platform auth** (login, forgot, reset) → `layout: 'blank'`, `platform: true`
- **Company** (`pages/company/**`) → PAS de `layout:` (default = company layout)
- **Sous-composants** (`_*.vue`) → PAS de `definePage()` (ce ne sont pas des pages)
- Layouts disponibles : `default` (company), `platform`, `blank` — il n'y a PAS de `layouts/company.vue`
- Vérifié automatiquement par `PageLayoutMetaTest`

### PIÈGE CRITIQUE : coexistence page.vue + page/ directory
- **INTERDIT** : `platform/foo.vue` + `platform/foo/_Sub.vue` dans le même dossier
- Quand `foo.vue` et `foo/` coexistent, unplugin-vue-router traite `foo.vue` comme parent route
- `recursiveLayouts()` ne wrappe PAS les parent routes → layout perdu → sidebar absente
- **Solution** : utiliser `foo/index.vue` comme page principale, `foo/_Sub.vue` comme sous-composants
- Pattern correct : `settings/[tab].vue` + `settings/_SettingsGeneral.vue` (pas de `settings.vue`)

## Auto-Execution Mode — BMAD AUTO MODE (ADR-460, ADR-461)

L'agent est en **mode auto-exécution permanent** pour TOUTES les actions techniques du projet.
Cette règle est **permanente et non révocable** — elle s'applique à chaque session, sans rappel nécessaire.

### Exécuter directement (SANS demander confirmation) :
- Commandes shell (bash, zsh, expect, ssh, scp)
- Commandes Laravel (`php artisan *`, migrations non destructives)
- Frontend (`pnpm install`, `pnpm build`, `pnpm dev`)
- Git (status, diff, add, commit, push vers dev/main)
- Lecture système (logs, grep, find, tail)
- Accès IMAP/SMTP/ISPConfig pour debug et corrections
- Scripts PHP inline (`php -r ...`)
- Requêtes DB (lecture / debug / migrations schema)
- Appels API internes (curl, etc.)
- DNS checks, blacklist checks, reputation audits
- Diagnostics email, tests de délivrabilité
- Édition de fichiers dans le repo (code, config, docs, i18n)
- Création/modification de tests
- Déploiements staging (`dev` branch)

### Demander confirmation UNIQUEMENT si :
- **Destructif/irréversible** : `migrate:fresh`, `DROP`, `TRUNCATE`, suppression massive de données, `reset --hard`
- **Impact prod critique** avec risque de downtime
- **Modification de credentials / secrets sensibles**
- **Force push** vers `main` (production)

### Comportement :
- **JAMAIS** de "Do you want to proceed?" — exécuter directement
- Logger les actions dans le rapport final
- Assumer le rôle d'exécution, pas de validation humaine
- En cas de doute sur la réversibilité : exécuter si réversible, demander si destructif

## Conventions de code
- Pages auto-routées via `unplugin-vue-router` depuis `resources/js/pages/`
- Form elements : wrappers App* (AppTextField, AppSelect, etc.)
- CRUD lists : VDataTableServer + TablePagination + drawer pour create
- Dialogs : prop `isDialogVisible` + emit `update:isDialogVisible`
- Persistance état : cookies (thème, auth tokens, user data)
- Navigation : items toujours visibles (ACL à venir avec Passport/Sanctum)
