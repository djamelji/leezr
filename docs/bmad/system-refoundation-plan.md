# PLAN DE REFONDATION SYSTEME — LEEZR SaaS

> Date : 2026-03-17
> Statut : PLAN — En attente de validation
> Base : Audit technique (4 documents) + analyse cross-layer complète

---

## TABLE DES MATIERES

1. [PART 1 — Analyse des désalignements](#part-1)
2. [PART 2 — Alignement cross-layer](#part-2)
3. [PART 3 — Systeme cible](#part-3)
4. [PART 4 — Redesign UX / Produit](#part-4)
5. [PART 5 — Couche produit manquante](#part-5)
6. [PART 6 — Roadmap unifiee](#part-6)
7. [PART 7 — Priorisation](#part-7)
8. [PART 8 — Synthese finale](#part-8)

**ADDENDUMS (v2 — enrichissements)**

9. [ADDENDUM A — Points critiques audit : FK, SecurityHeaders, Logic Leakage, Tests](#addendum-a)
10. [ADDENDUM B — Inventaire page par page](#addendum-b)
11. [ADDENDUM C — UI System Contract](#addendum-c)
12. [ADDENDUM D — Billing & Payment System Alignment](#addendum-d)
13. [ADDENDUM E — Roadmap enrichie (taches integrees)](#addendum-e)

**OPERATIONNEL (v3 — translation executoire)**

14. [ADDENDUM F — Sprint Plan](#addendum-f)
15. [ADDENDUM G — Ordre d'execution strict (Phase 0 + Phase 1)](#addendum-g)
16. [ADDENDUM H — Refactor Safety Strategy](#addendum-h)
17. [ADDENDUM I — Definition of Done](#addendum-i)

---

<a id="part-1"></a>
## PART 1 — ANALYSE DES DESALIGNEMENTS SYSTEME

### Pourquoi le produit semble incomplet malgre une architecture solide

Le systeme possede 121 controllers, 166 migrations, 37 modules, 99 fichiers billing core, et 70 pages Vue. L'architecture est mature. Mais le produit ne se *sent* pas fini. Voici pourquoi :

### 1.1 Deconnexion Backend ↔ Frontend

| Symptome | Backend | Frontend | Impact utilisateur |
|----------|---------|----------|-------------------|
| **Billing complet mais opaque** | 27 services, ledger double-entree, forensics | Pas de timeline visible, pas d'empty states billing, pas de guidance | L'utilisateur ne comprend pas son cycle de facturation |
| **Modules riches mais muets** | 37 modules avec permissions, activation, dependencies | Pas d'onboarding, pas de suggestion, modules = switch on/off | L'utilisateur ne sait pas quoi activer ni pourquoi |
| **Audit trail exhaustif** | AuditLogger + DiffEngine + logs immutables | Page audit = simple liste, pas de filtres contextuels | Les logs existent mais personne ne les consulte |
| **Notifications temps reel** | SSE publisher, topics, preferences | Toast + bell = ok, mais pas de notification center riche | Le systeme notifie mais l'utilisateur rate les infos |
| **Support ticket** | CRUD + messages + assign + priorite | Vue basique, pas de SLA visible, pas de contexte | Support existe mais ne rassure pas |

### 1.2 Features techniquement correctes mais UX confuse

| Feature | Probleme UX | Cause racine |
|---------|-------------|--------------|
| **Plan change** | L'utilisateur voit un prix mais pas la proration, pas le calendrier | Preview engine existe (ADR-247) mais UI ne montre pas tout |
| **Payment methods** | 3 types (carte, SEPA, wallet) mais UI ne guide pas le choix | `PaymentMethodResolver` choisit en backend, UI ne l'explique pas |
| **Checkout flow** | Marche techniquement mais l'utilisateur ne sait pas ou il en est | Pas de stepper visuel, pas de confirmation claire |
| **Trial → Paid** | Transition automatique prevue mais pas de warning pre-expiration | `TrialExpirationProcessor` manquant (gap audit) |
| **Dunning** | DunningEngine sophistique mais l'utilisateur ne recoit aucun signal | Pas de banner "paiement echoue", pas d'action visible |

### 1.3 Flows incomplets

| Flow | Etat actuel | Ce qui manque |
|------|-------------|---------------|
| **Signup → Trial → Payment → Activation** | Register 6 steps + checkout exist | Pas de transition trial→paid automatique. Pas de reminder pre-expiration |
| **Plan change → Proration → Invoice** | Backend calcule tout | UI ne montre pas le detail de la proration avant confirmation |
| **Addon lifecycle** | Activation/desactivation ok | Pas de preview impact addon sur facturation |
| **Support lifecycle** | Create/reply ok | Pas de SLA visible, pas de statut clair, pas de resolution tracking |
| **Document compliance** | Upload/review workflow ok | Pas de dashboard compliance, pas de deadline tracking |

### 1.4 Pages sans role clair

| Page | Probleme |
|------|----------|
| `/company/settings.vue` (13 lignes) | Stub redirect vers profile. Inutile. |
| `/account-settings/[tab].vue` | Legacy, doublon de company/profile et platform/account |
| `/home.vue` (183 lignes) | Role flou — workspace selector ? landing ? |
| `/dashboard.vue` (233 lignes) | Missing `definePage()`. Company dashboard mais sans guidance |

### 1.5 Couplage cache entre modules et UI

| Couplage | Risque |
|----------|--------|
| **Billing store monolithique** | 1 store gere overview + invoices + payments + subscription + checkout |
| **Pages billing > 1000 lignes** | `/company/billing/[tab].vue` orchestre 9 tabs dans 1 fichier |
| **Platform billing = 24 sous-composants** | Difficile a maintenir, navigation interne floue |
| **Jobdomain detail = 2516 lignes** | Monolithe UI, impossible a tester |

---

<a id="part-2"></a>
## PART 2 — ALIGNEMENT CROSS-LAYER

Pour chaque issue technique identifiee dans l'audit, voici l'impact UX et produit.

### 2.1 Infrastructure & Securite

| Issue technique (Audit) | Impact UX | Impact Produit |
|------------------------|-----------|----------------|
| **SESSION_ENCRYPT=false** (F1) | Aucun visible | Risque compliance RGPD |
| **SESSION_SECURE_COOKIE absent** (F2) | Session volee si HTTP downgrade | Perte de confiance |
| **Pas de keepalive** (F3) | Session expire silencieusement apres 120min | L'utilisateur perd son travail sans warning |
| **Pas de warning expiration** (F4) | Aucun signal avant perte de session | Frustration, impression de bug |
| **Dashboard fantome apres expiry** (F5) | UI affichee mais inoperante | L'utilisateur clique et rien ne repond |
| **Credentials encryption non confirmee** (Sec-1) | Invisible | Fuite potentielle de cles API Stripe |
| **v-html dans help center** (Sec-2) | Invisible | XSS possible si contenu non sanitise |
| **Pas de rate limiting billing API** (Sec-3) | Invisible | DDoS possible sur endpoints critiques |

### 2.2 Billing

| Issue technique (Audit) | Impact UX | Impact Produit |
|------------------------|-----------|----------------|
| **Trial expiration non automatisee** | L'utilisateur ne sait pas quand son trial finit | Perte de revenue, companies en trial infini |
| **Credit notes manuels** | L'admin doit crediter manuellement | Delai de remboursement, insatisfaction |
| **Subscription state "pending" jamais atteint** | Code mort | Faux sentiment de securite |
| **Invoice PDF non teste en charge** | PDF lent sur grosses factures | Perception de lenteur |
| **Webhook recovery manuelle** | Invisible | Paiements potentiellement rates |

### 2.3 Backend Architecture

| Issue technique (Audit) | Impact UX | Impact Produit |
|------------------------|-----------|----------------|
| **13 form requests sur 121 controllers** | Validation inconsistante | Erreurs 500 au lieu de 422 = messages incomprehensibles |
| **Business logic dans controllers** | Duplication | Comportements differents pour la meme action selon le point d'entree |
| **Pas de DTO response wrappers** | Reponses API inconsistantes | Frontend doit deviner la structure |
| **Pas d'API versioning** | Invisible | Breaking changes potentiels |

### 2.4 Frontend Architecture

| Issue technique (Audit) | Impact UX | Impact Produit |
|------------------------|-----------|----------------|
| **Pas de loading visuel (skeleton/progress)** | Pages "clignotent" entre etats | Perception de lenteur |
| **Empty states uniquement dans les tables** | Listes vides = ecran blanc | L'utilisateur pense que ca ne marche pas |
| **window.confirm() natif** | Popup OS, pas dans le style de l'app | Rupture visuelle, impression d'app pas finie |
| **Pas de validation client** | Erreur seulement apres submit | Friction formulaire |
| **Bundle CSS 3.2MB** (LOT-01) | First paint lent | Temps de chargement initial |
| **5000 icons charges, 180 utilises** | Gaspillage | Bande passante gaspillee |

---

<a id="part-3"></a>
## PART 3 — SYSTEME CIBLE (FULL ALIGNMENT)

### 3.1 Backend — Architecture cible

```
REQUETE HTTP
    ↓
Middleware (auth, company context, permissions, module gate, rate limit, session governance)
    ↓
Controller (thin — validation + dispatch uniquement)
    ↓
FormRequest (TOUTES les mutations validees par un FormRequest dedie)
    ↓
UseCase / Action (business logic UNIQUE pour une mutation)
    ↓
Domain Services (billing, audit, notification — orchestration)
    ↓
Models + Events (eloquent, domain events)
    ↓
ReadModel (queries complexes, projection plate pour l'UI)
    ↓
Response DTO (structure de reponse consistante, jamais un model eloquent brut)
```

**Regles strictes :**
- Chaque mutation passe par un UseCase ou Action
- Chaque mutation a un FormRequest dedie
- Les controllers ne contiennent JAMAIS de business logic
- Les ReadModels sont la seule source de donnees pour les pages de lecture
- Les responses sont enveloppees dans un DTO standard

### 3.2 Frontend — Architecture cible

```
ROUTE (unplugin-vue-router)
    ↓
Page Component (orchestration uniquement)
    ↓
Composables (useCompanyBilling, usePlatformUsers, etc.)
    ↓                          ↓
Store (Pinia)            API Client ($api, $platformApi)
    ↓                          ↓
State reactif          Response normalisee
    ↓
Sub-Components (presentation, zero logic business)
    ↓
UI Presets (Vuexy — selection, assemblage, configuration)
```

**Regles strictes :**
- ZERO business logic dans les pages ou composants
- Chaque page a un role clair (list, detail, create, settings)
- Les composables encapsulent : loading, error, data, actions
- Les stores sont granulaires (1 store = 1 domaine, max 200 lignes)
- L'etat de chargement/erreur/vide est TOUJOURS gere

### 3.3 Produit — Systeme cible

**Navigation :**
- Sections clairement nommees et ordonnees
- Aucun lien mort, aucune page fantome
- Breadcrumbs sur toutes les pages non-dashboard
- Titre de page dynamique (useHead)

**Pages :**
- Chaque page a un role unique et documente
- Les pages de liste suivent un pattern uniforme (filtres, table, pagination, empty state)
- Les pages de detail suivent un pattern uniforme (header, tabs, actions)
- Les pages de formulaire suivent un pattern uniforme (validation, submit, feedback)

**Workflows :**
- Chaque workflow est complet de bout en bout
- Les transitions sont visibles et explicites
- Les erreurs sont toujours recuperables (retry available)
- Les etats intermediaires sont toujours visibles (loading, processing, pending)

---

<a id="part-4"></a>
## PART 4 — REDESIGN UX / PRODUIT

### 4.1 Systeme de navigation

#### Platform (Admin)

```
DASHBOARD
├── Vue d'ensemble (widgets, KPIs, alertes)

GESTION ENTREPRISES
├── Entreprises (liste + detail 360°)
├── Utilisateurs entreprise (supervision)
├── Domaines metier (configuration)

FACTURATION
├── Abonnements & Plans
├── Factures & Paiements
├── Portefeuilles & Credits
├── Coupons & Promotions
├── Gouvernance financiere (ledger, periodes, forensics)
├── Recouvrement (DLQ, recovery, dunning)

CONFIGURATION
├── Parametres generaux
├── Theme & Typographie
├── Modules systeme
├── Champs personnalises
├── Documents (catalogue)
├── Internationalisation (langues, marches, traductions, taux)

UTILISATEURS ADMIN
├── Utilisateurs plateforme
├── Roles & Permissions

OPERATIONS
├── Notifications (governance topics)
├── Support (tickets admin)
├── Securite (alertes)
├── Audit (logs)
├── Temps reel (monitoring)
├── Documentation (help center admin)
├── Audience (mailing)

MON COMPTE
├── Profil, Securite, Preferences
```

#### Company (Utilisateur final)

```
TABLEAU DE BORD
├── Dashboard personnalisable (widgets, onboarding guide)

MON EQUIPE
├── Membres (liste, invitation, profils, documents)
├── Roles & Permissions

MON ENTREPRISE
├── Profil entreprise (infos, adresse, legal)
├── Modules actifs (activation, configuration)
├── Conformite documents (upload, review, deadlines)

FACTURATION
├── Vue d'ensemble (abonnement, prochaine facture, wallet)
├── Changer de plan
├── Factures & Paiements
├── Moyens de paiement

LOGISTIQUE (si module actif)
├── Expeditions
├── Mes livraisons

AIDE
├── Centre d'aide
├── Support (tickets)
├── Notifications

PARAMETRES
├── Preferences (theme, langue)
├── Journal d'activite (audit)
```

### 4.2 Systeme de pages

#### Types de pages et leur contrat

| Type | Role | Action principale | Donnees |
|------|------|-------------------|---------|
| **Dashboard** | Vue synthetique | Naviguer vers l'action urgente | Widgets, KPIs, alertes |
| **List** | Trouver un element | Filtrer, chercher, paginer | Table server-side, compteurs |
| **Detail** | Comprendre un element | Agir sur l'element (edit, delete, action) | Tabs, header recapitulatif |
| **Form/Create** | Creer ou editer | Soumettre | Formulaire, validation |
| **Settings** | Configurer | Sauvegarder | Sections, toggles |
| **Wizard** | Completer un processus | Avancer dans les etapes | Steps, validation, resume |

#### Contrat par page (chaque page DOIT implementer)

```
1. Loading state     → VProgressLinear dans AppBar OU VSkeletonLoader
2. Error state       → Alerte inline avec bouton retry
3. Empty state       → Illustration + message + CTA
4. Page title        → useHead() dynamique
5. Breadcrumb        → Si depth > 1
6. Responsive        → Fonctionnel mobile (Vuetify breakpoints)
```

### 4.3 Patterns UX systeme (OBLIGATOIRES sur toutes les pages)

#### Loading

```vue
<!-- Pattern standard : progress bar dans l'app bar -->
<VProgressLinear v-if="isLoading" indeterminate color="primary" />

<!-- OU skeleton pour premier chargement -->
<VSkeletonLoader v-if="!hasLoaded" type="table-heading, table-row@5" />

<!-- JAMAIS d'ecran blanc pendant le chargement -->
```

#### Erreur

```vue
<!-- Pattern standard : alerte inline -->
<VAlert v-if="loadError" type="error" class="mb-4">
  {{ loadError }}
  <template #append>
    <VBtn variant="text" @click="retry">{{ t('common.retry') }}</VBtn>
  </template>
</VAlert>

<!-- Retry TOUJOURS disponible -->
```

#### Empty state

```vue
<!-- Pattern standard : illustration + message + CTA -->
<div v-if="!isLoading && items.length === 0" class="text-center pa-8">
  <VIcon :icon="emptyIcon" size="64" class="mb-4 text-disabled" />
  <h6 class="text-h6 mb-2">{{ t('module.noItemsYet') }}</h6>
  <p class="text-body-2 text-medium-emphasis mb-4">{{ t('module.noItemsDescription') }}</p>
  <VBtn v-if="canCreate" color="primary" @click="create">
    {{ t('module.createFirst') }}
  </VBtn>
</div>
```

#### Feedback

```
- Succes → toast vert (4s auto-dismiss) via useAppToast
- Erreur → toast rouge (persist) + message backend
- Action destructive → ConfirmDialog Vuetify (PAS window.confirm natif)
- Loading action → bouton :loading="actionLoading"
- Sauvegarde → feedback immediat ("Sauvegarde...")
```

#### Confirmation destructive

```vue
<!-- Pattern standard : dialog Vuetify -->
<ConfirmDialog
  v-model:is-dialog-visible="showConfirm"
  :confirmation-question="t('common.confirmDelete', { name: item.name })"
  @confirm="executeDelete"
/>

<!-- JAMAIS window.confirm() -->
<!-- TOUJOURS indiquer ce qui va etre supprime -->
<!-- TOUJOURS un bouton annuler visible -->
```

### 4.4 Workflows complets (ZERO flow partiel)

#### Workflow 1 : Inscription → Trial → Paiement → Activation

```
ETAPE 1 : INSCRIPTION (6 steps existants)
  Account → Company → Industry → Plan → Addons → Summary
  ↓ (creation company + subscription trial)

ETAPE 2 : TRIAL (MANQUANT — a implementer)
  - Banner permanent "Trial : X jours restants"
  - Widget dashboard "Configurer votre entreprise" (onboarding checklist)
  - Email J-7, J-3, J-1 avant expiration
  - Notification in-app J-3
  ↓ (expiration trial)

ETAPE 3 : PAIEMENT (existe partiellement)
  - Page checkout avec Stripe Elements
  - Si payment method deja enregistre → charge automatique
  - Si pas de payment method → redirect vers setup
  - Confirmation visuelle + facture generee
  ↓ (activation)

ETAPE 4 : ACTIVATION
  - Subscription active
  - Email de bienvenue post-paiement
  - Dashboard actualise (plus de banner trial)
```

#### Workflow 2 : Changement de plan

```
ETAPE 1 : SELECTION
  - Page plans avec comparaison features
  - Badge plan actuel
  ↓

ETAPE 2 : PREVIEW (existe — ADR-247)
  - Proration calculee et affichee clairement
  - Montant a payer / a crediter
  - Date de prise d'effet
  - Impact sur les modules (activation / desactivation)
  ↓

ETAPE 3 : CONFIRMATION
  - Resume complet avant validation
  - Confirmation explicite (dialog)
  ↓

ETAPE 4 : EXECUTION
  - Facture generee si upgrade
  - Credit wallet si downgrade
  - Notification in-app + email
  - Badge plan actualise immediatement
```

#### Workflow 3 : Cycle de vie addon

```
ACTIVATION
  - Depuis la page modules
  - Preview impact sur facturation AVANT activation
  - Confirmation
  ↓
USAGE
  - Module apparait dans navigation
  - Badge addon dans les parametres
  ↓
DESACTIVATION
  - Preview impact (desactivation dependencies, perte de donnees ?)
  - Credit wallet prorata
  - Module disparait de la navigation
```

#### Workflow 4 : Support

```
CREATION
  - Formulaire avec categorie, priorite, description
  - Piece jointe optionnelle
  ↓
SUIVI
  - Timeline des messages (user + admin)
  - Statut visible (ouvert, en cours, resolu, ferme)
  - Notification quand admin repond
  ↓
RESOLUTION
  - Admin resout
  - User confirme ou reouvre
  - Satisfaction survey (optionnel)
```

#### Workflow 5 : Conformite documents

```
CONFIGURATION (admin company)
  - Activer les types de documents requis
  - Definir les deadlines
  ↓
DEMANDE
  - Demander un document a un membre (individuel ou batch par role)
  ↓
UPLOAD (membre)
  - Le membre recoit une notification
  - Upload le document
  ↓
REVIEW (admin company)
  - Approuver ou rejeter avec commentaire
  - Dashboard compliance : taux de conformite, documents en retard
```

---

<a id="part-5"></a>
## PART 5 — COUCHE PRODUIT MANQUANTE

Ce qui manque pour un SaaS production-grade :

### 5.1 Onboarding

| Element | Statut | A implementer |
|---------|--------|---------------|
| **Checklist onboarding** | `OnboardingStatusController` existe | Widget dashboard avec steps (profil, plan, equipe, module) |
| **Tooltips guide** | Absent | Pas necessaire en V1 — la checklist suffit |
| **Email sequence post-inscription** | Absent | Email J+1, J+3, J+7 avec tips |
| **Premier membre invite** | Absent | CTA contextuel apres creation company |

### 5.2 Clarte billing

| Element | Statut | A implementer |
|---------|--------|---------------|
| **Timeline billing** | Controller existe (ADR-314) | Composant visuel timeline (evenements, factures, paiements) |
| **Next invoice preview** | Backend calcule | Widget visible dans billing overview |
| **Wallet explique** | Backend complet | Section UI expliquant ce qu'est le wallet, d'ou viennent les credits |
| **Dunning visible** | DunningEngine existe | Banner "Paiement echoue — regularisez" + lien direct |
| **Facture PDF conforme** | ADR-331 implementee | Verifier mentions legales EU |

### 5.3 Aide contextuelle

| Element | Statut | A implementer |
|---------|--------|---------------|
| **Help center** | Complet (ADR-355/356) | Verifier liens depuis chaque section |
| **Tooltips explicatifs** | Ponctuels | Ajouter sur les champs critiques (VAT, proration, dunning) |
| **Empty state avec guidance** | Absent | Chaque empty state doit expliquer quoi faire |

### 5.4 Visibilite audit

| Element | Statut | A implementer |
|---------|--------|---------------|
| **Audit platform** | Complet (logs + diff) | Filtres par module, par user, par periode |
| **Audit company** | Controller existe | Timeline visuelle (pas juste une table) |
| **Audit financier** | Forensics complet | Dashboard admin avec alertes de drift |

### 5.5 Signaux de confiance

| Element | Statut | A implementer |
|---------|--------|---------------|
| **Securite visible** | 2FA existe | Badge "Compte securise" dans le profil |
| **Uptime** | Service existe | Page status publique (optionnel V2) |
| **Derniere connexion** | Loggee | Afficher dans le profil |
| **Activite recente** | Audit log | Widget dashboard "Dernieres actions" |

### 5.6 Surfaces admin avancees

| Element | Statut | A implementer |
|---------|--------|---------------|
| **Dashboard admin KPIs** | Widget system existe | MRR, ARR, churn, trial conversion rate |
| **Export CSV** | Partiellement (billing export) | Generaliser a toutes les listes admin |
| **Bulk actions** | Partiellement (billing bulk) | Generaliser (bulk suspend, bulk notify) |
| **Recherche globale** | Absente | Recherche companies + users + factures (V2) |

---

<a id="part-6"></a>
## PART 6 — ROADMAP UNIFIEE

### Phase 0 — Securite critique (DE L'AUDIT)

> Objectif : eliminer les risques de securite et d'integrite avant tout

| # | Tache | Source | Impact | Effort |
|---|-------|--------|--------|--------|
| 0.1 | **SESSION_ENCRYPT=true** + **SESSION_SECURE_COOKIE=true** en production | Audit Session F1/F2 | Haute | S |
| 0.2 | **Verifier encryption credentials Stripe** dans PlatformPaymentModule | Audit Securite Sec-1 | Haute | S |
| 0.3 | **Rate limiting sur /api/company/billing/*** | Audit Securite Sec-3 | Haute | S |
| 0.4 | **Sanitizer v-html** dans help center (DOMPurify) | Audit Securite Sec-2 | Moyenne | S |
| 0.5 | **Webhook secret validation** — throw si absent au lieu de fallback null | Audit Securite Sec-4 | Moyenne | S |
| 0.6 | **Audit mutations admin** — AuditLogger sur void/mark-paid/refund | Audit Securite Sec-5 | Basse | S |
| 0.7 | **Session keepalive** + **warning dialog** avant expiration | Audit Session F3/F4 — ADR-070 propose | Haute | M |
| 0.8 | **Session expired dialog** (graceful, pas redirect brutal) | Audit Session F5 — ADR-070 propose | Moyenne | M |

**Dependances** : Aucune — peut commencer immediatement
**Critere de fin** : Tests securite verts, zero finding HIGH ouvert

---

### Phase 1 — Nettoyage domaine (DE L'AUDIT)

> Objectif : standardiser l'architecture backend pour garantir la coherence

| # | Tache | Source | Impact | Effort |
|---|-------|--------|--------|--------|
| 1.1 | **FormRequest pour chaque mutation** — 108 controllers sans form request | Audit Architecture | Haute | L |
| 1.2 | **Extraction use cases** — logique metier hors des controllers | Audit Architecture | Haute | L |
| 1.3 | **Response DTO** — wrapper standard pour toutes les reponses API | Audit Architecture | Moyenne | M |
| 1.4 | **Trial expiration job** — scheduler + TrialExpirationProcessor | Audit Billing | Haute | M |
| 1.5 | **Credit note automatique** sur downgrade | Audit Billing | Moyenne | M |
| 1.6 | **Webhook recovery poller** — fallback si Stripe rate un event | Audit Billing | Moyenne | M |
| 1.7 | **Test : trial → paid conversion** end-to-end | Audit Test Coverage | Haute | S |
| 1.8 | **Test : concurrent payment attempts** (race condition) | Audit Test Coverage | Haute | M |
| 1.9 | **Test : webhook failure & replay** | Audit Test Coverage | Moyenne | S |
| 1.10 | **Audit & enforce SEPA debit protocol** (ADR-328) | Audit Billing | Moyenne | S |
| 1.11 | **Supprimer state "pending" mort** ou l'implementer | Audit Billing | Basse | S |

**Dependances** : Phase 0 terminee pour la securite
**Critere de fin** : Tous les tests verts, zero TODO dans les controllers

---

### Phase 2 — Fondation UX (NOUVEAU)

> Objectif : chaque page respecte le contrat UX minimum

| # | Tache | Source | Impact | Effort |
|---|-------|--------|--------|--------|
| 2.1 | **Composable useAsyncState()** — loading, error, data, retry en un seul composable | Analyse UX | Haute | M |
| 2.2 | **VProgressLinear systemique** — dans AppBarContent quand une page charge | Analyse UX | Haute | S |
| 2.3 | **Composant EmptyState** reutilisable (icon + message + CTA) | Analyse UX | Haute | S |
| 2.4 | **Composant ErrorState** reutilisable (message + retry) | Analyse UX | Haute | S |
| 2.5 | **Remplacer window.confirm()** par ConfirmDialog Vuetify partout | Analyse UX | Moyenne | S |
| 2.6 | **i18n ConfirmDialog** — boutons "Confirm"/"Cancel" → t('common.confirm')/t('common.cancel') | Analyse UX | Basse | S |
| 2.7 | **useHead() sur toutes les pages** — titre dynamique | Analyse Navigation | Moyenne | M |
| 2.8 | **Breadcrumbs sur pages depth > 1** | Analyse Navigation | Moyenne | M |
| 2.9 | **Supprimer pages mortes** : `/company/settings.vue` (stub), `/account-settings/[tab].vue` (legacy) | Analyse Pages | Basse | S |
| 2.10 | **definePage() sur les 4 pages manquantes** | Analyse Pages | Basse | S |
| 2.11 | **Bundle optimization LOT-01** — icons CSS 2.85MB → purge unused | Audit Bundle | Haute | M |
| 2.12 | **Validation client** — regles de base sur les formulaires critiques (register, billing) | Analyse UX | Moyenne | M |

**Dependances** : Peut commencer en parallele de Phase 1
**Critere de fin** : Build clean, toutes les pages respectent le contrat UX, bundle < 1MB CSS

---

### Phase 3 — Coherence produit (NOUVEAU)

> Objectif : le produit se sent complet et predictible

| # | Tache | Source | Impact | Effort |
|---|-------|--------|--------|--------|
| 3.1 | **Onboarding widget dashboard** — checklist (profil, plan, equipe, module) | Couche manquante | Haute | M |
| 3.2 | **Trial banner** permanent avec jours restants + CTA paiement | Workflow manquant | Haute | S |
| 3.3 | **Billing timeline** — composant visuel sur billing overview (events, invoices, payments) | ADR-314 | Haute | M |
| 3.4 | **Next invoice preview widget** dans billing overview | Backend existe | Moyenne | S |
| 3.5 | **Dunning banner** — "Paiement echoue" visible dans layout quand past_due | Workflow manquant | Haute | S |
| 3.6 | **Plan comparison page** enrichie avec features par plan | UX Produit | Moyenne | M |
| 3.7 | **Proration detail** dans la confirmation de changement de plan | ADR-247 existe, UI incomplete | Moyenne | S |
| 3.8 | **Wallet section expliquee** — d'ou viennent les credits, comment ils sont utilises | UX Produit | Moyenne | S |
| 3.9 | **Support status tracker** — timeline visuelle (ouvert → en cours → resolu) | Workflow manquant | Moyenne | M |
| 3.10 | **Document compliance dashboard** — taux, deadlines, en retard | Workflow manquant | Moyenne | M |
| 3.11 | **Notification center enrichi** — groupement par type, mark read batch | ADR-352 | Moyenne | M |
| 3.12 | **Reorganiser navigation company** selon la hierarchie definie en 4.1 | Navigation | Haute | M |
| 3.13 | **Reorganiser navigation platform** selon la hierarchie definie en 4.1 | Navigation | Haute | M |
| 3.14 | **Split pages monolithiques** : billing [tab] (1237L), jobdomains [id] (2516L), modules index (1531L), members index (1196L) | Architecture UI | Moyenne | L |

**Dependances** : Phase 2 pour les composants UX de base
**Critere de fin** : Tous les workflows documentes en 4.4 sont complets, navigation coherente

---

### Phase 4 — Completion systeme

> Objectif : SaaS production-grade, observable, administrable

| # | Tache | Source | Impact | Effort |
|---|-------|--------|--------|--------|
| 4.1 | **Emails transactionnels** — trial reminder J-7/J-3/J-1, welcome post-payment, plan change | Workflow manquant | Haute | M |
| 4.2 | **Admin dashboard KPIs** — MRR, ARR, churn rate, trial conversion | Couche manquante | Haute | M |
| 4.3 | **Export CSV generalise** — toutes les listes admin (companies, users, invoices, audit) | Couche manquante | Moyenne | M |
| 4.4 | **Correlation IDs** en production (ADR-311) | Audit Observabilite | Moyenne | M |
| 4.5 | **Financial forensics dashboard** — alertes drift, reconciliation | ADR existe | Moyenne | M |
| 4.6 | **Billing store split** — 1 store = 1 concern (overview, invoices, payments, subscription) | ADR-317 | Moyenne | M |
| 4.7 | **Queue jobs billing** — async pour invoice generation, auto-charge, emails | ADR-318 | Moyenne | L |
| 4.8 | **Bulk actions admin** — suspend/reactivate/notify multiple companies | Couche manquante | Basse | M |
| 4.9 | **Audit company timeline** visuelle (pas juste une table) | Couche manquante | Basse | M |
| 4.10 | **Satisfaction survey** post-resolution support | Couche manquante | Basse | S |
| 4.11 | **Data retention policy** documentee et implementee | Audit Securite | Basse | S |

**Dependances** : Phase 3 pour les workflows complets
**Critere de fin** : Zero gap dans les workflows, observabilite active

---

<a id="part-7"></a>
## PART 7 — PRIORISATION DETAILLEE

### Matrice Impact × Effort

#### Phase 0 — Securite critique

| # | Tache | Impact | Effort | Dependances | Priorite |
|---|-------|--------|--------|-------------|----------|
| 0.1 | Session encrypt + secure cookie | Haute | S | Aucune | P0 |
| 0.2 | Verifier encryption credentials Stripe | Haute | S | Aucune | P0 |
| 0.3 | Rate limiting billing API | Haute | S | Aucune | P0 |
| 0.4 | Sanitize v-html help center | Moyenne | S | Aucune | P0 |
| 0.5 | Webhook secret validation | Moyenne | S | Aucune | P0 |
| 0.6 | Audit mutations admin | Basse | S | Aucune | P0 |
| 0.7 | Session keepalive + warning | Haute | M | 0.1 | P0 |
| 0.8 | Session expired dialog | Moyenne | M | 0.7 | P0 |

#### Phase 1 — Nettoyage domaine

| # | Tache | Impact | Effort | Dependances | Priorite |
|---|-------|--------|--------|-------------|----------|
| 1.4 | Trial expiration job | Haute | M | Aucune | P1-A |
| 1.7 | Test trial → paid | Haute | S | 1.4 | P1-A |
| 1.8 | Test concurrent payments | Haute | M | Aucune | P1-A |
| 1.1 | FormRequest standardization | Haute | L | Aucune | P1-B |
| 1.2 | UseCase extraction | Haute | L | 1.1 | P1-B |
| 1.5 | Credit note auto | Moyenne | M | Aucune | P1-B |
| 1.6 | Webhook recovery poller | Moyenne | M | Aucune | P1-B |
| 1.3 | Response DTO | Moyenne | M | 1.2 | P1-C |
| 1.9 | Test webhook replay | Moyenne | S | 1.6 | P1-C |
| 1.10 | SEPA protocol audit | Moyenne | S | Aucune | P1-C |
| 1.11 | Clean dead state | Basse | S | Aucune | P1-C |

#### Phase 2 — Fondation UX

| # | Tache | Impact | Effort | Dependances | Priorite |
|---|-------|--------|--------|-------------|----------|
| 2.1 | useAsyncState composable | Haute | M | Aucune | P2-A |
| 2.2 | VProgressLinear systemique | Haute | S | 2.1 | P2-A |
| 2.3 | EmptyState component | Haute | S | Aucune | P2-A |
| 2.4 | ErrorState component | Haute | S | Aucune | P2-A |
| 2.11 | Bundle CSS purge | Haute | M | Aucune | P2-A |
| 2.5 | Remplacer window.confirm | Moyenne | S | Aucune | P2-B |
| 2.6 | i18n ConfirmDialog | Basse | S | 2.5 | P2-B |
| 2.7 | useHead toutes pages | Moyenne | M | Aucune | P2-B |
| 2.8 | Breadcrumbs | Moyenne | M | Aucune | P2-B |
| 2.12 | Validation client | Moyenne | M | 2.1 | P2-B |
| 2.9 | Supprimer pages mortes | Basse | S | Aucune | P2-C |
| 2.10 | definePage manquants | Basse | S | Aucune | P2-C |

#### Phase 3 — Coherence produit

| # | Tache | Impact | Effort | Dependances | Priorite |
|---|-------|--------|--------|-------------|----------|
| 3.2 | Trial banner | Haute | S | 1.4 | P3-A |
| 3.5 | Dunning banner | Haute | S | 2.3, 2.4 | P3-A |
| 3.1 | Onboarding widget | Haute | M | 2.3 | P3-A |
| 3.12 | Nav company redesign | Haute | M | Aucune | P3-A |
| 3.13 | Nav platform redesign | Haute | M | Aucune | P3-A |
| 3.3 | Billing timeline | Haute | M | 2.1 | P3-B |
| 3.4 | Next invoice preview | Moyenne | S | 3.3 | P3-B |
| 3.7 | Proration detail | Moyenne | S | Aucune | P3-B |
| 3.8 | Wallet explique | Moyenne | S | Aucune | P3-B |
| 3.6 | Plan comparison | Moyenne | M | Aucune | P3-B |
| 3.9 | Support timeline | Moyenne | M | 2.3 | P3-C |
| 3.10 | Compliance dashboard | Moyenne | M | 2.3 | P3-C |
| 3.11 | Notification center enrichi | Moyenne | M | Aucune | P3-C |
| 3.14 | Split pages monolithiques | Moyenne | L | Aucune | P3-C |

#### Phase 4 — Completion systeme

| # | Tache | Impact | Effort | Dependances | Priorite |
|---|-------|--------|--------|-------------|----------|
| 4.1 | Emails transactionnels | Haute | M | 1.4, 3.2 | P4-A |
| 4.2 | Admin KPIs dashboard | Haute | M | Aucune | P4-A |
| 4.6 | Billing store split | Moyenne | M | Aucune | P4-A |
| 4.3 | Export CSV generalise | Moyenne | M | Aucune | P4-B |
| 4.4 | Correlation IDs | Moyenne | M | Aucune | P4-B |
| 4.5 | Forensics dashboard | Moyenne | M | 4.2 | P4-B |
| 4.7 | Queue jobs billing | Moyenne | L | 4.6 | P4-C |
| 4.8 | Bulk actions admin | Basse | M | 4.3 | P4-C |
| 4.9 | Audit timeline visuelle | Basse | M | 2.3 | P4-C |
| 4.10 | Satisfaction survey | Basse | S | 3.9 | P4-C |
| 4.11 | Data retention policy | Basse | S | Aucune | P4-C |

### Synthese effort

| Phase | Taches | Effort total estime | Quick wins (S) |
|-------|--------|---------------------|----------------|
| Phase 0 | 8 | ~3 semaines | 6 taches S |
| Phase 1 | 11 | ~5 semaines | 4 taches S |
| Phase 2 | 12 | ~4 semaines | 7 taches S |
| Phase 3 | 14 | ~6 semaines | 5 taches S |
| Phase 4 | 11 | ~5 semaines | 2 taches S |
| **TOTAL** | **56 taches** | **~23 semaines** | **24 quick wins** |

---

<a id="part-8"></a>
## PART 8 — SYNTHESE FINALE

### Ce qui a ete fait

Le systeme Leezr possede :
- **Architecture backend mature** : 121 controllers, 37 modules, 166 migrations, domain-driven
- **Billing sophistique** : 99 fichiers core, double-entry ledger, forensics, multi-provider
- **Securite solide** : 2FA, audit trail, tenancy isolation verifiee, flood detection
- **UI riche** : 70 pages, 722 presets Vuexy, notifications temps reel, dashboard widgets

### Ce qui manque

Le systeme ne se sent pas fini parce que :

1. **La couche UX est inconsistante** — pas de loading/error/empty states standardises
2. **Les workflows sont incomplets** — trial → paid, dunning → resolution, compliance
3. **Le produit ne guide pas** — pas d'onboarding, pas de timeline billing, pas de banners contextuels
4. **Les pages sont surdimensionnees** — 5 pages > 1000 lignes, 1 page a 2516 lignes
5. **Le feedback est minimal** — window.confirm natif, pas de validation client, toasts basiques
6. **La navigation n'est pas structuree** — sections plates, pas de hierarchie claire

### Ce que ce plan resout

| Probleme | Solution | Phase |
|----------|----------|-------|
| Securite ouverte | Encrypt, rate limit, sanitize, audit | Phase 0 |
| Backend inconsistant | FormRequests, UseCases, DTOs | Phase 1 |
| UX fragmentee | Composants standards, contrat par page | Phase 2 |
| Produit incomplet | Workflows bout en bout, banners, onboarding | Phase 3 |
| Pas observable | KPIs, exports, forensics, correlation IDs | Phase 4 |

### Principes directeurs

1. **Securite d'abord** — on ne deploie pas de feature si un finding HIGH est ouvert
2. **Backend propre avant UI** — un backend inconsistant genere une UI inconsistante
3. **Contrat UX obligatoire** — chaque page respecte loading/error/empty/feedback
4. **Workflow complet ou pas du tout** — pas de flow partiel en production
5. **Mesurer avant d'optimiser** — KPIs et observabilite avant les features avancees

### Metriques de succes

| Metrique | Etat actuel | Cible |
|----------|-------------|-------|
| Form requests / controllers | 13/121 (10%) | 100% des mutations |
| Pages avec loading state | ~50% | 100% |
| Pages avec empty state | ~20% | 100% |
| Tests billing edge cases | 0 (trial, concurrency, SEPA) | Couverts |
| Bundle CSS | 3.2 MB | < 700 KB |
| Workflows complets | 2/5 | 5/5 |
| window.confirm() usages | ~10 | 0 |
| Pages > 1000 lignes | 5 | 0 |

---

> **Ce plan est un systeme, pas une liste.**
> Chaque phase construit sur la precedente.
> Aucune phase n'est optionnelle.
> Le resultat est un SaaS coherent, predictible, et de confiance.

---
---

# ADDENDUMS v2 — ENRICHISSEMENTS

---

<a id="addendum-a"></a>
## ADDENDUM A — POINTS CRITIQUES AUDIT : FK, SECURITY HEADERS, LOGIC LEAKAGE, TESTS

### A.1 FK / DB Integrity

**Etat actuel** : 84.7% de couverture FK (56 tables OK / 66 tables avec relations)
**Cible** : 95%+

#### Tables avec FK manquantes (10 — a corriger)

| Table | Colonne | Probleme | Migration a creer | Phase |
|-------|---------|----------|-------------------|-------|
| **sessions** | `user_id` | `foreignId` sans `constrained()` → sessions orphelines apres suppression user | `add_fk_sessions_user_id` | 0 |
| **security_alerts** | `actor_id` | `unsignedBigInteger` sans FK | `add_fk_security_alerts_actor` | 0 |
| **security_alerts** | `acknowledged_by` | `unsignedBigInteger` sans FK | (meme migration) | 0 |
| **security_alerts** | `resolved_by` | `unsignedBigInteger` sans FK | (meme migration) | 0 |
| **platform_audit_logs** | `actor_id` | `unsignedBigInteger` sans FK (polymorphe — documenter si intentionnel) | `document_or_fix_audit_actor_fk` | 1 |
| **company_audit_logs** | `actor_id` | Meme probleme | (meme migration) | 1 |
| **support_messages** | `sender_id` | Polymorphe `sender_type`+`sender_id` sans FK | `document_polymorphic_support_sender` | 1 |
| **company_wallet_transactions** | `actor_id` | `unsignedBigInteger` sans FK | `add_fk_wallet_transactions_actor` | 1 |
| **company_wallet_transactions** | `source_id` | Polymorphe `source_type`+`source_id` sans FK | (documenter — polymorphe intentionnel) | 1 |
| **field_definitions** | `company_id` | Ajoute en migration 950002 sans `constrained()` | `add_fk_field_definitions_company` | 1 |
| **notification_events** | `recipient_id` | Polymorphe sans FK | (documenter — polymorphe intentionnel) | 1 |

#### Actions concretes

**Phase 0 (Securite — FK critiques) :**
```
0.9  Migration: sessions.user_id → constrained()->cascadeOnDelete()
     Fichier: database/migrations/YYYY_MM_DD_fix_sessions_user_fk.php

0.10 Migration: security_alerts.actor_id, acknowledged_by, resolved_by → FK vers platform_users
     Fichier: database/migrations/YYYY_MM_DD_fix_security_alerts_fk.php
```

**Phase 1 (Integrite — FK fonctionnelles) :**
```
1.12 Migration: field_definitions.company_id → constrained()->cascadeOnDelete()
     Fichier: database/migrations/YYYY_MM_DD_fix_field_definitions_company_fk.php

1.13 Migration: company_wallet_transactions.actor_id → FK vers users
     Fichier: database/migrations/YYYY_MM_DD_fix_wallet_transactions_actor_fk.php

1.14 ADR: Documenter les colonnes polymorphes intentionnelles
     - platform_audit_logs.actor_id (actor_type = admin|system|webhook)
     - company_audit_logs.actor_id (actor_type = user|system)
     - support_messages.sender_id + sender_type
     - company_wallet_transactions.source_id + source_type
     - notification_events.recipient_id + recipient_type
     Fichier: docs/bmad/04-decisions.md (nouvel ADR)
```

---

### A.2 SecurityHeaders Middleware

**Etat actuel** : 5 headers de securite MANQUANTS

| Header | Status | Risque |
|--------|--------|--------|
| Content-Security-Policy (CSP) | ❌ ABSENT | HIGH — XSS possible |
| Strict-Transport-Security (HSTS) | ❌ ABSENT | HIGH — downgrade HTTPS→HTTP |
| X-Content-Type-Options | ❌ ABSENT | MEDIUM — MIME sniffing |
| Referrer-Policy | ❌ ABSENT | MEDIUM — fuite de referrer |
| Permissions-Policy | ❌ ABSENT | MEDIUM — acces device features |
| X-Frame-Options | ✅ PRESENT | OK — via FrameGuard Laravel |
| Cache-Control | ✅ PRESENT | OK — via NoCacheHeaders middleware |
| X-Correlation-Id | ✅ PRESENT | OK — via CorrelationIdMiddleware |
| X-Build-Version | ✅ PRESENT | OK — via AddBuildVersion |

#### Action concrete

```
0.11 Creer SecurityHeadersMiddleware
     Fichier: app/Http/Middleware/SecurityHeadersMiddleware.php
     Headers:
       - Strict-Transport-Security: max-age=31536000; includeSubDomains (prod only)
       - X-Content-Type-Options: nosniff
       - Referrer-Policy: strict-origin-when-cross-origin
       - Permissions-Policy: geolocation=(), microphone=(), camera=()
       - Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline';
         style-src 'self' 'unsafe-inline' https://fonts.googleapis.com;
         font-src 'self' https://fonts.gstatic.com; img-src 'self' data: https:

0.12 Enregistrer dans bootstrap/app.php
     $middleware->appendToGroup('api', SecurityHeadersMiddleware::class);

0.13 Test: SecurityHeadersTest — verifier presence dans les reponses
     Fichier: tests/Feature/SecurityHeadersTest.php
```

**Phase** : 0 (Securite critique)

---

### A.3 Logic Leakage dans les Controllers Billing

**Etat actuel** : 21/25 controllers billing = PROPRE. 4 controllers avec leakage mineur.

#### Controllers a corriger

| Controller | Methode | Probleme | Extraction cible | Phase |
|------------|---------|----------|------------------|-------|
| `PlatformBillingMetricsController` | `__invoke()` L28-72 | MRR/ARR/churn calcules dans controller | `BillingMetricsCalculationService` | 1 |
| `PlatformBillingMetricsController` | `__invoke()` L101-164 | MRR history + trial conversion rate | (meme service) | 1 |
| `SubscriptionMutationController` | `planChange()` L60-72 | Timing decision (immediate vs end_of_period) dans controller | `PlanChangeTimingResolver` | 1 |
| `CompanyPaymentMethodController` | `destroy()` L76-82 | Promotion default card + min-one-method rule dans controller | `DeletePaymentMethodUseCase` | 1 |
| `CompanyPaymentSetupController` | `confirmSetupIntent()` L166-190 | Extraction donnees Stripe-specific dans controller | `StripePaymentMethodDataExtractor` | 1 |

#### Fichiers a creer

```
1.15 app/Core/Billing/BillingMetricsCalculationService.php
     Methodes: calculateMRR(), calculateARR(), calculateChurnRate(),
               calculateMRRHistory(), calculateTrialConversionRate()

1.16 app/Core/Billing/PlanChangeTimingResolver.php
     Methode: resolveTiming(Subscription, toPlanKey, BillingPolicy): string

1.17 app/Modules/Core/Billing/UseCases/DeletePaymentMethodUseCase.php
     Logique: guard min-one-method, promotion default, delete

1.18 app/Core/Billing/Adapters/StripePaymentMethodDataExtractor.php
     Methode: extractProfileData(SetupIntent): array
```

**Phase** : 1 (Nettoyage domaine)

---

### A.4 Test Coverage Gaps

**Etat actuel** : 1,866 tests, 177 fichiers, 51K lignes. Mais 7 modules critiques avec ZERO tests.

#### Modules avec ZERO tests (CRITIQUE)

| Module | Code existant | Tests | Action | Fichier test a creer | Phase |
|--------|---------------|-------|--------|---------------------|-------|
| **2FA / Two-Factor** | TwoFactorService, TwoFactorController, PlatformTwoFactorController | 0 | CREER suite complete | `tests/Feature/TwoFactorAuthTest.php` (~25 tests) | 0 |
| **Platform Roles CRUD** | RoleController, PermissionController | 0 | CREER suite CRUD | `tests/Feature/PlatformRolesCrudTest.php` (~15 tests) | 1 |
| **Notifications** | NotificationController, NotificationPreferenceController, topics | 0 | CREER suite | `tests/Feature/NotificationSystemTest.php` (~20 tests) | 1 |
| **Audience / Mailing** | 9 classes domain (Subscriber, MailingList, etc.) | 0 | CREER suite | `tests/Feature/AudienceModuleTest.php` (~25 tests) | 1 |
| **Shipments CRUD** | ShipmentController, CreateShipment, ChangeShipmentStatus | 0 | CREER suite | `tests/Feature/ShipmentWorkflowTest.php` (~15 tests) | 1 |
| **Markets CRUD** | MarketCrudController (index, store, update, toggle, etc.) | 0 | CREER suite | `tests/Feature/PlatformMarketsCrudTest.php` (~12 tests) | 1 |
| **Translations / Languages** | TranslationController, LanguageController, matrix | 0 | CREER suite | `tests/Feature/PlatformTranslationsTest.php` (~15 tests) | 1 |

#### Modules avec couverture MINIMALE (a renforcer)

| Module | Tests existants | Ce qui manque | Tests a ajouter | Phase |
|--------|-----------------|---------------|-----------------|-------|
| **Suspension/Reactivation** | ~25 (eparpilles dans billing tests) | Pas de suite isolee, edge cases non couverts | `tests/Feature/CompanySuspensionTest.php` (~15 tests) | 1 |
| **Support tickets** | 13 (SupportTicketTest.php) | Escalation, SLA, search, archive | Enrichir existant (+10 tests) | 1 |
| **Session Governance** | 0 dedie | Timeout, limites simultanees, device management | `tests/Feature/SessionGovernanceTest.php` (~12 tests) | 0 |
| **SEPA payments** | 2 (SepaFirstPaymentFailureTest.php) | Scenarios multiples, constraints ADR-328 | Enrichir existant (+8 tests) | 1 |
| **Realtime subscriptions** | 0 pour filtering/backpressure | Subscription, filtering, reconnect | `tests/Feature/RealtimeSubscriptionTest.php` (~12 tests) | 4 |
| **Documentation feedback** | 0 | Feedback system untested | Enrichir DocumentationModuleTest (+5 tests) | 4 |

#### Actions concretes integrees dans la roadmap

**Phase 0 :**
```
0.14 tests/Feature/TwoFactorAuthTest.php — 25 tests
     (enable, confirm, verify, disable, backup codes, rate limiting, platform 2FA)

0.15 tests/Feature/SessionGovernanceTest.php — 12 tests
     (timeout, keepalive, multi-tab, device tracking)
```

**Phase 1 :**
```
1.19 tests/Feature/PlatformRolesCrudTest.php — 15 tests
1.20 tests/Feature/NotificationSystemTest.php — 20 tests
1.21 tests/Feature/AudienceModuleTest.php — 25 tests
1.22 tests/Feature/ShipmentWorkflowTest.php — 15 tests
1.23 tests/Feature/PlatformMarketsCrudTest.php — 12 tests
1.24 tests/Feature/PlatformTranslationsTest.php — 15 tests
1.25 tests/Feature/CompanySuspensionTest.php — 15 tests
1.26 Enrichir SupportTicketTest.php — +10 tests
1.27 Enrichir SepaFirstPaymentFailureTest.php — +8 tests
```

---

<a id="addendum-b"></a>
## ADDENDUM B — INVENTAIRE PAGE PAR PAGE

### B.1 Company Pages

| # | Fichier | Lignes | Loading | Error | Empty | Title | Breadcrumbs | window.confirm | Validation | Split? | Actions |
|---|---------|--------|---------|-------|-------|-------|-------------|----------------|------------|--------|---------|
| 1 | `pages/dashboard.vue` | 234 | ✅ VProgressCircular | ❌ | ✅ card | ❌ | ❌ | ❌ | ❌ | NON | Ajouter useHead, error state |
| 2 | `pages/home.vue` | 184 | ✅ VProgressCircular | ❌ | ✅ card | ❌ | ❌ | ❌ | ❌ | NON | Ajouter useHead, error state |
| 3 | `company/billing/[tab].vue` | 75 | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | NON | Tab router — ajouter loading systemique |
| 4 | `company/billing/invoices/[id].vue` | 754 | ✅ VSkeletonLoader | ✅ VAlert | ✅ no-payments | ❌ | ❌ | ❌ | ❌ | **OUI** | Split: header + lines + payments + credit notes |
| 5 | `company/billing/pay.vue` | 962 | ✅ VSkeletonLoader | ✅ VAlert x2 | ✅ no-invoices | ❌ | ❌ | ❌ | ✅ Stripe | **OUI** | Split: invoice selection + payment form + confirmation |
| 6 | `company/members/index.vue` | **1197** | ❌ | ✅ VAlert x2 | ✅ table | ❌ | ❌ | **✅ L111** | ✅ fields | **CRITIQUE** | Split: table + quick view + field drawer. Remplacer confirm() |
| 7 | `company/members/[id].vue` | 428 | ✅ VProgressLinear | ❌ | ❌ | ❌ | ❌ | ❌ | ✅ password | NON | Ajouter error/empty state, breadcrumb |
| 8 | `company/modules/index.vue` | **1532** | ✅ VProgressCircular | ✅ VAlert | ✅ tabs | ❌ | ❌ | ❌ | ❌ pricing | **CRITIQUE** | Split: module list + activation dialog + quote dialog + deactivation preview |
| 9 | `company/modules/[key].vue` | 332 | ✅ VProgressCircular | ❌ | ✅ settings | ❌ | ❌ | ❌ | ❌ | NON | Ajouter error state, breadcrumb |
| 10 | `company/profile/[tab].vue` | 100 | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | NON | Tab router — ajouter loading systemique |
| 11 | `company/roles.vue` | ~350 | ✅ inferred | ✅ VAlert | ❌ | ❌ | ❌ | **✅ probable** | ✅ perms | NON | Remplacer confirm(), ajouter empty state |
| 12 | `company/support/index.vue` | ~200 | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ✅ form | NON | Ajouter loading, error, empty states |
| 13 | `company/support/[id].vue` | ~200 | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | NON | Ajouter loading, error, breadcrumb |
| 14 | `company/notifications/index.vue` | ~150 | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | NON | Ajouter loading, error, empty states |
| 15 | `company/audit/index.vue` | ~150 | ✅ ref | ❌ | ❌ | ❌ | ❌ | ❌ | ✅ filter | NON | Ajouter error, empty state |
| 16 | `company/shipments/index.vue` | ~200 | ✅ ref | ✅ catch | ✅ table | ❌ | ❌ | ❌ | ❌ | NON | Ajouter visual loading, breadcrumb |
| 17 | `company/shipments/[id].vue` | ~200 | ✅ ref | ✅ errorMsg | ❌ | ❌ | ❌ | ❌ | ❌ | NON | Ajouter empty state, breadcrumb |
| 18 | `company/my-deliveries/index.vue` | ~150 | ✅ ref | ❌ | ✅ table | ❌ | ❌ | ❌ | ❌ | NON | Ajouter error state |

### B.2 Platform Pages

| # | Fichier | Lignes | Loading | Error | Empty | Title | Breadcrumbs | window.confirm | Validation | Split? | Actions |
|---|---------|--------|---------|-------|-------|-------|-------------|----------------|------------|--------|---------|
| 19 | `platform/index.vue` | ~200 | ✅ statsLoading | ✅ statsError | ❌ | ❌ | ❌ | ❌ | ❌ | NON | Ajouter useHead |
| 20 | `platform/companies/index.vue` | ~300 | ✅ ref | ❌ | ❌ | ❌ | ❌ | **✅ L96** | ❌ | NON | Remplacer confirm(), ajouter empty state |
| 21 | `platform/companies/[id].vue` | ~500 | ✅ ref | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | NON | Ajouter breadcrumb, empty state tabs |
| 22 | `platform/billing/index.vue` | ~300 | ✅ ref | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | NON | Ajouter error state |
| 23 | `platform/billing/invoices/[id].vue` | ~500 | ✅ ref | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | NON | Ajouter error state, breadcrumb |
| 24 | `platform/users/index.vue` | ~250 | ✅ ref + actionLoading | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | NON | Ajouter empty state |
| 25 | `platform/users/[id].vue` | ~400 | ✅ ref | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | NON | Ajouter breadcrumb, error state |
| 26 | `platform/plans/index.vue` | ~250 | ✅ ref | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | NON | Ajouter empty state |
| 27 | `platform/plans/[key].vue` | ~400 | ✅ ref | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | NON | Ajouter breadcrumb, error state |
| 28 | `platform/modules/index.vue` | ~250 | ✅ ref | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | NON | Ajouter empty state |
| 29 | `platform/modules/[key].vue` | **1500** | ✅ ref | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | **CRITIQUE** | Split: overview + config + companies tabs |
| 30 | `platform/jobdomains/index.vue` | ~250 | ✅ ref | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | NON | Ajouter empty state |
| 31 | `platform/jobdomains/[id].vue` | **2516** | ✅ ref | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | **CRITIQUE** | Split: overview + modules + fields + overlays tabs |
| 32 | `platform/roles.vue` | ~200 | ✅ ref | ❌ | ❌ | ❌ | ❌ | ❌ | ✅ perms | NON | Ajouter empty state |
| 33 | `platform/settings/[tab].vue` | ~100 | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | NON | Tab router — loading via sub-components |
| 34 | `platform/support/index.vue` | ~250 | ✅ ref | ❌ | ✅ template | ❌ | ❌ | ❌ | ❌ | NON | OK (bon pattern) |
| 35 | `platform/support/[id].vue` | ~300 | ✅ ref | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | NON | Ajouter breadcrumb |
| 36 | `platform/security/index.vue` | ~200 | ✅ ref | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | NON | Ajouter empty state |
| 37 | `platform/audit/index.vue` | ~200 | ✅ ref | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | NON | Ajouter empty state |
| 38 | `platform/notifications/index.vue` | ~200 | ✅ ref | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | NON | Ajouter empty state |
| 39 | `platform/documentation/index.vue` | ~350 | ✅ ref | ✅ catch | ✅ template | ❌ | ❌ | **✅ L204,263** | ❌ | NON | Remplacer confirm() |
| 40 | `platform/realtime/index.vue` | ~200 | ✅ ref | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | NON | Ajouter empty state |

### B.3 Synthese inventaire

| Critere | Conforme | Non conforme | % |
|---------|----------|--------------|---|
| Loading state (logique) | 32/40 | 8/40 | 80% |
| Loading state (visuel) | 8/40 | 32/40 | **20%** |
| Error state | 8/40 | 32/40 | **20%** |
| Empty state | 12/40 | 28/40 | **30%** |
| Page title (useHead) | 0/40 | 40/40 | **0%** |
| Breadcrumbs | 0/40 | 40/40 | **0%** |
| window.confirm() | 35/40 OK | **5 pages** | 87% |
| Needs split (>1000L) | — | **5 pages** | — |

**Pages a splitter (OBLIGATOIRE) :**
1. `platform/jobdomains/[id].vue` — 2516 lignes
2. `company/modules/index.vue` — 1532 lignes
3. `platform/modules/[key].vue` — 1500 lignes
4. `company/members/index.vue` — 1197 lignes
5. `company/billing/pay.vue` — 962 lignes

**Pages avec window.confirm() (REMPLACER) :**
1. `company/members/index.vue` L111
2. `company/roles.vue` (probable)
3. `platform/companies/index.vue` L96
4. `platform/documentation/index.vue` L204, L263

---

<a id="addendum-c"></a>
## ADDENDUM C — UI SYSTEM CONTRACT (NON NEGOTIABLE)

Ce contrat definit les regles UI obligatoires pour TOUTE page du systeme. Il est applique **avant, pendant et apres** chaque implementation.

### C.1 Layout de page standard

```
┌─────────────────────────────────────────────────┐
│ HEADER                                          │
│  Titre (h5) + Badge(s)          Actions (VBtn)  │
├─────────────────────────────────────────────────┤
│ [VProgressLinear si isLoading]                   │
├─────────────────────────────────────────────────┤
│ CONTENT ZONE                                    │
│                                                 │
│  [VAlert si loadError]                          │
│  [EmptyState si !isLoading && data.length === 0]│
│  [Contenu principal]                            │
│                                                 │
└─────────────────────────────────────────────────┘
```

**Regles :**
- Header = 1 seul `<VCardTitle>` ou `<h5>` + badges + actions (alignes a droite)
- Content zone = toujours dans un `<VCard>` ou `<VRow>`/`<VCol>`
- Actions primaires = `<VBtn color="primary">` en haut a droite
- Actions secondaires = `<VBtn variant="tonal">` ou `<VBtn variant="text">`
- Jamais de bouton sans label (sauf icon-only dans les tables)

### C.2 Tables (VDataTableServer)

```vue
<VCard>
  <!-- Toolbar: filtres + search + actions bulk -->
  <VCardText class="d-flex align-center gap-4">
    <AppTextField v-model="search" :placeholder="t('common.search')" density="compact" />
    <VSpacer />
    <VBtn color="primary" @click="create">{{ t('module.create') }}</VBtn>
  </VCardText>

  <VDivider />

  <!-- Table -->
  <VDataTableServer
    v-model:items-per-page="perPage"
    v-model:page="page"
    :headers="headers"
    :items="items"
    :items-length="total"
    :loading="isLoading"
    @update:options="fetchData"
  >
    <!-- Row actions: toujours un menu 3-dots -->
    <template #item.actions="{ item }">
      <IconBtn>
        <VIcon icon="tabler-dots-vertical" />
        <VMenu activator="parent">
          <VList>
            <VListItem @click="edit(item)">{{ t('common.edit') }}</VListItem>
            <VListItem @click="confirmDelete(item)" class="text-error">{{ t('common.delete') }}</VListItem>
          </VList>
        </VMenu>
      </IconBtn>
    </template>

    <!-- Empty state: TOUJOURS present -->
    <template #no-data>
      <EmptyState :icon="emptyIcon" :title="t('module.noItemsYet')" :action-label="t('module.createFirst')" @action="create" />
    </template>
  </VDataTableServer>

  <!-- Pagination: TOUJOURS presente -->
  <VDivider />
  <TablePagination v-model:page="page" :items-per-page="perPage" :total-items="total" />
</VCard>
```

**Regles tables :**
- TOUJOURS `VDataTableServer` (jamais client-side pour les donnees paginables)
- TOUJOURS `:loading="isLoading"` (affiche la progress bar native Vuetify)
- TOUJOURS un slot `#no-data` avec EmptyState
- TOUJOURS `TablePagination` en footer
- TOUJOURS un filtre search visible
- Actions de ligne = menu `tabler-dots-vertical` (3 dots)
- Actions bulk = boutons dans le toolbar, disabled si selection vide

### C.3 Formulaires

```vue
<VForm ref="form" @submit.prevent="submit">
  <VRow>
    <VCol cols="12" md="6">
      <AppTextField
        v-model="formData.name"
        :label="t('field.name')"
        :rules="[v => !!v || t('validation.required')]"
        :error-messages="serverErrors.name"
      />
    </VCol>
  </VRow>

  <VDivider class="my-4" />

  <div class="d-flex justify-end gap-3">
    <VBtn variant="tonal" @click="cancel">{{ t('common.cancel') }}</VBtn>
    <VBtn type="submit" color="primary" :loading="isSubmitting" :disabled="isSubmitting">
      {{ t('common.save') }}
    </VBtn>
  </div>
</VForm>
```

**Regles formulaires :**
- TOUJOURS `<VForm>` avec `ref="form"` pour validation programmatique
- TOUJOURS `:rules` client-side sur les champs obligatoires
- TOUJOURS `:error-messages="serverErrors.fieldName"` pour les erreurs backend
- TOUJOURS `:loading` sur le bouton submit
- TOUJOURS `:disabled` sur le bouton submit pendant la soumission
- Bouton cancel a GAUCHE, bouton submit a DROITE
- Jamais de submit sans feedback (toast success ou error)
- Layout : `<VRow>` avec `<VCol cols="12" md="6">` par defaut (2 colonnes desktop, 1 mobile)

### C.4 Dialogs de confirmation

```vue
<!-- UNIQUE pattern pour TOUTE action destructive -->
<ConfirmDialog
  v-model:is-dialog-visible="showConfirmDelete"
  :confirmation-question="t('common.confirmDeleteMessage', { name: target?.name })"
  @confirm="executeDelete"
/>
```

**Regles dialogs :**
- **INTERDIT** : `window.confirm()` — TOUJOURS ConfirmDialog Vuetify
- **INTERDIT** : Action destructive sans confirmation
- TOUJOURS mentionner l'element concerne dans la question (nom, identifiant)
- Bouton confirm en rouge (`color="error"`) pour les suppressions
- Bouton confirm en primary pour les actions non-destructives
- TOUJOURS un bouton annuler explicite

### C.5 Feedback (toasts + inline)

| Evenement | Pattern | Duree | Couleur |
|-----------|---------|-------|---------|
| Succes (create, update, delete) | `toast(t('common.saveSuccess'), 'success')` | 4s auto-dismiss | success (vert) |
| Erreur serveur | `toast(error?.data?.message \|\| t('common.operationFailed'), 'error')` | Persist (dismiss manuel) | error (rouge) |
| Erreur validation | `:error-messages` inline sur le champ | Persist | error inline |
| Loading action | `:loading="actionLoading"` sur le bouton | Tant que l'action dure | — |
| Loading page | `<VProgressLinear indeterminate>` | Tant que le fetch dure | primary |

**Regles feedback :**
- JAMAIS d'operation silencieuse (toujours un toast ou feedback inline)
- Les erreurs backend doivent TOUJOURS remonter un message lisible
- Le bouton `:loading` empêche le double-click
- Les erreurs de validation apparaissent SOUS le champ concerne

### C.6 Drawers vs Pages vs Dialogs

| Type d'operation | UI pattern | Quand utiliser |
|------------------|------------|----------------|
| **Create simple** (< 5 champs) | Drawer (VNavigationDrawer) | Membre, role, coupon |
| **Create complexe** (> 5 champs, multi-step) | Page dediee ou wizard | Registration, shipment |
| **Edit inline** | Drawer ou section editable | Profil, settings |
| **Detail** | Page avec tabs | Company 360, member detail |
| **Confirmation** | Dialog (VDialog max-width 500) | Delete, suspend, cancel |
| **Selection** | Dialog (VDialog max-width 800) | Plan change, payment method |

**Regles :**
- Drawer = toujours a droite, largeur 400px desktop / full mobile
- Dialog confirmation = max-width 500px, centree
- Dialog selection = max-width 800px, centree
- Page detail = toujours accessible par URL (bookmarkable)
- JAMAIS de nested dialog (dialog dans un dialog)

### C.7 Densite et espacement

| Contexte | Densite | Spacing |
|----------|---------|---------|
| Formulaires | `density="compact"` sur tous les champs | `<VRow>` gap standard |
| Tables | `density="comfortable"` (defaut VDataTable) | — |
| Cards dashboard | Padding `pa-4` | `<VRow>` avec `<VCol>` gap `ga-4` |
| Toolbars | `density="compact"` sur les inputs de filtre | `gap-4` entre elements |
| Dialogs | `density="default"` | `pa-6` interieur |

**Regles :**
- JAMAIS de padding < 8px (Vuetify `pa-2`)
- JAMAIS de texte < 12px (toujours `text-body-2` minimum)
- Hierarchie visuelle : `text-h5` > `text-h6` > `text-body-1` > `text-body-2` > `text-caption`
- Labels secondaires : `text-medium-emphasis`
- Nombres/montants : `font-weight-semibold`

### C.8 Hierarchie visuelle des pages

| Element | Style |
|---------|-------|
| Titre de page | `<h5 class="text-h5">` ou `<VCardTitle>` |
| Sous-titre | `<span class="text-body-1 text-medium-emphasis">` |
| Section title | `<h6 class="text-h6">` |
| Label formulaire | Via `label` prop de AppTextField |
| Donnee affichee | `<span class="text-body-1">` |
| Donnee secondaire | `<span class="text-body-2 text-medium-emphasis">` |
| Badge/statut | `<VChip size="small" :color="statusColor">` |
| Montant/prix | `<span class="text-body-1 font-weight-semibold">` |

---

<a id="addendum-d"></a>
## ADDENDUM D — BILLING & PAYMENT SYSTEM ALIGNMENT

### D.1 Regles metier billing (SOURCE DE VERITE)

#### Trial

| Regle | Implementation | Etat | Fichier |
|-------|---------------|------|---------|
| Anti-abus : 1 trial par user (cross-company) | `RegisterCompanyUseCase` L40-47 | ✅ OK | `app/Modules/Infrastructure/Auth/UseCases/RegisterCompanyUseCase.php` |
| Duree trial = `plan.trial_days` | Subscription `trial_ends_at = now() + trial_days` | ✅ OK | idem L150-159 |
| Reminder J-3 avant expiration | `BillingCheckTrialExpiringCommand` (daily) | ✅ OK | `app/Console/Commands/BillingCheckTrialExpiringCommand.php` |
| **Trial expire sans paiement** | **AUCUN handler** — status reste `trialing` indefiniment | ⚠️ **MANQUANT** | Creer `BillingExpireTrialsCommand` |
| Trial → Active sur premier renouvellement | `BillingRenewCommand` L323-353 | ✅ OK | `app/Console/Commands/BillingRenewCommand.php` |
| Notification `TrialConverted` | Envoyee au owner | ✅ OK | idem L340-351 |

**Action** : Creer `billing:expire-trials` (Phase 0, tache 1.4 enrichie)
- Trouver subscriptions `trialing` avec `trial_ends_at < now()`
- Transition vers `expired`
- Desactiver modules premium si policy l'exige
- Envoyer notification `TrialExpired`

#### Proration

| Regle | Implementation | Etat | Fichier |
|-------|---------------|------|---------|
| Calcul jour-par-jour, floor (favorable company) | `ProrationCalculator` | ✅ OK | `app/Core/Billing/ProrationCalculator.php` |
| Credit = `floor(daysRemaining / totalDays × oldPrice)` | Deterministe, pur | ✅ OK | idem |
| Charge = `floor(daysRemaining / totalDays × newPrice)` | Deterministe, pur | ✅ OK | idem |
| Timing : immediate / end_of_period / end_of_trial | `PlanChangeExecutor` L44-96 | ✅ OK | `app/Core/Billing/PlanChangeExecutor.php` |
| Addon proration sur desactivation | `CompanyAddonSubscription.proratedCreditCents()` | ✅ OK | `app/Core/Billing/CompanyAddonSubscription.php` L76-90 |
| Snapshot proration dans PlanChangeIntent | Idempotent via `idempotency_key` | ✅ OK | idem L52-57 |

**Impact UX** :
- Le preview DOIT afficher : credit ancien plan, charge nouveau plan, net a payer/crediter, date d'effet
- Le detail proration DOIT apparaitre dans la confirmation avant validation

#### Coupons

| Regle | Implementation | Etat | Fichier |
|-------|---------------|------|---------|
| Types : percentage, fixed_amount | `BillingCoupon` model | ✅ OK | `app/Core/Billing/BillingCoupon.php` |
| Filtres : plan_keys, billing_cycles, addon_keys, addon_mode | Fillable fields | ✅ OK | idem L10-29 |
| Duree : `duration_months` (decremente a chaque renouvellement) | `BillingRenewCommand` L265-271 | ✅ OK | `app/Console/Commands/BillingRenewCommand.php` |
| Limites : `max_uses`, `max_uses_per_company` (defaut 1) | `CouponService.apply()` L37-46 | ✅ OK | `app/Modules/Core/Billing/Services/CouponService.php` |
| First-purchase-only | Validation L54 | ✅ OK | idem |
| **PAS de stacking** | 1 coupon par subscription | ✅ Documente | — |

**Impact UX** :
- Champ coupon dans le checkout avec validation en temps reel (endpoint public existe)
- Affichage du discount sur la ligne facture
- Badge "Coupon actif" dans billing overview avec mois restants

#### Taxes (TVA)

| Regle | Implementation | Etat | Fichier |
|-------|---------------|------|---------|
| Taux = Market.vat_rate_bps (fallback: policy default) | `TaxResolver` | ✅ OK | `app/Core/Billing/TaxResolver.php` |
| Modes : exclusive (B2B) / inclusive | `PlatformBillingPolicy.tax_mode` | ✅ OK | idem L61-64 |
| B2B intra-EU + VAT valide = 0% (reverse charge) | `TaxContextResolver` case 2 | ✅ OK | `app/Modules/Core/Billing/Services/TaxContextResolver.php` |
| B2C intra-EU = taux standard | `TaxContextResolver` case 3 | ✅ OK | idem |
| Extra-EU = 0% (export) | `TaxContextResolver` case 4 | ✅ OK | idem |
| VIES indisponible = assume valide | `TaxContextResolver` case 5 | ✅ OK | idem |
| Validation VIES avec cache 7j | `VatValidationService` | ✅ OK | `app/Modules/Core/Billing/Services/VatValidationService.php` |
| Mention sur facture : `tax_exemption_reason` | Invoice model | ✅ OK | `app/Core/Billing/Invoice.php` |

**Impact UX** :
- Afficher HT + TVA + TTC clairement sur chaque facture
- Mention "Autoliquidation (reverse charge)" si applicable
- Champ VAT number dans le profil company avec validation VIES inline
- Badge "Exonere" si applicable

#### Moyens de paiement

| Regle | Implementation | Etat | Fichier |
|-------|---------------|------|---------|
| Resolution : default-first, puis par ID (ancien d'abord) | `PaymentMethodResolver` | ✅ OK | `app/Core/Billing/PaymentMethodResolver.php` |
| Types : card (Stripe), sepa_debit, wallet | `CompanyPaymentProfile.method_key` | ✅ OK | model |
| SEPA : debit a `preferred_debit_day` | `BillingCollectScheduledCommand` | ✅ OK | `app/Console/Commands/BillingCollectScheduledCommand.php` |
| SEPA 1er paiement echoue : action configurable | `sepa_first_failure_action` dans policy | ✅ OK | `PlatformBillingPolicy` |
| Wallet : full coverage OU split payment (ADR-265) | `DunningRetryStrategy` L208-232 | ✅ OK | `app/Core/Billing/Dunning/DunningRetryStrategy.php` |
| Split = wallet partial + provider remainder | Sequence : wallet d'abord, puis provider | ✅ OK | idem |
| Min 1 payment method actif | Guard dans `CompanyPaymentMethodController` | ⚠️ Dans controller | Extraire vers UseCase (1.17) |

**Impact UX** :
- Afficher chaque methode avec son type (icone carte/SEPA/wallet)
- Indiquer la methode par defaut avec badge
- Permettre de changer la methode par defaut en 1 click
- Expliquer le wallet : "Credits disponibles : X€ — appliques automatiquement sur la prochaine facture"
- Si SEPA : afficher le jour de debit + mandat SEPA signe

#### Dunning (recouvrement)

| Regle | Implementation | Etat | Fichier |
|-------|---------------|------|---------|
| Grace period : `grace_period_days` (configurable) | `DunningEngine` L72 | ✅ OK | `app/Core/Billing/DunningEngine.php` |
| Retries : intervals [1, 3, 7] jours (configurable) | `retry_intervals_days` dans policy | ✅ OK | `PlatformBillingPolicy` L95 |
| Sequence retry : provider → split → wallet | 3 phases dans `DunningRetryStrategy` | ✅ OK | `app/Core/Billing/Dunning/DunningRetryStrategy.php` |
| Max retries : configurable | `max_retry_attempts` | ✅ OK | `DunningEngine` L205 |
| Echec final → suspend OU cancel company | `failure_action` dans policy | ✅ OK | `DunningEngine` |
| Reactivation si plus de factures overdue | `checkReactivation()` bounded check | ✅ OK | idem L221 |

**Impact UX (CRITIQUE — actuellement invisible)** :
- **Banner persistante** dans le layout company si subscription `past_due` :
  "Paiement echoue — Votre dernier paiement a echoue. [Regulariser maintenant]"
- **Badge rouge** sur l'item navigation Facturation
- **Email** a chaque tentative echouee avec lien direct vers la page paiement
- **Timeline** dans billing overview : tentatives de paiement avec statuts
- **Page paiement** : permettre de retenter manuellement avec un autre moyen

#### Factures (clarte utilisateur)

| Element | Present sur la facture | Etat |
|---------|----------------------|------|
| Numero sequentiel (INV-2026-000001) | ✅ | OK |
| Lignes : plan (nom + periode) | ✅ InvoiceLineDescriptor locale-aware | OK |
| Lignes : addons (nom module + periode) | ✅ | OK |
| Lignes : discount (code coupon + montant) | ✅ metadata | OK |
| Lignes : proration (description + dates) | ✅ | OK |
| Sous-total HT | ✅ | OK |
| TVA (taux + montant) | ✅ `tax_rate_bps` + `tax_amount` | OK |
| Mention exoneration (reverse charge, export) | ✅ `tax_exemption_reason` | OK |
| Credit wallet applique | ✅ `wallet_credit_applied` | OK |
| Montant du (TTC - credits) | ✅ `amount_due` | OK |
| Factures annexes (addons) | ✅ `parent_invoice_id` + `annexe_suffix` | OK |

**Impact UX** :
- PDF facture = identique au detail en ligne (pas de divergence)
- Chaque ligne doit etre comprehensible sans jargon technique
- Le wallet credit doit etre explique : "Credit applique (suite a un changement de plan)"
- Le lien vers le paiement doit etre visible si `amount_due > 0` et non paye

### D.2 Checkout Flow Complet (cible)

```
1. SELECTION PLAN
   └─ Page /company/plan.vue ou /company/modules
   └─ Comparaison visuelle des plans + addons
   └─ Prix affiche TTC (ou HT+TVA si B2B)

2. PREVIEW
   └─ Proration calculee (credit ancien + charge nouveau)
   └─ Coupon applique si present
   └─ TVA calculee
   └─ Wallet credit deduit
   └─ = Montant final a payer CLAIREMENT affiche

3. METHODE DE PAIEMENT
   └─ Cartes enregistrees (avec icone type)
   └─ SEPA (si eligible)
   └─ Wallet (si balance suffisante)
   └─ Ajouter nouvelle carte (Stripe Elements inline)

4. CONFIRMATION
   └─ Resume : plan, montant, methode, date d'effet
   └─ Bouton "Confirmer le paiement" (:loading)
   └─ Mentions legales (CGV, politique d'annulation)

5. RESULTAT
   └─ Succes : toast + redirect billing overview + facture generee
   └─ Echec : message d'erreur Stripe traduit + bouton retry
   └─ En attente (SEPA) : message "Paiement en cours de traitement"
```

---

<a id="addendum-e"></a>
## ADDENDUM E — ROADMAP ENRICHIE (TACHES INTEGREES)

Les taches suivantes s'ajoutent aux phases existantes.

### Phase 0 — Taches ajoutees (securite + integrite)

| # | Tache | Source | Impact | Effort | Fichiers |
|---|-------|--------|--------|--------|----------|
| 0.9 | **FK sessions.user_id** → constrained + cascadeOnDelete | Audit FK | Haute | S | `database/migrations/YYYY_fix_sessions_user_fk.php` |
| 0.10 | **FK security_alerts** actor_id, acknowledged_by, resolved_by | Audit FK | Haute | S | `database/migrations/YYYY_fix_security_alerts_fk.php` |
| 0.11 | **SecurityHeadersMiddleware** — CSP, HSTS, X-Content-Type-Options, Referrer-Policy, Permissions-Policy | Audit Security Headers | Haute | M | `app/Http/Middleware/SecurityHeadersMiddleware.php`, `bootstrap/app.php` |
| 0.12 | **Test SecurityHeaders** — verifier presence dans les reponses | Audit Security Headers | Moyenne | S | `tests/Feature/SecurityHeadersTest.php` |
| 0.13 | **Test 2FA suite complete** — 25 tests (enable, confirm, verify, disable, backup, rate limit) | Audit Test Gaps | Haute | M | `tests/Feature/TwoFactorAuthTest.php` |
| 0.14 | **Test Session Governance** — 12 tests (timeout, keepalive, multi-tab) | Audit Test Gaps | Haute | M | `tests/Feature/SessionGovernanceTest.php` |

### Phase 1 — Taches ajoutees (domaine + tests)

| # | Tache | Source | Impact | Effort | Fichiers |
|---|-------|--------|--------|--------|----------|
| 1.12 | **FK field_definitions.company_id** → constrained + cascade | Audit FK | Moyenne | S | `database/migrations/YYYY_fix_field_definitions_fk.php` |
| 1.13 | **FK company_wallet_transactions.actor_id** → FK users | Audit FK | Moyenne | S | `database/migrations/YYYY_fix_wallet_tx_actor_fk.php` |
| 1.14 | **ADR colonnes polymorphes** — documenter actor_id, sender_id, source_id, recipient_id | Audit FK | Basse | S | `docs/bmad/04-decisions.md` |
| 1.15 | **BillingMetricsCalculationService** — extraire MRR/ARR/churn du controller | Audit Logic Leakage | Moyenne | M | `app/Core/Billing/BillingMetricsCalculationService.php` |
| 1.16 | **PlanChangeTimingResolver** — extraire timing decision du controller | Audit Logic Leakage | Moyenne | S | `app/Core/Billing/PlanChangeTimingResolver.php` |
| 1.17 | **DeletePaymentMethodUseCase** — extraire guard + promotion du controller | Audit Logic Leakage | Moyenne | S | `app/Modules/Core/Billing/UseCases/DeletePaymentMethodUseCase.php` |
| 1.18 | **StripePaymentMethodDataExtractor** — extraire parsing Stripe du controller | Audit Logic Leakage | Basse | S | `app/Core/Billing/Adapters/StripePaymentMethodDataExtractor.php` |
| 1.19 | **Test Platform Roles CRUD** — 15 tests | Audit Test Gaps | Haute | M | `tests/Feature/PlatformRolesCrudTest.php` |
| 1.20 | **Test Notification System** — 20 tests (dispatch, preferences, topics) | Audit Test Gaps | Haute | M | `tests/Feature/NotificationSystemTest.php` |
| 1.21 | **Test Audience Module** — 25 tests (subscribe, confirm, unsubscribe, lists) | Audit Test Gaps | Moyenne | M | `tests/Feature/AudienceModuleTest.php` |
| 1.22 | **Test Shipment Workflow** — 15 tests (create, status, assign, track) | Audit Test Gaps | Moyenne | M | `tests/Feature/ShipmentWorkflowTest.php` |
| 1.23 | **Test Platform Markets CRUD** — 12 tests | Audit Test Gaps | Moyenne | S | `tests/Feature/PlatformMarketsCrudTest.php` |
| 1.24 | **Test Platform Translations** — 15 tests (keys, matrix, overrides) | Audit Test Gaps | Moyenne | M | `tests/Feature/PlatformTranslationsTest.php` |
| 1.25 | **Test Company Suspension** — 15 tests (isolee, pas dans billing) | Audit Test Gaps | Moyenne | M | `tests/Feature/CompanySuspensionTest.php` |
| 1.26 | **Enrichir SupportTicketTest** — +10 tests (escalation, SLA, search) | Audit Test Gaps | Basse | S | `tests/Feature/SupportTicketTest.php` |
| 1.27 | **Enrichir SepaTest** — +8 tests (scenarios ADR-328) | Audit Test Gaps | Basse | S | `tests/Feature/SepaFirstPaymentFailureTest.php` |

### Synthese effort mise a jour

| Phase | Taches originales | Taches ajoutees | Total | Effort additionnel |
|-------|-------------------|-----------------|-------|-------------------|
| Phase 0 | 8 | 6 | **14** | +2 semaines |
| Phase 1 | 11 | 16 | **27** | +4 semaines |
| Phase 2 | 12 | 0 | **12** | — |
| Phase 3 | 14 | 0 | **14** | — |
| Phase 4 | 11 | 0 | **11** | — |
| **TOTAL** | **56** | **22** | **78 taches** | **~29 semaines** |

### Metriques de succes mises a jour

| Metrique | Etat actuel | Cible |
|----------|-------------|-------|
| FK coverage | 84.7% | **95%+** |
| Security headers | 3/8 | **8/8** |
| Form requests / controllers mutations | 13/121 (10%) | **100%** |
| Test coverage modules critiques | 5 modules a ZERO | **ZERO module a ZERO** |
| Tests totaux | 1,866 | **2,100+** (~234 nouveaux) |
| Pages avec loading state visuel | 20% | **100%** |
| Pages avec error state | 20% | **100%** |
| Pages avec empty state | 30% | **100%** |
| Page title (useHead) | 0% | **100%** |
| Breadcrumbs (pages depth>1) | 0% | **100%** |
| Bundle CSS | 3.2 MB | **< 700 KB** |
| window.confirm() usages | 5 pages | **0** |
| Pages > 1000 lignes | 5 | **0** |
| Billing controller logic leakage | 4 controllers | **0** |
| Workflows complets | 2/5 | **5/5** |
| Trial sans expiration automatique | ⚠️ BUG | **Corrige** |

---

> **Ce plan est desormais COMPLET.**
> Il couvre : securite, integrite DB, architecture backend, UX page par page,
> UI system contract, billing alignment, et test coverage.
> Il est directement implementable sans angle mort.

---
---

# OPERATIONNEL v3 — TRANSLATION EXECUTOIRE

---

<a id="addendum-f"></a>
## ADDENDUM F — SPRINT PLAN

### Principes de decoupage

- 1 sprint = 2 semaines
- Chaque sprint a un objectif unique et un livrable visible
- Aucune tache ne chevauche 2 sprints (si trop grosse → decoupe)
- Tests et ADR inclus dans le sprint (pas de sprint "tests" separe)

---

### Sprint 0 — SECURITE CRITIQUE (semaines 1-2)

**Objectif** : Eliminer tous les risques de securite HIGH. Le systeme est deployable en confiance.

| Ordre | ID | Tache | Effort |
|-------|-----|-------|--------|
| 1 | 0.1 | SESSION_ENCRYPT=true + SESSION_SECURE_COOKIE=true | S |
| 2 | 0.2 | Verifier encryption credentials Stripe (PlatformPaymentModule) | S |
| 3 | 0.3 | Rate limiting sur /api/company/billing/* | S |
| 4 | 0.4 | Sanitize v-html help center (DOMPurify) | S |
| 5 | 0.5 | Webhook secret validation — throw si absent | S |
| 6 | 0.9 | FK sessions.user_id → constrained + cascadeOnDelete | S |
| 7 | 0.10 | FK security_alerts (actor_id, acknowledged_by, resolved_by) | S |
| 8 | 0.11 | SecurityHeadersMiddleware (CSP, HSTS, X-Content-Type-Options, Referrer-Policy) | M |
| 9 | 0.12 | Test SecurityHeaders — verifier presence dans les reponses | S |
| 10 | 0.6 | Audit mutations admin — AuditLogger sur void/mark-paid/refund | S |

**Livrable visible** : Zero finding HIGH. Headers de securite presents sur chaque reponse. FK critiques corrigees.

**Ce qui change pour le produit** : Rien de visible cote utilisateur — mais le systeme est securise pour la production.

---

### Sprint 1 — SESSION GOVERNANCE + TESTS SECURITE (semaines 3-4)

**Objectif** : Session utilisateur resiliente. 2FA et session governance testes.

| Ordre | ID | Tache | Effort |
|-------|-----|-------|--------|
| 1 | 0.7 | Session keepalive + warning dialog avant expiration | M |
| 2 | 0.8 | Session expired dialog (graceful, pas redirect brutal) | M |
| 3 | 0.13 | Test 2FA suite complete (25 tests) | M |
| 4 | 0.14 | Test Session Governance (12 tests) | M |

**Livrable visible** : L'utilisateur ne perd plus sa session silencieusement. Warning 5min avant expiration. Dialog de reconnexion gracieuse.

**Ce qui change pour le produit** : Plus jamais de "j'ai clique et rien ne se passe". Plus de perte de formulaire en cours.

---

### Sprint 2 — BILLING SAFETY + TRIAL FIX (semaines 5-6)

**Objectif** : Le cycle trial→paid est complet et teste. Les FK billing sont corrigees.

| Ordre | ID | Tache | Effort |
|-------|-----|-------|--------|
| 1 | 1.4 | Trial expiration job (billing:expire-trials command) | M |
| 2 | 1.7 | Test trial → paid conversion end-to-end | S |
| 3 | 1.8 | Test concurrent payment attempts (race condition) | M |
| 4 | 1.10 | Audit & enforce SEPA debit protocol (ADR-328) | S |
| 5 | 1.27 | Enrichir SepaTest (+8 tests) | S |
| 6 | 1.12 | FK field_definitions.company_id → constrained + cascade | S |
| 7 | 1.13 | FK company_wallet_transactions.actor_id → FK users | S |
| 8 | 1.14 | ADR colonnes polymorphes (documenter decisions) | S |

**Livrable visible** : Les trials expirent automatiquement. Pas de companies en trial infini. SEPA enforce.

**Ce qui change pour le produit** : Revenue protegee — chaque company paie ou est expiree. Integrite DB renforcee.

---

### Sprint 3 — LOGIC EXTRACTION + TESTS MODULES CRITIQUES (semaines 7-8)

**Objectif** : Business logic hors des controllers. Modules critiques testes.

| Ordre | ID | Tache | Effort |
|-------|-----|-------|--------|
| 1 | 1.15 | BillingMetricsCalculationService (extraire du controller) | M |
| 2 | 1.16 | PlanChangeTimingResolver (extraire du controller) | S |
| 3 | 1.17 | DeletePaymentMethodUseCase (extraire du controller) | S |
| 4 | 1.18 | StripePaymentMethodDataExtractor (extraire du controller) | S |
| 5 | 1.5 | Credit note automatique sur downgrade | M |
| 6 | 1.6 | Webhook recovery poller | M |
| 7 | 1.9 | Test webhook failure & replay | S |
| 8 | 1.11 | Clean dead state "pending" | S |

**Livrable visible** : 0 logic leakage dans les controllers billing. Credit notes automatiques. Recovery webhook autonome.

**Ce qui change pour le produit** : Downgrade = credit instantane. Webhooks rates = rattrapes automatiquement.

---

### Sprint 4 — TESTS COVERAGE BLITZ (semaines 9-10)

**Objectif** : Combler les 7 modules a ZERO tests. Coverage > 2000 tests.

| Ordre | ID | Tache | Effort |
|-------|-----|-------|--------|
| 1 | 1.19 | Test Platform Roles CRUD (15 tests) | M |
| 2 | 1.20 | Test Notification System (20 tests) | M |
| 3 | 1.21 | Test Audience Module (25 tests) | M |
| 4 | 1.22 | Test Shipment Workflow (15 tests) | M |
| 5 | 1.23 | Test Platform Markets CRUD (12 tests) | S |
| 6 | 1.24 | Test Platform Translations (15 tests) | M |
| 7 | 1.25 | Test Company Suspension (15 tests) | M |
| 8 | 1.26 | Enrichir SupportTicketTest (+10 tests) | S |

**Livrable visible** : Zero module sans tests. Suite complete > 2100 tests.

**Ce qui change pour le produit** : Confiance deploiement — chaque module est couvert. Regression impossible sur les fonctionnalites critiques.

---

### Sprint 5 — FONDATION UX (semaines 11-12)

**Objectif** : Les composants UX de base existent et sont deployes sur les pages critiques.

| Ordre | ID | Tache | Effort |
|-------|-----|-------|--------|
| 1 | 2.1 | Composable useAsyncState() (loading, error, data, retry) | M |
| 2 | 2.3 | Composant EmptyState reutilisable | S |
| 3 | 2.4 | Composant ErrorState reutilisable | S |
| 4 | 2.2 | VProgressLinear systemique dans AppBar | S |
| 5 | 2.5 | Remplacer window.confirm() → ConfirmDialog partout | S |
| 6 | 2.6 | i18n ConfirmDialog (boutons traduits) | S |
| 7 | 2.11 | Bundle CSS purge (icons 2.85MB → purge unused) | M |
| 8 | 2.9 | Supprimer pages mortes (settings stub, account-settings legacy) | S |
| 9 | 2.10 | definePage() sur les 4 pages manquantes | S |

**Livrable visible** : Progress bar sur chaque chargement. Empty states avec guidance. Plus de window.confirm() natif. Bundle CSS divise par 4+.

**Ce qui change pour le produit** : Le produit ne "clignote" plus. Chaque ecran vide guide l'utilisateur. Chargement percu divise par 2.

---

### Sprint 6 — UX SYSTEMATIQUE (semaines 13-14)

**Objectif** : Toutes les pages respectent le contrat UX. Titres et breadcrumbs partout.

| Ordre | ID | Tache | Effort |
|-------|-----|-------|--------|
| 1 | 2.7 | useHead() sur toutes les pages (40 pages) | M |
| 2 | 2.8 | Breadcrumbs sur pages depth > 1 (~25 pages) | M |
| 3 | 2.12 | Validation client sur formulaires critiques (register, billing, member) | M |
| 4 | 1.1-a | FormRequest batch 1 — billing controllers (9 controllers) | M |

**Livrable visible** : Chaque page a un titre dans l'onglet navigateur. Navigation contextuelle via breadcrumbs. Validation instant sur les formulaires cles.

**Ce qui change pour le produit** : L'utilisateur sait toujours ou il est. Les erreurs de saisie sont attrapees avant le submit.

---

### Sprint 7 — WORKFLOWS BILLING + ONBOARDING (semaines 15-16)

**Objectif** : Les 3 workflows critiques (trial, plan change, dunning) sont complets et visibles.

| Ordre | ID | Tache | Effort |
|-------|-----|-------|--------|
| 1 | 3.2 | Trial banner permanent (jours restants + CTA) | S |
| 2 | 3.5 | Dunning banner ("Paiement echoue" + lien regulariser) | S |
| 3 | 3.1 | Onboarding widget dashboard (checklist 4 steps) | M |
| 4 | 3.3 | Billing timeline (composant visuel events/invoices/payments) | M |
| 5 | 3.4 | Next invoice preview widget dans billing overview | S |
| 6 | 3.7 | Proration detail dans confirmation plan change | S |
| 7 | 3.8 | Wallet section expliquee (origine credits, utilisation) | S |

**Livrable visible** : Banner trial avec compte a rebours. Banner dunning rouge si paiement echoue. Timeline billing lisible. Onboarding guide actif.

**Ce qui change pour le produit** : L'utilisateur comprend ou il en est financierement. Le trial est visible. Le dunning est actionnable. L'onboarding guide les premiers pas.

---

### Sprint 8 — NAVIGATION + COHERENCE PRODUIT (semaines 17-18)

**Objectif** : Navigation restructuree. Pages monolithiques decoupees.

| Ordre | ID | Tache | Effort |
|-------|-----|-------|--------|
| 1 | 3.12 | Reorganiser navigation company (hierarchie definie) | M |
| 2 | 3.13 | Reorganiser navigation platform (hierarchie definie) | M |
| 3 | 3.14-a | Split platform/jobdomains/[id].vue (2516L → 4 sub-components) | M |
| 4 | 3.14-b | Split company/modules/index.vue (1532L → 3 sub-components) | M |
| 5 | 3.14-c | Split company/members/index.vue (1197L → 3 sub-components) | S |
| 6 | 3.6 | Plan comparison page enrichie | M |

**Livrable visible** : Navigation claire avec sections logiques. Plus de pages monolithiques. Comparaison de plans lisible.

**Ce qui change pour le produit** : L'utilisateur trouve ce qu'il cherche en 1 click. Les pages chargent plus vite (composants lazy). L'admin platform ne se perd plus.

---

### Sprint 9 — SUPPORT + COMPLIANCE + NOTIFICATIONS (semaines 19-20)

**Objectif** : Workflows secondaires complets. Notification center enrichi.

| Ordre | ID | Tache | Effort |
|-------|-----|-------|--------|
| 1 | 3.9 | Support status tracker (timeline ouvert→resolu) | M |
| 2 | 3.10 | Document compliance dashboard (taux, deadlines) | M |
| 3 | 3.11 | Notification center enrichi (groupement, batch read) | M |
| 4 | 3.14-d | Split company/billing/pay.vue (962L → 2 sub-components) | S |
| 5 | 3.14-e | Split platform/modules/[key].vue (1500L → 3 sub-components) | M |

**Livrable visible** : Support avec timeline visible. Dashboard compliance pour les documents. Notifications groupees et lisibles.

**Ce qui change pour le produit** : Chaque workflow est complet de bout en bout. 5/5 workflows termines. Zero page > 1000 lignes.

---

### Sprint 10 — COMPLETION SYSTEME (semaines 21-22)

**Objectif** : Emails transactionnels, KPIs admin, billing store split.

| Ordre | ID | Tache | Effort |
|-------|-----|-------|--------|
| 1 | 4.1 | Emails transactionnels (trial J-7/J-3/J-1, welcome, plan change) | M |
| 2 | 4.2 | Admin dashboard KPIs (MRR, ARR, churn, trial conversion) | M |
| 3 | 4.6 | Billing store split (1 store = 1 concern) | M |
| 4 | 4.11 | Data retention policy documentee | S |

**Livrable visible** : Emails automatiques sur les evenements cles. Dashboard admin avec metriques business. Store billing maintenable.

**Ce qui change pour le produit** : L'admin voit son business en un coup d'oeil. Les utilisateurs recoivent des emails au bon moment.

---

### Sprint 11 — OBSERVABILITE + POLISH (semaines 23-24)

**Objectif** : Observabilite production. Export CSV. Dernieres taches.

| Ordre | ID | Tache | Effort |
|-------|-----|-------|--------|
| 1 | 4.3 | Export CSV generalise (companies, users, invoices, audit) | M |
| 2 | 4.4 | Correlation IDs en production | M |
| 3 | 4.5 | Financial forensics dashboard (alertes drift) | M |
| 4 | 4.10 | Satisfaction survey post-resolution support | S |

**Livrable visible** : Export CSV depuis chaque liste admin. Traces de debug en production. Dashboard financier forensics.

---

### Sprint 12 — SCALE + DERNIERS ITEMS (semaines 25-26+)

**Objectif** : Queue async, bulk actions, FormRequest completion.

| Ordre | ID | Tache | Effort |
|-------|-----|-------|--------|
| 1 | 4.7 | Queue jobs billing (async invoice, auto-charge, emails) | L |
| 2 | 4.8 | Bulk actions admin (suspend/reactivate/notify) | M |
| 3 | 4.9 | Audit company timeline visuelle | M |
| 4 | 1.1-b | FormRequest batch 2 — remaining controllers | L |
| 5 | 1.2 | UseCase extraction restante | L |
| 6 | 1.3 | Response DTO wrapper | M |

**Livrable visible** : Billing async (pas de timeout sur grosses operations). Actions bulk admin. FormRequests sur 100% des mutations.

---

### Vue calendrier

```
S0  [sem 1-2]   Securite critique          ████████░░░░░░░░░░░░░░░░░░
S1  [sem 3-4]   Session + Tests securite   ░░░░████░░░░░░░░░░░░░░░░░░
S2  [sem 5-6]   Billing safety + Trial     ░░░░░░░░████░░░░░░░░░░░░░░
S3  [sem 7-8]   Logic extraction           ░░░░░░░░░░░░████░░░░░░░░░░
S4  [sem 9-10]  Tests coverage blitz       ░░░░░░░░░░░░░░░░████░░░░░░
S5  [sem 11-12] Fondation UX              ░░░░░░░░░░░░░░░░░░░░████░░
S6  [sem 13-14] UX systematique           ░░░░░░░░░░░░░░░░░░░░░░░░██
S7  [sem 15-16] Workflows billing          ████████████████████████████
S8  [sem 17-18] Navigation + coherence     ████████████████████████████
S9  [sem 19-20] Support + compliance       ████████████████████████████
S10 [sem 21-22] Completion systeme         ████████████████████████████
S11 [sem 23-24] Observabilite              ████████████████████████████
S12 [sem 25-26] Scale + derniers items     ████████████████████████████
```

---

<a id="addendum-g"></a>
## ADDENDUM G — ORDRE D'EXECUTION STRICT (Phase 0 + Phase 1)

### BMAD Compliance Gate

> **REGLE** : Chaque tache est executee dans cet ordre EXACT.
> Aucune tache ne peut commencer avant que la precedente soit terminee ET ses tests soient verts.
> Exception : les taches marquees `[PARALLEL]` peuvent etre executees en parallele avec la precedente.

### Phase 0 — Execution sequentielle stricte

```
JOUR 1
  #01  0.1  — .env.production : SESSION_ENCRYPT=true, SESSION_SECURE_COOKIE=true
             Fichier : .env.production
             Test : verifier en staging que les cookies sont encrypted + secure
             ADR : non necessaire (configuration)

  #02  0.2  — Verifier encryption at-rest des credentials Stripe
             Fichier : app/Core/Billing/PlatformPaymentModule.php (verifier $casts encrypted)
             Si absent : ajouter cast 'encrypted' sur la colonne credentials
             Test : PlatformPaymentModuleTest — asserter que credentials ne sont pas en clair en DB
             ADR : non necessaire si cast suffit

JOUR 2
  #03  0.3  — Rate limiting /api/company/billing/*
             Fichier : routes/company.php — ajouter ->middleware('throttle:60,1') au group billing
             Test : asserter 429 apres 61 requetes
             ADR : non necessaire (middleware standard)

  #04  0.4  — Sanitize v-html dans help center
             Fichier : resources/js/pages/help-center/[topicSlug]/[articleSlug].vue
             Action : npm install dompurify → v-html="sanitize(article.content)"
             Test : verifier que <script> tags sont strippes
             ADR : non necessaire (securite standard)

  #05  0.5  [PARALLEL avec #04] — Webhook secret validation
             Fichier : app/Core/Billing/Adapters/StripePaymentAdapter.php L244
             Action : remplacer fallback null par throw InvalidArgumentException
             Test : StripeWebhookSecurityTest — asserter exception si secret absent
             ADR : non necessaire

JOUR 3
  #06  0.9  — FK sessions.user_id
             Fichier : database/migrations/2026_03_18_000001_fix_sessions_user_fk.php
             Action : $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete()
             Test : php artisan migrate + verifier FK en DB
             ADR : non necessaire (correction integrite)

  #07  0.10 [PARALLEL avec #06] — FK security_alerts
             Fichier : database/migrations/2026_03_18_000002_fix_security_alerts_fk.php
             Action : FK actor_id → platform_users, acknowledged_by → platform_users, resolved_by → platform_users
             Test : php artisan migrate
             ADR : non necessaire

JOUR 4-5
  #08  0.11 — SecurityHeadersMiddleware
             Fichier creer : app/Http/Middleware/SecurityHeadersMiddleware.php
             Fichier modifier : bootstrap/app.php (appendToGroup 'api')
             Headers : HSTS (prod only), X-Content-Type-Options, Referrer-Policy, Permissions-Policy, CSP
             ADR : oui — ADR-3XX "Security Headers Middleware"

  #09  0.12 [PARALLEL avec #08] — Test SecurityHeaders
             Fichier creer : tests/Feature/SecurityHeadersTest.php
             Tests : assertHeader present sur response GET /api/public/version
             ~8 tests (1 par header)

JOUR 6
  #10  0.6  — Audit mutations admin
             Fichiers modifier :
               app/Modules/Platform/Billing/Http/PlatformInvoiceMutationController.php (void, markPaidOffline)
               app/Modules/Platform/Billing/Http/PlatformAdvancedMutationController.php (refund, writeOff)
             Action : injecter AuditLogger, appeler auditLog() apres chaque mutation
             Test : asserter audit log cree apres chaque action
             ADR : non necessaire (enrichissement existant)

JOUR 7-8
  #11  0.7  — Session keepalive + warning dialog
             Backend :
               Fichier creer : routes/api.php → GET /api/session/heartbeat (renew session)
             Frontend :
               Fichier creer : resources/js/composables/useSessionKeepalive.js
               Fichier creer : resources/js/components/dialogs/SessionWarningDialog.vue
               Fichier modifier : resources/js/layouts/default.vue (monter le composable)
               Fichier modifier : resources/js/layouts/platform.vue (idem)
             ADR : ADR-070 (deja propose)

JOUR 9-10
  #12  0.8  — Session expired dialog
             Fichier creer : resources/js/components/dialogs/SessionExpiredDialog.vue
             Fichier modifier : resources/js/plugins/1.router/guards.js (intercepter 401 → dialog au lieu de redirect)
             Test : E2E — verifier que 401 affiche le dialog et non un redirect
             ADR : ADR-070 (suite)

JOUR 11-12
  #13  0.13 — Test 2FA suite complete
             Fichier creer : tests/Feature/TwoFactorAuthTest.php
             25 tests : enable, confirm, verify, disable, regenerate backup, rate limit verify,
                        platform enable, platform confirm, platform verify, platform disable
             ADR : non necessaire (tests d'existant)

JOUR 13-14
  #14  0.14 — Test Session Governance
             Fichier creer : tests/Feature/SessionGovernanceTest.php
             12 tests : heartbeat renew, timeout detection, TTL header, multi-tab sync
             ADR : non necessaire
```

### Phase 1 — Execution sequentielle stricte

```
JOUR 15-17
  #15  1.4  — Trial expiration job
             Fichier creer : app/Console/Commands/BillingExpireTrialsCommand.php
             Fichier modifier : routes/console.php (schedule daily)
             Logique : trouver trialing + trial_ends_at < now() → transition expired
             Test : BillingTrialExpirationTest — 8 tests
             ADR : ADR-3XX "Trial Auto-Expiration"

  #16  1.7  [DEPEND de #15] — Test trial → paid conversion E2E
             Fichier creer : tests/Feature/TrialConversionE2ETest.php
             5 tests : register → trial → expire / register → trial → pay → active

JOUR 18-19
  #17  1.8  — Test concurrent payment attempts
             Fichier creer : tests/Feature/ConcurrentPaymentTest.php
             8 tests : double submit, race condition checkout, idempotency guard
             ADR : non necessaire

  #18  1.10 [PARALLEL avec #17] — Audit SEPA protocol
             Fichier auditer : app/Core/Billing/PlatformBillingPolicy.php (sepa_requires_trial)
             Fichier auditer : app/Console/Commands/BillingCollectScheduledCommand.php
             Test : enrichir SepaFirstPaymentFailureTest +8 tests (1.27)
             ADR : mettre a jour ADR-328 si necessaire

JOUR 20-21
  #19  1.12 — FK field_definitions.company_id
             Migration + test migrate

  #20  1.13 [PARALLEL avec #19] — FK company_wallet_transactions.actor_id
             Migration + test migrate

  #21  1.14 [PARALLEL avec #19] — ADR colonnes polymorphes
             Fichier modifier : docs/bmad/04-decisions.md
             Documenter : actor_id, sender_id, source_id, recipient_id (intentionnel)

JOUR 22-24
  #22  1.15 — BillingMetricsCalculationService
             Fichier creer : app/Core/Billing/BillingMetricsCalculationService.php
             Fichier modifier : app/Modules/Platform/Billing/Http/PlatformBillingMetricsController.php
             Strategie : creer service → migrer methodes → controller appelle service
             Test : unit test du service + verifier que le controller retourne le meme resultat
             ADR : ADR-3XX "Billing Metrics Service Extraction"

  #23  1.16 [PARALLEL avec #22] — PlanChangeTimingResolver
             Fichier creer : app/Core/Billing/PlanChangeTimingResolver.php
             Fichier modifier : app/Modules/Core/Billing/Http/SubscriptionMutationController.php
             Test : unit test du resolver

  #24  1.17 [PARALLEL avec #22] — DeletePaymentMethodUseCase
             Fichier creer : app/Modules/Core/Billing/UseCases/DeletePaymentMethodUseCase.php
             Fichier modifier : app/Modules/Core/Billing/Http/CompanyPaymentMethodController.php

  #25  1.18 [PARALLEL avec #22] — StripePaymentMethodDataExtractor
             Fichier creer : app/Core/Billing/Adapters/StripePaymentMethodDataExtractor.php
             Fichier modifier : app/Modules/Core/Billing/Http/CompanyPaymentSetupController.php

JOUR 25-26
  #26  1.5  — Credit note automatique sur downgrade
             Fichier modifier : app/Core/Billing/PlanChangeExecutor.php
             Action : apres wallet credit, appeler CreditNoteIssuer::issueForDowngrade()
             Test : PlanChangeExecutorTest — asserter credit note cree
             ADR : ADR-3XX "Automatic Credit Note on Downgrade"

  #27  1.6  — Webhook recovery poller
             Fichier creer : app/Console/Commands/BillingPollMissedEventsCommand.php
             Action : query Stripe API pour events des 24h, comparer avec reçus, replayer manquants
             Test : StripeWebhookSyncTest enrichi
             ADR : mettre a jour ADR-345

JOUR 27
  #28  1.9  [DEPEND de #27] — Test webhook failure & replay
             Enrichir tests existants

  #29  1.11 — Clean dead state "pending"
             Auditer Subscription model → si "pending" jamais utilise, supprimer de l'enum
             Si utilise quelque part, documenter dans ADR
             Test : verifier qu'aucun test ne reference "pending" status

JOUR 28-36 (Sprint 4)
  #30-#37  1.19 a 1.26 — Tests coverage blitz (voir Sprint 4)
             Ordre : Roles → Notifications → Audience → Shipments → Markets → Translations → Suspension → Support
```

---

<a id="addendum-h"></a>
## ADDENDUM H — REFACTOR SAFETY STRATEGY

### Contrainte architecturale NON NEGOCIABLE

> Toute modification DOIT respecter :
> - **BMAD** : Business → Domain → Architecture → Decisions → UI → Implementation
> - **Separation** : Core (logique pure) / Modules (orchestration) / Infrastructure (technique)
> - **ReadModels** : seule source de verite pour la lecture UI
> - **Controllers** : passifs (validation + dispatch)
> - **Frontend** : zero logique metier, consommation de ReadModels uniquement
> - **ADR** : toute decision structurelle = nouvel ADR avant implementation

### H.1 Strategie UseCase extraction (SANS casser les controllers)

**Principe** : Strangler Fig Pattern — on enveloppe, on ne remplace pas.

```
ETAPE 1 : Creer le UseCase/Service dans le bon module
  app/Core/Billing/BillingMetricsCalculationService.php  (Core = logique pure)
  app/Modules/Core/Billing/UseCases/DeletePaymentMethodUseCase.php  (Module = orchestration)

ETAPE 2 : Migrer la logique du controller vers le UseCase
  - Copier la logique
  - Adapter les signatures (recevoir un DTO, pas une Request)
  - Ajouter les tests unitaires du UseCase

ETAPE 3 : Controller appelle le UseCase
  // AVANT (logic dans controller)
  public function destroy(Request $request, $id) {
      $profile = CompanyPaymentProfile::findOrFail($id);
      if ($company->paymentProfiles()->count() <= 1) abort(422);
      // ... promotion logic ...
      $profile->delete();
  }

  // APRES (controller passif)
  public function destroy(DeletePaymentMethodRequest $request, $id) {
      $this->deletePaymentMethod->execute(
          new DeletePaymentMethodData(profileId: $id, companyId: $request->company()->id)
      );
      return response()->json(['message' => 'deleted']);
  }

ETAPE 4 : Verifier que les tests existants passent toujours
  - php artisan test --filter=PaymentMethod
  - Si un test casse → le UseCase a un bug, pas le controller

ETAPE 5 : Supprimer l'ancien code du controller
  - Seulement apres que TOUS les tests sont verts
```

**Regle BMAD** :
- UseCase dans `app/Modules/{scope}/{Module}/UseCases/` si c'est un cas d'usage metier
- Service dans `app/Core/{Domain}/` si c'est de la logique pure reutilisable
- JAMAIS de UseCase dans `app/Core/` (Core = pas d'orchestration)
- JAMAIS de logique metier dans un Service d'Infrastructure

**Placement des extractions planifiees** :

| Extraction | Emplacement | Justification |
|------------|-------------|---------------|
| `BillingMetricsCalculationService` | `app/Core/Billing/` | Logique pure, calculs deterministes |
| `PlanChangeTimingResolver` | `app/Core/Billing/` | Logique pure, resolution sans side-effect |
| `DeletePaymentMethodUseCase` | `app/Modules/Core/Billing/UseCases/` | Orchestration metier (guard + delete + promotion) |
| `StripePaymentMethodDataExtractor` | `app/Core/Billing/Adapters/` | Adaptation technique Stripe-specific |

---

### H.2 Strategie split pages >1000 lignes (SANS casser le routing)

**Principe** : Extraction de sub-components, pas de rearchitecture.

```
ETAPE 1 : Identifier les blocs autonomes dans la page
  Exemple company/modules/index.vue (1532L) :
    - Bloc A : Liste des modules actifs (tab 1)
    - Bloc B : Liste des modules disponibles (tab 2)
    - Bloc C : Dialog activation + confirmation
    - Bloc D : Dialog deactivation preview

ETAPE 2 : Creer les sub-components (prefixe _)
  company/modules/_ModuleActiveList.vue      (Bloc A)
  company/modules/_ModuleAvailableList.vue   (Bloc B)
  company/modules/_ModuleActivationDialog.vue (Bloc C)
  company/modules/_ModuleDeactivationDialog.vue (Bloc D)

  Convention BMAD-UI-001 : prefixe _ = pas une page, pas de definePage()

ETAPE 3 : La page index.vue devient un orchestrateur leger
  <template>
    <ModuleActiveList v-if="activeTab === 'active'" :modules="activeModules" />
    <ModuleAvailableList v-if="activeTab === 'available'" :modules="availableModules" />
    <ModuleActivationDialog v-model:visible="showActivation" :module="selectedModule" />
    <ModuleDeactivationDialog v-model:visible="showDeactivation" :module="selectedModule" />
  </template>

ETAPE 4 : Props down, events up
  - Sub-component recoit les donnees via props (jamais d'appel API direct)
  - Sub-component emet des events (@activate, @deactivate, @confirm)
  - La page gere les appels API et les transitions d'etat
  → ZERO logique metier dans les sub-components (presentation uniquement)

ETAPE 5 : Verifier
  - La route ne change pas (unplugin-vue-router voit toujours index.vue)
  - Les _ sub-components ne generent pas de routes (convention respectee)
  - Le comportement est identique
```

**PIEGE BMAD-UI-001 rappel** : JAMAIS `modules.vue` + `modules/` coexistants. Toujours `modules/index.vue`.

**Ordre de split** :
1. `platform/jobdomains/[id].vue` (2516L) → 4 sub-components : _Overview, _Modules, _Fields, _Overlays
2. `company/modules/index.vue` (1532L) → 3 sub-components : _ActiveList, _AvailableList, _Dialogs
3. `platform/modules/[key].vue` (1500L) → 3 sub-components : _Overview, _Config, _Companies
4. `company/members/index.vue` (1197L) → 3 sub-components : _MemberTable, _QuickView, _FieldDrawer
5. `company/billing/pay.vue` (962L) → 2 sub-components : _InvoiceSelection, _PaymentForm

---

### H.3 Strategie UI System Contract (SANS tout casser)

**Principe** : Adoption progressive, page par page, pas de big bang.

```
PHASE A — Creer les composants reutilisables (Sprint 5)
  resources/js/components/states/EmptyState.vue
  resources/js/components/states/ErrorState.vue
  resources/js/composables/useAsyncState.js

  → Aucune page existante n'est modifiee. Les composants sont juste disponibles.

PHASE B — Deployer sur les pages NOUVELLES d'abord
  Chaque nouvelle page ou sub-component cree DOIT utiliser le UI System Contract.
  → Pas de dette sur le nouveau code.

PHASE C — Migrer les pages existantes par batch (Sprint 6-9)
  Batch 1 (Sprint 6) : Pages les plus visitees — dashboard, billing, members
  Batch 2 (Sprint 7) : Pages billing — overview, invoices, pay
  Batch 3 (Sprint 8) : Pages platform — companies, users, plans
  Batch 4 (Sprint 9) : Pages restantes

  Pour chaque page migree :
    1. Ajouter useHead() (titre)
    2. Ajouter VProgressLinear (loading)
    3. Ajouter EmptyState (si applicable)
    4. Ajouter ErrorState avec retry
    5. Remplacer window.confirm si present
    6. Verifier responsive (breakpoints Vuetify)
```

**JAMAIS :** modifier `@core/` ou `@layouts/` (politique UI Vuexy)

---

### H.4 Strategie deploiement sans bloquer la prod

**Principe** : Chaque sprint produit un deployable. Pas de "branch longue".

```
REGLE 1 : Chaque tache = 1 commit atomique
  - Migration + code + test = 1 commit
  - Si le test casse, le commit n'est pas pousse

REGLE 2 : Chaque sprint = 1 PR vers dev
  - dev = staging (dev.leezr.com)
  - Validation en staging avant merge main

REGLE 3 : Migrations non-destructives UNIQUEMENT
  - Ajouter colonne : OK
  - Ajouter FK : OK (si colonne existante a des valeurs valides)
  - Supprimer colonne : JAMAIS en premiere migration
    → D'abord : arreter de l'utiliser dans le code
    → Ensuite : supprimer dans un sprint suivant

REGLE 4 : Pas de feature flags
  - Le code est toujours actif ou absent
  - Si une feature n'est pas prete, elle n'est pas mergee
  - Exception : session keepalive peut etre derriere un config (SESSION_KEEPALIVE_ENABLED)

REGLE 5 : Rollback possible
  - Chaque migration doit avoir un down()
  - deploy/rollback.sh est toujours fonctionnel
  - Si une migration echoue en prod → rollback immediat
```

---

### H.5 ADR Compliance Checklist

Avant chaque implementation, verifier :

```
□ La tache respecte Core / Modules / Infrastructure ?
□ La logique metier est dans Core ou Modules/UseCases, JAMAIS dans un controller ?
□ Les lectures passent par un ReadModel, JAMAIS par un Model direct ?
□ Le frontend ne contient ZERO logique metier ?
□ Les ADR existantes ne sont pas contredites ?
□ Si changement structurel → nouvel ADR cree AVANT l'implementation ?
□ La tache est multi-tenant safe (company_id scope verifie) ?
□ Le test couvre le cas nominal ET au moins 1 cas d'erreur ?
```

---

<a id="addendum-i"></a>
## ADDENDUM I — DEFINITION OF DONE

### Definition of Done GLOBALE (fin de refondation)

| Critere | Mesure | Cible |
|---------|--------|-------|
| **Securite** | Zero finding HIGH/MEDIUM ouvert | ✅ |
| **Security Headers** | 8/8 headers presents sur chaque reponse | 8/8 |
| **FK Integrity** | Couverture FK | > 95% |
| **Controller purity** | Controllers avec business logic | 0 |
| **FormRequests** | Mutations avec FormRequest dedie | 100% |
| **Test coverage** | Modules avec ZERO tests | 0 |
| **Test count** | Nombre total de tests | > 2100 |
| **Loading states** | Pages avec loading visuel | 100% |
| **Error states** | Pages avec error state + retry | 100% |
| **Empty states** | Pages avec empty state contextuel | 100% |
| **Page titles** | Pages avec useHead() | 100% |
| **Breadcrumbs** | Pages depth>1 avec breadcrumbs | 100% |
| **window.confirm** | Usages natifs | 0 |
| **Page size** | Pages > 1000 lignes | 0 |
| **Bundle CSS** | Taille | < 700 KB |
| **Workflows** | Workflows complets (trial, plan, addon, support, compliance) | 5/5 |
| **Trial bug** | Trial sans expiration automatique | Corrige |
| **Billing visibility** | Timeline, dunning banner, wallet explique | Deploye |
| **Navigation** | Sections structurees, zero ghost link | Deploye |
| **ADR** | Decisions documentees | Toutes |

---

### Definition of Done par SPRINT

#### Sprint 0 — Securite critique
```
□ SESSION_ENCRYPT=true en production (verifiable via staging)
□ SESSION_SECURE_COOKIE=true en production
□ Stripe credentials encrypted at rest (cast encrypted verifie)
□ Rate limit 60/min sur /api/company/billing/* (test 429)
□ v-html sanitise avec DOMPurify (test XSS)
□ Webhook secret throw si absent (test exception)
□ FK sessions.user_id contrainte (migration appliquee)
□ FK security_alerts corrigees (migration appliquee)
□ SecurityHeadersMiddleware deploye (8 headers verifies par test)
□ AuditLogger sur void/markPaid/refund (test audit log cree)
□ php artisan test : VERT
□ pnpm build : CLEAN
□ ADR mises a jour si necessaire
```

#### Sprint 1 — Session governance + tests securite
```
□ Session keepalive fonctionnel (heartbeat toutes les 5min)
□ Warning dialog affiche a TTL < 5min
□ Session expired dialog au lieu de redirect brutal
□ 25 tests 2FA VERTS
□ 12 tests Session Governance VERTS
□ php artisan test : VERT
□ pnpm build : CLEAN
□ ADR-070 finalise
```

#### Sprint 2 — Billing safety + trial fix
```
□ billing:expire-trials command deploye et schedule
□ Trials expirent automatiquement apres trial_ends_at
□ Test trial → paid E2E VERT
□ Test concurrent payments VERT
□ SEPA protocol conforme ADR-328 (8 tests supplementaires)
□ FK field_definitions + wallet_transactions corrigees
□ ADR colonnes polymorphes documentee
□ php artisan test : VERT
□ pnpm build : CLEAN
```

#### Sprint 3 — Logic extraction
```
□ BillingMetricsCalculationService cree et teste (unit tests)
□ PlatformBillingMetricsController ne contient plus de calculs
□ PlanChangeTimingResolver cree et teste
□ DeletePaymentMethodUseCase cree et teste
□ StripePaymentMethodDataExtractor cree et teste
□ Credit note auto sur downgrade (test asserter credit note)
□ Webhook recovery poller deploye (test replay)
□ 0 controller billing avec logic leakage
□ php artisan test : VERT
□ pnpm build : CLEAN
□ ADR par extraction cree
```

#### Sprint 4 — Tests coverage blitz
```
□ PlatformRolesCrudTest : 15 tests VERTS
□ NotificationSystemTest : 20 tests VERTS
□ AudienceModuleTest : 25 tests VERTS
□ ShipmentWorkflowTest : 15 tests VERTS
□ PlatformMarketsCrudTest : 12 tests VERTS
□ PlatformTranslationsTest : 15 tests VERTS
□ CompanySuspensionTest : 15 tests VERTS
□ SupportTicketTest enrichi : +10 tests VERTS
□ Total tests > 2100
□ Zero module a ZERO tests
□ php artisan test : VERT
```

#### Sprint 5 — Fondation UX
```
□ useAsyncState() composable cree et documente
□ EmptyState component cree (icon + message + CTA)
□ ErrorState component cree (message + retry)
□ VProgressLinear dans AppBar (chargement visible)
□ 0 usage de window.confirm() (toutes les pages migrees)
□ ConfirmDialog i18n (boutons traduits)
□ Bundle CSS < 1 MB (LOT-01 icons purge)
□ Pages mortes supprimees (settings stub, account-settings)
□ definePage() sur les 4 pages manquantes
□ pnpm build : CLEAN
```

#### Sprint 6 — UX systematique
```
□ useHead() sur 40 pages (titre dynamique dans l'onglet)
□ Breadcrumbs sur ~25 pages depth > 1
□ Validation client sur register, billing, member forms
□ FormRequest batch 1 : 9 controllers billing couverts
□ pnpm build : CLEAN
□ php artisan test : VERT
```

#### Sprint 7 — Workflows billing + onboarding
```
□ Trial banner visible si subscription trialing (jours restants + CTA)
□ Dunning banner visible si subscription past_due (rouge + lien regulariser)
□ Onboarding widget dashboard avec 4 steps (profil, plan, equipe, module)
□ Billing timeline deploye sur billing overview
□ Next invoice preview widget deploye
□ Proration detail visible dans confirmation plan change
□ Wallet section expliquee (origine credits)
□ 3/5 workflows complets (trial, plan change, dunning)
□ pnpm build : CLEAN
```

#### Sprint 8 — Navigation + coherence
```
□ Navigation company restructuree (6 sections hierarchiques)
□ Navigation platform restructuree (7 sections hierarchiques)
□ jobdomains/[id].vue < 300 lignes (4 sub-components extraits)
□ modules/index.vue < 300 lignes (3 sub-components extraits)
□ members/index.vue < 300 lignes (3 sub-components extraits)
□ Plan comparison page deployee
□ 0 page > 1000 lignes (5/5 pages splittees au total a fin Sprint 9)
□ pnpm build : CLEAN
```

#### Sprint 9 — Support + compliance + notifications
```
□ Support timeline visuelle deployee
□ Document compliance dashboard deploye
□ Notification center enrichi (groupement + batch read)
□ billing/pay.vue < 500 lignes (2 sub-components)
□ platform/modules/[key].vue < 500 lignes (3 sub-components)
□ 5/5 workflows complets
□ 0 page > 1000 lignes
□ pnpm build : CLEAN
```

#### Sprint 10 — Completion systeme
```
□ Emails transactionnels deployes (trial J-7/3/1, welcome, plan change)
□ Admin KPIs dashboard deploye (MRR, ARR, churn, trial conversion)
□ Billing store split (max 200 lignes par store)
□ Data retention policy documentee
□ pnpm build : CLEAN
□ php artisan test : VERT
```

#### Sprint 11 — Observabilite
```
□ Export CSV depuis chaque liste admin (companies, users, invoices, audit)
□ Correlation IDs en production (X-Correlation-Id tracable)
□ Financial forensics dashboard deploye
□ Satisfaction survey post-resolution support deploye
□ pnpm build : CLEAN
```

#### Sprint 12 — Scale + derniers items
```
□ Queue jobs billing (invoice, auto-charge, emails) — async
□ Bulk actions admin deployees (suspend, reactivate, notify)
□ Audit company timeline visuelle
□ FormRequest batch 2 : 100% des mutations couvertes
□ UseCase extraction : 100% des mutations passent par UseCase
□ Response DTO : 100% des reponses API wrappees
□ pnpm build : CLEAN
□ php artisan test : VERT
□ *** DEFINITION OF DONE GLOBALE ATTEINTE ***
```

---

> **Ce plan est maintenant EXECUTABLE.**
> 12 sprints. 78 taches. Ordre strict. Criteres clairs.
> Chaque sprint produit un livrable visible et deployable.
> Aucune ambiguite. Aucune dette cachee.
