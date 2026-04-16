# LEEZR V2 — FRONTEND & PRODUIT CONCRET

> Scope : company scope uniquement (/company/...)
> Mode : Lead Frontend SaaS + Product Designer
> Pas de marketing. Pas de vision. Du concret.

---

# 1. AUDIT DES PAGES EXISTANTES

## 1.1 Home / Dashboard (`/company/home`)

**Ce qui existe** :
- Surface engine avec grid de widgets
- Smart layout auto-build (catégorise KPI/list/banner)
- Store `useOperationalHomeStore` wrapping `createSurfaceEngine()`

**Problèmes UX** :
| # | Problème | Impact |
|---|----------|--------|
| 1 | **Pas d'onboarding** — l'utilisateur arrive sur un dashboard vide | L'utilisateur ne sait pas quoi faire |
| 2 | **Pas de suggestions contextuelles** — aucune aide au premier lancement | Perte immédiate d'engagement |
| 3 | **Pas de score/KPI clair** — widgets génériques sans hiérarchie visuelle | L'utilisateur ne voit pas sa valeur |
| 4 | **Pas d'actions rapides** — le dashboard est passif, lecture seule | Pas de raccourci vers les tâches urgentes |
| 5 | **Auto-save layout silencieux** — layout sauvegardé avant interaction user | Confusant si le user n'a rien personnalisé |

**V2 attendue** :
- Onboarding checklist (comme section 6 du parcours produit)
- Section "Actions requises" en haut (docs expirants, relances en attente, tickets ouverts)
- KPI bar avec compliance %, membres actifs, docs en attente
- Empty state éducatif si aucun widget configuré

---

## 1.2 Members (`/company/members`)

**Ce qui existe** :
- Liste de membres avec VDataTableServer
- Drawer pour ajouter un membre (invitation par email)
- Actions : modifier rôle, supprimer, réinviter
- Permissions gérées par `hasPermission('members.manage')`

**Problèmes UX** :
| # | Problème | Impact |
|---|----------|--------|
| 1 | **Zero loading state dans le store** — le store ne track pas le loading | La page gère son propre isLoading, incohérent |
| 2 | **Pas de try/catch dans le store** — erreurs propagées brutes | Le composant doit tout wrapper lui-même |
| 3 | **_memberCount++ manuel** — dérive si mutation échoue | Compteur faux affiché |
| 4 | **Pas de SSE** — ajout/suppression d'un membre pas visible en temps réel | Multi-tab désynchronisé |
| 5 | **Pas de bulk actions** — ajout/suppression un par un | 50 membres = 50 clics |
| 6 | **Pas de recherche** — aucun champ search dans la liste | Inutilisable à 50+ membres |
| 7 | **Pas de filtre par rôle** — tous mélangés | Impossible de voir "tous les admins" |
| 8 | **Pas d'historique d'actions** — qui a invité qui, quand | Pas d'audit trail visible |
| 9 | **Drawer ajout basique** — juste nom/email/rôle | Pas de poste, pas de documents requis associés |
| 10 | **Pas de vue profil membre** — pas de page /members/[id] | Impossible de voir les détails d'un membre |

**V2 attendue** :
- Search bar + filtre par rôle en haut de table
- Bulk invite (CSV import ou multi-add)
- Vue profil membre avec : infos, rôle, documents, activité
- SSE pour les changements de membership
- Loading states dans le store

---

## 1.3 Documents — Requests (`/company/documents/requests`)

**Ce qui existe** :
- Liste de demandes de documents (VDataTableServer)
- Filtres par statut (computed)
- Upload/download actions
- AI analysis avec polling fallback (ADR-431b)
- Smart merge dans le store (`_mergeRequests()`) — JSON.stringify compare
- Realtime SSE intégré via `useRealtimeSubscription`
- Bulk action backend existante (approve/reject)

