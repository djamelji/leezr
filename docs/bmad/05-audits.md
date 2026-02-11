# 05 — Audits

> Résultats des audits de l'existant.

---

## Audit 2026-02-10 : État initial du projet

### Infrastructure
- [x] Laravel 12 installé, key générée
- [x] MySQL `leezr` créée, 3 migrations exécutées (users, cache, jobs)
- [x] pnpm install + build scripts approuvés
- [x] Vite build réussi
- [x] `pnpm dev:all` configuré (concurrently)
- [x] Storage link créé

### Problèmes identifiés (starter kit)
| Problème | Sévérité | Statut |
|----------|----------|--------|
| Aliases `@db` et `@api-utils` pointent vers dirs inexistants | Erreur | Non corrigé |
| `themeConfig.js` titre = "vuexy" au lieu de "leezr" | Erreur | Non corrigé |
| Auth non implémentée | Warning | Passport/Sanctum à venir |
| `VITE_API_BASE_URL` vide, `/api` n'existe pas | Config | Attendu (pas d'API) |
| Login page non fonctionnel | Attendu | Pas d'auth backend |
| Pas de store Pinia pour auth/user | Attendu | Pas d'auth |
| Aucun guard de route | Attendu | Pas d'auth |
| `<meta robots noindex>` dans blade | Minor | À retirer en prod |
| MSW installé mais aucun handler | Info | Fake API pas intégrée |
| `jwt-decode` installé mais non utilisé | Info | - |
| Double implémentation HTTP (useApi + ofetch) | Info | À trancher |

### Inventaire UI Vuexy
- [x] 893 fichiers analysés
- [x] `VUEXY-UI-INVENTORY.md` créé (référence exhaustive)
- [x] 144+ pages, 526 views, 80+ composants, 20 dialogs, 45+ endpoints fake API