**Problèmes UX** :
| # | Problème | Impact |
|---|----------|--------|
| 1 | **AI silencieuse 30s** — aucun feedback pendant l'analyse AI | L'utilisateur croit que rien ne se passe |
| 2 | **Pas d'error state dans le store** — `catch { this._data = [] }` | Impossible de distinguer "pas de docs" de "erreur serveur" |
| 3 | **Polling silencieux overwrite SSE** — `fetchRequests({silent:true})` chaque 5s | Race condition : SSE push → polling écrase |
| 4 | **Bulk actions non connectées au frontend** — backend existe, UI absente | L'admin approuve/rejette un par un |
| 5 | **Pas de vue document détaillée** — pas de page /documents/[id] | Pas de détail AI, historique de review, timeline |
| 6 | **Pas de retry mechanism** — fetch fail = données vidées | Utilisateur doit refresh manuellement |
| 7 | **Timeline AI invisible** — on ne voit pas les étapes (upload → MRZ → Vision → OCR) | L'utilisateur ne comprend pas le processus |
| 8 | **Compliance data overwrite** — destructuring brutal | Blink si plusieurs fetches simultanés |

**V2 attendue** :
- Progress bar AI avec étapes visibles (upload → analyse → extraction → validation)
- Bulk toolbar (checkbox → approuver/rejeter sélection)
- Vue document detail avec : image, données AI, confiance, historique, timeline
- Error state distingué de empty state
- Retry button sur erreur
- Skeleton loaders au lieu de spinner plein

---

## 1.4 Documents — Overview / Vault / Compliance

**Ce qui existe** :
- Vue vault (documents par type)
- Card grid avec card-grid-sm
- Compliance dashboard avec score %
- Activity feed
- Document settings (types actifs, auto-review threshold)

**Problèmes UX** :
| # | Problème | Impact |
|---|----------|--------|
| 1 | **Compliance widget stale si erreur** — montre vieille data | Faux score compliance |
| 2 | **Pas de refresh button** — utilisateur ne peut pas retry | Doit refresh la page |
| 3 | **Activity feed limité** — pas de "voir plus", pas de filtres | Limité aux 10 dernières |
| 4 | **Settings UI basique** — on/off toggle sans explication | L'admin ne comprend pas l'impact d'un setting |

**V2 attendue** :
- Compliance score avec breakdown par type de document
- Activity feed paginé avec filtres (par membre, par type, par action)
- Settings avec explanations inline + preview d'impact
- Export compliance PDF en 1 clic (backend existe déjà)

---

## 1.5 Shipments (`/company/shipments`)

**Ce qui existe** :
- Liste paginée VDataTableServer
- Filtres : statut, recherche par référence, assigné à
- Actions : voir détail, changer statut
- Store avec `_loaded` flag unique

**Problèmes UX** :
| # | Problème | Impact |
|---|----------|--------|
| 1 | **Zero loading state** — ni dans le store ni granulaire | UI bloque sans feedback |
| 2 | **Zero error handling** — pas de try/catch | Erreur silencieuse |
| 3 | **`_shipments` et `_currentShipment` driftent** — mis à jour séparément | Données incohérentes entre liste et détail |
| 4 | **Pas de SSE** — changement de statut invisible en temps réel | Multi-utilisateur cassé |
| 5 | **Pas de bulk status change** | Opérations laborieuses |
| 6 | **Pas de vue timeline** — historique des changements de statut absent | Pas de traçabilité |

**V2 attendue** :
- Loading states granulaires (list, detail, statusChange)
- Error handling dans le store + ErrorState dans la page
- SSE pour shipment.updated / shipment.status_changed
- Timeline de statut sur la page détail
- Bulk status change toolbar

---

## 1.6 Deliveries (`/company/deliveries`)

**Ce qui existe** :
- Identique au pattern shipments
- Vue livreur (filtrée sur l'utilisateur assigné)

**Problèmes** : Mêmes que shipments — copié-collé du pattern avec les mêmes lacunes.

---

## 1.7 Support / Tickets (`/company/support`)

**Ce qui existe** :
- Liste de tickets avec statut/priorité
- Page détail avec chat (messages)
- Send message avec Enter
- Status chips avec couleur par statut

**Problèmes UX** :
| # | Problème | Impact |
|---|----------|--------|
| 1 | **fetchMessages() sans loading** — pas de flag | Messages apparaissent d'un coup |
| 2 | **Pas d'optimistic send** — message visible seulement après API response | Chat sent lent (500ms+) |
| 3 | **Pas de SSE** — nouveaux messages ne poussent pas en temps réel | L'utilisateur doit refresh pour voir les réponses |
| 4 | **Pas de typing indicator** | UX chat basique |
| 5 | **Erreurs silencieuses** — sendMessage échoue sans feedback | L'utilisateur croit que son message est parti |
| 6 | **Pas de search/filter** sur la liste de tickets | Inutilisable à 50+ tickets |
| 7 | **Chat fermé non éditable** — correct mais pas de bouton "réouvrir" | L'utilisateur est bloqué |

**V2 attendue** :
- Optimistic message send (apparaît immédiatement, grisé si pending)
- SSE pour ticket messages + status changes
- Search + filter (statut, priorité, catégorie) sur la liste
- Bouton "réouvrir ticket" quand resolved
- Skeleton loader sur les messages

---

## 1.8 Billing (`/company/billing/[tab]`)

**Ce qui existe** :
- Tabs : overview, invoices, payment-methods
- Overview : plan actuel, usage, prochaine facture
- Invoices : liste paginée
- Payment methods : cartes/SEPA avec add/remove/default
- Dialogs : upgrade plan, add payment method

**Problèmes UX** :
| # | Problème | Impact |
|---|----------|--------|
| 1 | **Zero loading state** — aucun flag dans le store | Boutons ne se disable pas au clic |
| 2 | **Zero error handling** — pas de try/catch | Double-clic = double charge |
| 3 | **Card state inconsistency** — `setDefaultCard` ne valide pas si mutation échoue | Default flag incorrect |
| 4 | **Pas de preview de prochaine facture après changement** — stale après plan change | Montant affiché incorrect |
| 5 | **Pas d'idempotency keys** — retry = double exécution | Risque de double paiement |
| 6 | **Pas de SSE** — changements billing sur autre device invisibles | |
| 7 | **setupIntent flow fragile** — pas de rollback si confirm échoue | Carte ajoutée partiellement |

**V2 attendue** :
- Loading states sur chaque action (change plan, add card, set default)
- Error handling avec retry
- Idempotency keys sur les mutations financières
- Preview facture auto-refresh après chaque changement
- Confirmation dialog avant toute action financière

---

## 1.9 Settings (`/company/settings/[tab]`)

**Ce qui existe** :
- Tabs : overview (profil company), roles, permissions
- Profil : nom, adresses (company + billing), champs dynamiques
- Roles : CRUD avec drawer
- DynamicFormRenderer pour les champs serveur-driven

**Problèmes UX** :
| # | Problème | Impact |
|---|----------|--------|
| 1 | **Silent 403 via `$guardedApi`** — permission refusée = rien ne s'affiche | L'utilisateur ne sait pas pourquoi c'est vide |
| 2 | **Pas de dirty check** — pas de "Unsaved changes" warning | Perte de données si navigation |
| 3 | **Nav cache pas invalidé après settings change** — sidebar stale | Menu incohérent jusqu'au refresh |
| 4 | **Pas de feedback intermédiaire** — save réussit ou échoue, pas de progression | |

**V2 attendue** :
- Dirty check avec "Vous avez des modifications non sauvegardées"
- Feedback explicite quand permission refusée (pas de silence)
- Invalidation nav cache immédiate après role/permission change
- Skeleton loaders sur le chargement initial

---

## 1.10 Profile (`/company/profile/[tab]`)

**Ce qui existe** :
- Tabs : account, security, notifications
- Account : infos utilisateur, avatar
- Security : password change, 2FA
- Notifications : préférences

**Problèmes UX** :
| # | Problème | Impact |
|---|----------|--------|
| 1 | **Pas de dirty check** | Perte de données |
| 2 | **Avatar upload sans preview** | UX basique |

---

## 1.11 Audit Trail (`/company/audit`)

**Ce qui existe** :
- Table d'événements d'audit
- Pagination basique

**Problèmes UX** :
| # | Problème | Impact |
|---|----------|--------|
| 1 | **Zero loading state** — table blink | |
| 2 | **Zero error handling** | |
| 3 | **Pas de filtres** — pas de filtre par action, par utilisateur, par date | Inutilisable |
| 4 | **Pas d'export** | Pas de traçabilité exportable |
| 5 | **Pas de détail expandable** — juste une ligne de log | Pas assez d'info |

**V2 attendue** :
- Filtres : type d'action, utilisateur, date range
- Expandable rows avec détail JSON
- Export CSV
- Loading + error states

---

# 2. TROUS FONCTIONNELS DU SAAS

## 2.1 Trous critiques (bloquent l'utilisation)

| # | Gap | Description | Impact |
|---|-----|-------------|--------|
| 1 | **Pas d'onboarding** | Aucun parcours guidé post-signup. Dashboard vide. | L'utilisateur est perdu dès le jour 1 |
| 2 | **Pas de dashboard utile** | Widgets génériques, pas de KPI métier, pas d'actions urgentes | Aucune raison de revenir sur le dashboard |
| 3 | **Pas de vue profil membre** | On ne peut pas voir le détail d'un membre (docs, rôle, activité) | Impossible de gérer un individu |
| 4 | **Pas de vue document detail** | Pas de page dédiée document avec AI data, timeline, historique | Impossible de comprendre l'état d'un document |
| 5 | **Pas de search global** | NavSearchBar existe mais non connectée à des résultats | Impossible de trouver un membre, un doc, un ticket |
| 6 | **Error states absents partout** | 9/14 stores n'ont pas d'error state. Pages montrent du vide. | L'utilisateur pense "pas de données" au lieu de "erreur" |
| 7 | **Loading states absents dans 9/14 stores** | Pages doivent gérer leur propre loading externement | Inconsistant, certaines pages oublient |

## 2.2 Trous importants (dégradent l'expérience)

| # | Gap | Description | Impact |
|---|-----|-------------|--------|
| 8 | **Pas de bulk actions** | Tables sans multi-select. Tout se fait un par un. | 50 docs à approuver = 50 clics |
| 9 | **Pas de filtres avancés** | Members: pas de filtre. Audit: pas de filtre. Support: basique. | Listes inutilisables > 30 items |
| 10 | **Pas de breadcrumbs** | Navigation nested (detail→liste) sans fil d'Ariane | L'utilisateur se perd |
| 11 | **Pas de dirty check (formulaires)** | Naviguer pendant un edit = perte silencieuse | Frustration utilisateur |
| 12 | **Pas d'historique/timeline** | Shipments, documents, tickets : pas de timeline de changements | Pas de traçabilité |
| 13 | **Pas de skeleton loaders** | VSkeletonLoader utilisé dans ~12 pages sur 40+ | Layout shift, flash de contenu vide |
| 14 | **SSE intégré dans 1 seul module** | Seul documents utilise le realtime SSE. Le reste est statique. | Multi-device/multi-user cassé |
| 15 | **Toast limité** | useAppToast = 3 couleurs, pas de severity (info/success/warning/error) | Feedback visuel pauvre |
| 16 | **Pas de permissions fines UX** | Admin peut tout. Pas de scope par module dans l'UI. | Pas de delegation possible |
| 17 | **Pas d'export sur les listes** | Audit: pas d'export. Members: pas d'export. | Pas de compliance exportable |
| 18 | **Pas de notification in-app pour les actions** | Les notifications SSE existent pour events, pas pour actions user | Pas de feedback "Membre ajouté" temps réel |

## 2.3 Trous de polish (SaaS premium)

| # | Gap | Description |
|---|-----|-------------|
| 19 | **Pas d'activity feed** | Pas de flux d'activité centralisé (qui a fait quoi quand) |
| 20 | **Pas de raccourcis clavier** | Pas de ⌘K command bar, pas de shortcuts |
| 21 | **Pas de mode offline graceful** | Perte réseau = écran mort |
| 22 | **Pas d'indicateur de connexion** | Pas de "connected/disconnected" visible |
| 23 | **Pas de collaboration temps réel** | Deux admins sur la même page ne voient pas les changements de l'autre |
| 24 | **Pas de dark mode complet** | Dark mode existe (Vuexy) mais pas testé sur toutes les pages custom |
| 25 | **Status colors hardcodés** | Chaque page définit son propre `statusColors = {...}`. Pas centralisé. |

---

# 3. FLOWS UTILISATEUR DÉTAILLÉS

## 3.1 Flow : Upload Document

```
ÉTAT ACTUEL :
  1. Admin ouvre /company/documents/requests
  2. Clique "Ajouter document" → drawer s'ouvre
  3. Sélectionne le type de document
  4. Upload le fichier
  5. ... silence pendant 10-30 secondes ...
  6. Le document apparaît dans la liste avec un statut
  7. Si AI activée : résultat apparaît (polling 5s)

PROBLÈMES :
  - Étape 5 : AUCUN FEEDBACK. L'utilisateur ne sait pas si l'upload est en cours,
    si l'AI analyse, ou si c'est planté.
  - Pas de progress bar sur l'upload
  - Pas d'étapes visibles (upload → processing → analysis → done)
  - Si erreur AI : aucune indication (status reste "submitted")

V2 ATTENDUE :
  1. Admin clique "Ajouter document"
  2. Drawer s'ouvre → sélection type + upload
  3. PROGRESS BAR pendant l'upload (% réel)
  4. Toast "Document uploadé — analyse AI en cours"
  5. DANS LA LISTE : la ligne montre un STEPPER inline :
     ░░░░ Upload ✓ → Analyse AI ◌ → Extraction ◌ → Résultat ◌
  6. SSE push les étapes en temps réel :
     → "AI: type reconnu (Permis B)" — step 2 ✓
     → "AI: données extraites" — step 3 ✓
     → "AI: confiance 94%" — step 4 ✓
  7. Le document passe à "Prêt pour review" avec résumé inline
  8. Si ERREUR : la ligne montre ErrorState avec "Retry" button
     → pas de silence, pas de data vidée
```

## 3.2 Flow : Review Document (Admin)

```
ÉTAT ACTUEL :
  1. Admin voit un document "submitted" dans la liste
  2. Actions inline : approuver / rejeter
  3. Clic → API call → toast "Document approved"

PROBLÈMES :
  - Pas de vue détail pour voir l'analyse AI complète
  - Pas de comparaison (doc uploadé vs données extraites)
  - Pas de note de review (bien que le backend le supporte)
  - Bulk review impossible malgré l'API backend existante

V2 ATTENDUE :
  1. Admin clique sur un document → PAGE DETAIL (/documents/[id])
  2. Split view : Image du document | Données AI extraites
  3. Confiance AI visible par champ (nom: 98%, date: 72%, numéro: 95%)
  4. Boutons : Approuver | Rejeter | Demander un meilleur scan
  5. Note de review optionnelle (textarea)
  6. Timeline : upload → AI → review en cours → résultat
  7. Retour liste : bulk actions toolbar (checkbox → approve/reject selected)
```

## 3.3 Flow : Ajouter un membre

```
ÉTAT ACTUEL :
  1. Admin clique "Ajouter" → drawer avec nom/email/rôle
  2. Submit → API call → toast → drawer ferme → liste refresh
  3. Le nouveau membre reçoit un email d'invitation

PROBLÈMES :
  - Pas de sélection de poste (associé aux documents requis)
  - Pas de preview des documents qui seront demandés
  - Pas de bulk add (CSV ou multi-form)
  - Pas d'optimistic update (le membre n'apparaît qu'après API response)

V2 ATTENDUE :
  1. Admin clique "Ajouter" → drawer
  2. Champs : nom, email, rôle, POSTE
  3. Le poste trigger l'affichage des documents requis :
     "Ce poste requiert : Permis C, FIMO, Carte conducteur, Visite médicale"
  4. Toggle : "Envoyer demande de documents automatiquement" (on par défaut)
  5. Submit → OPTIMISTIC UPDATE (membre apparaît immédiatement dans la liste, grisé)
  6. API response → membre devient normal
  7. Si erreur → membre disparaît + toast erreur
  8. BULK ADD : bouton "Importer CSV" → upload → preview → confirm
```

## 3.4 Flow : Consultation du score compliance

```
ÉTAT ACTUEL :
  1. Admin va sur /company/documents → onglet compliance
  2. Score global affiché en %
  3. Pas de breakdown détaillé

V2 ATTENDUE :
  1. Widget compliance visible sur le HOME (pas seulement dans documents)
  2. Score global avec jauge visuelle
  3. Breakdown par type de document :
     - Permis C : 45/50 (90%) ✅
     - FIMO : 32/50 (64%) ⚠️
     - Visite médicale : 10/50 (20%) 🔴
  4. Breakdown par membre :
     - Mohamed Alami : 4/4 ✅
     - Sarah Ben : 2/4 ⚠️ (manque FIMO + visite médicale)
  5. Actions directes depuis le breakdown :
     - "Relancer Sarah Ben pour FIMO" → 1 clic
  6. Export PDF compliance (backend existe déjà)
```

## 3.5 Flow : Gestion d'une expédition

```
ÉTAT ACTUEL :
  1. Admin crée une expédition (drawer)
  2. Liste avec filtres (statut, recherche, assigné)
  3. Detail page avec infos + changement de statut

PROBLÈMES :
  - Pas de timeline de statut
  - Pas de SSE (changement sur le terrain invisible)
  - Pas d'historique des modifications
  - Changement de statut sans confirmation

V2 ATTENDUE :
  1. Liste avec bulk status change
  2. Detail : timeline visuelle des changements de statut
     draft → confirmed → picked_up → in_transit → delivered
  3. SSE pour tous les changements (livreur met à jour depuis le terrain)
  4. Confirmation dialog avant changement de statut critique
  5. Historique : qui a changé quoi, quand, avec notes
```

---

# 4. COMPOSANTS MANQUANTS

## 4.1 Composants qui n'existent pas mais devraient

| Composant | Description | Où l'utiliser |
|-----------|-------------|---------------|
| **StatusTimeline** | Timeline verticale avec étapes et timestamps | Documents (AI process), Shipments (status), Support (ticket lifecycle) |
| **BulkToolbar** | Toolbar contextuelle quand items sélectionnés (checkbox table) | Documents (approve/reject), Members (bulk invite), Shipments (status change) |
| **InlineProgress** | Stepper/progress horizontal inline dans une row de table | Documents (AI processing steps) |
| **ErrorBanner** | Banner d'erreur avec retry + détails (pas toast, inline) | Toute page avec fetch qui échoue |
| **DirtyGuard** | Composable + dialog "Modifications non sauvegardées" | Settings, Profile, tout formulaire d'édition |
| **MemberProfile** | Page complète profil membre (infos + docs + activité) | /company/members/[id] |
| **DocumentDetail** | Page complète document (image + AI data + timeline + review) | /company/documents/[id] |
| **OnboardingChecklist** | Checklist progressive avec % (persiste en DB) | Home, sidebar |
| **ConnectionIndicator** | Badge SSE connected/disconnected | Navbar |
| **ActivityPanel** | Panel latéral ou page d'activité récente centralisée | Home, sidebar |
| **ComplianceBreakdown** | Widget compliance avec breakdown par type + par membre | Home, Documents |
| **FilterBar** | Composant réutilisable search + chips filtres | Members, Audit, Support, Shipments |
| **PageSkeleton** | Skeleton layout réutilisable par type de page (list, detail, form) | Toute page async |
| **RetryButton** | Bouton "Réessayer" standardisé avec callback | Toute page en erreur |

## 4.2 Composants qui existent mais sont sous-utilisés

| Composant | Utilisation actuelle | Utilisation attendue |
|-----------|---------------------|---------------------|
| **EmptyState** | 1 page (billing payment methods) | TOUTE liste vide (members, shipments, tickets, audit) |
| **ErrorState** | AppShellGate seulement | TOUTE page avec fetch en erreur |
| **StatusChip** | Billing uniquement (5 domains) | Shipments, Support, Documents (ajouter ces domains) |
| **SectionHeader** | Rare | Headers de section dans les pages complexes |
| **VSkeletonLoader** | ~12 pages | TOUTE page avec chargement async |

---

# 5. STANDARDS FRONTEND V2

## 5.1 Comment un écran doit fonctionner

```
BOOT :
  1. Page mount → store.fetch() appelé
  2. Pendant le fetch : SKELETON LOADER visible (pas spinner plein page)
  3. Skeleton matche la structure finale (table skeleton si table, card skeleton si cards)
  4. Fetch réussit → skeleton remplacé par contenu (transition fade, pas flash)
  5. Fetch échoue → ErrorState avec message + bouton Retry

INTERACTION :
  6. User clique une action (save, delete, approve)
  7. Bouton passe en :loading="true" immédiatement
  8. Si optimistic possible → update UI immédiatement (grisé/pending)
  9. API response → confirme ou rollback
  10. Toast feedback : success/error avec message explicite

NAVIGATION :
  11. Si données en cache (store._loaded) → afficher immédiatement
  12. Background refresh silencieux (smart merge, pas overwrite)
  13. Pas de skeleton si données en cache — juste refresh invisible

ERREUR :
  14. Erreur réseau → ErrorBanner inline (pas toast) avec Retry
  15. Erreur validation → champs en erreur highlights (Vuetify native)
  16. Erreur 403 → message explicite "Vous n'avez pas la permission" (pas silence)
  17. Erreur 404 → redirect vers liste avec toast
  18. Erreur 500 → ErrorState avec "Contactez le support"
```

## 5.2 Comment un loading doit fonctionner

```
RÈGLE : Jamais de page blanche. Jamais de spinner plein page (sauf boot initial).

FIRST LOAD (pas de cache) :
  → VSkeletonLoader type adapté au contenu
  → table-row pour les tables, card pour les cards, text pour les formulaires

REFRESH (données en cache) :
  → Pas de skeleton. Données affichées immédiatement.
  → Fetch en background avec silent=true
  → Smart merge : JSON.stringify compare, Object.assign si différent
  → JAMAIS de overwrite brutal (this.items = newItems)

ACTION (button click) :
  → Bouton :loading="true"
  → Le reste de la page reste interactif
  → Si mutation : optimistic update quand possible

POLLING :
  → Toujours silent (pas de loading visible)
  → Toujours smart merge (pas d'overwrite)
  → Intervalle adaptatif : 5s si activité récente, 30s sinon
```

## 5.3 Comment un update realtime doit fonctionner

```
ARCHITECTURE SSE EXISTANTE (bonne) :
  RealtimeClient → ChannelRouter → DomainEventBus → store handlers

RÈGLES V2 :

1. TOUT store qui mute des données DOIT écouter les SSE correspondants
   - documentsStore : ✅ (fait)
   - membersStore : ❌ → ajouter member.updated, member.joined, member.removed
   - shipmentStore : ❌ → ajouter shipment.updated, shipment.status_changed
   - supportStore : ❌ → ajouter ticket.updated, ticket.message.created
   - billingStore : ❌ → ajouter subscription.updated, payment.completed
   - auditStore : ❌ → ajouter audit.entry.created

2. Les handlers SSE font du SMART MERGE — jamais d'overwrite
   - Compare via ID + updated_at
   - Update seulement les champs changés
   - Ajouter les items nouveaux en haut de liste

3. Indicateur de connexion visible
   - Navbar : petit dot vert = connecté, rouge = déconnecté
   - Tooltip : "Temps réel actif" / "Reconnexion en cours..."

4. Reconnexion gracieuse
   - MAX_RECONNECT_ATTEMPTS = 5 (pas 3)
   - Backoff : 2s → 4s → 8s → 16s → 30s
   - Après échec total : polling fallback + banner "Mode hors-ligne partiel"

5. Dedup SSE vs polling
   - Chaque event a un uuid
   - Store track les uuids traités (Set, max 1000)
   - Si uuid déjà vu → skip
```

## 5.4 Comment une erreur doit apparaître

```
RÈGLE : Jamais silencieux. Jamais toast-only pour les erreurs critiques.

ERREUR RÉSEAU (fetch échoue) :
  → ErrorBanner inline dans la page
  → Message : "Impossible de charger les données. Vérifiez votre connexion."
  → Bouton : "Réessayer"
  → PAS de toast (trop éphémère pour une erreur qui persiste)

ERREUR VALIDATION (form submit échoue 422) :
  → Champs en rouge avec message d'erreur (Vuetify :error-messages)
  → Focus automatique sur le premier champ en erreur
  → Toast optionnel en plus : "Corrigez les champs en erreur"

ERREUR ACTION (mutation échoue 500) :
  → Toast error : "L'opération a échoué. Réessayez."
  → Si optimistic : ROLLBACK visible
  → Bouton reste cliquable (pas locked)

ERREUR PERMISSION (403) :
  → Message inline explicite : "Vous n'avez pas la permission d'effectuer cette action"
  → PAS de silence. PAS de données vidées.
  → Si page entière → redirect 403 avec message

ERREUR SESSION (401) :
  → SessionExpiredDialog (existe déjà)
  → Bloque toute interaction
  → "Reconnectez-vous pour continuer"
```

## 5.5 Store Quality Standard

```
TOUT store Pinia company-scope DOIT avoir :

1. LOADING STATE
   _loading: false,        // ou granulaire : _loading: { list: false, detail: false }

2. ERROR STATE
   _error: null,           // string message ou null

3. LOADED FLAG
   _loaded: false,         // true après premier fetch réussi

4. TRY/CATCH sur tout fetch
   async fetchList() {
     this._loading = true
     this._error = null
     try {
       const data = await api.get(...)
       this._smartMerge(data)  // PAS this._items = data
       this._loaded = true
     } catch (e) {
       this._error = e.message
     } finally {
       this._loading = false
     }
   }

5. SMART MERGE (pas d'overwrite brutal)
   _smartMerge(newItems) {
     if (JSON.stringify(this._items) !== JSON.stringify(newItems)) {
       // merge item by item via ID
     }
   }

6. SILENT MODE pour les refetch background
   async fetchList({ silent = false } = {}) {
     if (!silent) this._loading = true
     // ...
   }

7. SSE HANDLER si le module mute des données
   handleRealtimeEvent(payload) {
     switch(payload.type) {
       case 'updated': this._smartMergeOne(payload.data); break
       case 'created': this._items.unshift(payload.data); break
       case 'deleted': this._items = this._items.filter(i => i.id !== payload.id); break
     }
   }

8. RETRY exposé
   retry() { return this.fetchList() }
```

---

# 6. MATRICE DE PRIORITÉ

## P0 — Bloquant (faire en premier)

| Action | Pages impactées | Effort |
|--------|----------------|--------|
| Ajouter loading + error state à tous les stores | TOUTES | 2-3 jours |
| Skeleton loaders sur toutes les pages liste | 8 pages | 1-2 jours |
| ErrorState + RetryButton sur toutes les pages | TOUTES | 1 jour |
| Bulk actions toolbar (documents requests) | documents | 1 jour |
| AI progress feedback (stepper inline) | documents | 2 jours |

## P1 — Important (sprint suivant)

| Action | Pages impactées | Effort |
|--------|----------------|--------|
| SSE integration dans members, shipments, support | 3 modules | 3-4 jours |
| Vue profil membre (/members/[id]) | members | 2-3 jours |
| Vue document detail (/documents/[id]) | documents | 3-4 jours |
| Search + filtres avancés (members, audit, support) | 3 pages | 2-3 jours |
| Dirty check sur formulaires | settings, profile | 1 jour |
| Breadcrumbs sur routes nested | toutes les detail pages | 1 jour |
| Onboarding checklist (home) | home | 2-3 jours |
| StatusTimeline composant | shipments, documents, support | 2 jours |

## P2 — Polish (roadmap V2)

| Action | Pages impactées | Effort |
|--------|----------------|--------|
| Optimistic updates (members, support chat) | 2 modules | 2 jours |
| Connection indicator (SSE status) | navbar | 0.5 jour |
| Command bar ⌘K | global | 3-5 jours |
| Activity feed centralisé | home, sidebar | 3-5 jours |
| Export CSV/PDF sur toutes les listes | members, audit, compliance | 2-3 jours |
| Bulk invite CSV (members) | members | 2 jours |
| Toast severity (info/success/warning/error) | global | 0.5 jour |
| StatusColors centralisé (composable) | toutes les pages avec status | 1 jour |
| ComplianceBreakdown widget | home, documents | 2 jours |
| FilterBar composant réutilisable | 4+ pages | 2 jours |

---

# 7. RÉSUMÉ QUANTITATIF

```
PAGES AUDITÉES :            11 (home, members, documents×3, shipments,
                                deliveries, support, billing, settings, profile, audit)
STORES AUDITÉES :           14 (11 module + 3 core)
COMPOSABLES AUDITÉES :      29
COMPOSANTS UI AUDITÉES :    54

PROBLÈMES IDENTIFIÉS :
  P0 (bloquant)  :  7
  P1 (important) : 18
  P2 (polish)    : 25
  TOTAL          : 50

STORES SANS LOADING STATE :  9/14 (64%)
STORES SANS ERROR STATE :    11/14 (79%)
STORES AVEC SSE :            1/14 (7%)  — seulement documents
STORES AVEC SMART MERGE :    3/14 (21%)
PAGES AVEC SKELETON :       ~12/40 (30%)
PAGES AVEC EMPTY STATE :     1/40 (2.5%)

COMPOSANTS MANQUANTS :      14
COMPOSANTS SOUS-UTILISÉS :   5
FLOWS À REFAIRE :            5
```
