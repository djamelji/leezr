# Audit Produit Platform — V2

> Date : 2026-04-15
> Perspective : Admin SaaS qui pilote son business au quotidien
> Objectif : Simplifier, clarifier, rendre pilotable

---

## PARTIE 1 — Verdict par page (34 pages actuelles)

### Pages à SUPPRIMER (4 pages)

| Page | Raison |
|------|--------|
| `supervision/[tab].vue` | **Redondante.** L'onglet Companies fait doublon avec la page Companies enrichie (Lot 1). L'onglet Members (tous les membres de toutes les companies) n'a aucun cas d'usage admin réel. L'onglet Company Logs fait doublon avec Activity Feed. |
| `security/index.vue` | **Redondante.** Les alertes sécurité sont des alertes comme les autres. Fusionner dans la page Alertes (qui gère déjà les sources billing/support/ai — ajouter "security"). |
| `notifications/index.vue` | **Mal placée.** C'est l'inbox perso de l'admin, pas un outil de pilotage. Accessible via l'icône cloche du header (existe déjà). Retirer du sidebar. |
| `fields.vue` (racine) | **Orpheline.** Page produit placée à la racine au lieu d'être dans le groupe Produit. Ne pas supprimer la fonctionnalité, mais la déplacer (voir ci-dessous). |

### Pages à FUSIONNER (4 pages → 2)

| Pages | Fusion | Raison |
|-------|--------|--------|
| `realtime/index.vue` + `automations/index.vue` | → `system/[tab].vue` (2 onglets : Automations, Temps réel) | Deux pages techniques que l'admin consulte rarement. Un seul point d'entrée "Système" suffit. |
| `plans/index.vue` + `plans/[key].vue` | → onglet dans Billing | Les plans sont du paramétrage billing. Pas besoin d'un item de nav dédié. Ajouter un onglet "Plans" dans la page Billing. |

### Pages à CONVERTIR en onglet (2 pages)

| Page | Devient | Raison |
|------|---------|--------|
| `fields.vue` | Onglet "Champs" dans `documents/index.vue` rebaptisé "Catalogue" | Les champs dynamiques sont du même domaine que les types de documents — c'est du paramétrage de la donnée entreprise. |
| `security/index.vue` | Source "security" dans `alerts/index.vue` | Les alertes sécurité ne méritent pas leur propre page — c'est un filtre dans le centre d'alertes unifié. |

### Pages à GARDER telles quelles (24 pages)

| Page | Verdict | Note |
|------|---------|------|
| `index.vue` (dashboard) | **KEEP** | Cockpit décisionnel (refait Lot 1). |
| `login.vue` | **KEEP** | Auth. |
| `forgot-password.vue` | **KEEP** | Auth. |
| `reset-password.vue` | **KEEP** | Auth. |
| `activity/index.vue` | **KEEP** | Activity feed (créé Lot 1). Bon produit. |
| `alerts/index.vue` | **KEEP + enrichir** | Absorbe security. Ajouter source "security". |
| `billing/[tab].vue` | **KEEP + simplifier** | 13 onglets → 10 (voir ci-dessous). |
| `billing/invoices/[id].vue` | **KEEP** | Détail facture, essentiel. |
| `companies/[id].vue` | **KEEP** | Fiche 360° entreprise, bien faite. |
| `ai/[tab].vue` | **KEEP** | 4 onglets cohérents. |
| `documentation/index.vue` | **KEEP** | Gestion articles help center. |
| `documentation/[slug].vue` | **KEEP** | Éditeur d'article. |
| `documents/index.vue` | **KEEP + absorber fields** | Renommer "Catalogue données" avec onglets : Types documents, Champs. |
| `documents/[id].vue` | **KEEP** | Détail type document. |
| `international/[tab].vue` | **KEEP** | 4 onglets cohérents (Marchés, Langues, Traductions, Taux). |
| `markets/[key].vue` | **KEEP** | Détail marché, accès depuis International. |
| `jobdomains/index.vue` | **KEEP** | Liste des métiers. |
| `jobdomains/[id].vue` | **KEEP** | Détail métier. |
| `modules/index.vue` | **KEEP** | Catalogue modules. |
| `modules/[key].vue` | **KEEP** | Détail module. |
| `settings/[tab].vue` | **KEEP + nettoyer** | Retirer onglet "Billing" (doublon) et "Notifications" (doublon). 7 → 5 onglets. |
| `access/[tab].vue` | **KEEP** | Users, Roles, Logs platform. |
| `account/[tab].vue` | **KEEP** | Mon compte (3 onglets). |
| `support/index.vue` | **KEEP** | Liste tickets. |
| `support/[id].vue` | **KEEP** | Détail ticket. |
| `users/[id].vue` | **KEEP** | Profil utilisateur platform (accès depuis access). |

### Bilan

| Métrique | Avant | Après |
|----------|-------|-------|
| Pages routable | 34 | 28 |
| Items sidebar | 24 | 17 |
| Groupes nav | 8 | 7 |
| Pages techniques exposées | 3 (realtime, automations, security) | 1 (système) |

---

## PARTIE 2 — Navigation cible

### Structure proposée (7 groupes, 17 items)

```
COCKPIT
  ├── Dashboard                → /platform
  ├── Alertes                  → /platform/alerts          (absorbe security)
  └── Activité                 → /platform/activity

CLIENTS
  ├── Entreprises              → /platform/companies       (ex-supervision, enrichie)
  └── Support                  → /platform/support

FINANCE
  ├── Facturation              → /platform/billing/[tab]   (10 onglets, incl. Plans)
  └── Analytics                → /platform/billing/[tab=analytics]  (NOUVEAU)

PRODUIT
  ├── Modules                  → /platform/modules
  ├── Métiers                  → /platform/jobdomains
  ├── Catalogue                → /platform/documents       (types docs + champs, 2 onglets)
  └── Documentation            → /platform/documentation

INTERNATIONAL
  └── International            → /platform/international/[tab]  (4 onglets internes)

SYSTÈME
  ├── Opérations               → /platform/system/[tab]    (automations + realtime)
  └── Intelligence IA          → /platform/ai/[tab]

ADMINISTRATION
  ├── Accès                    → /platform/access/[tab]    (users + roles + logs)
  └── Paramètres               → /platform/settings/[tab]  (5 onglets)
```

**Mon Compte** : accessible via icône user dans le header (pas dans la sidebar).
**Notifications** : accessible via icône cloche dans le header (pas dans la sidebar).

### Changements clés vs actuel

| Changement | Pourquoi |
|------------|----------|
| Supervision **supprimée** | Remplacée par "Entreprises" (même page, même data, nom plus clair) |
| Security **absorbée** par Alertes | Une alerte est une alerte, quel que soit le domaine |
| Plans **absorbé** par Facturation | C'est du paramétrage billing |
| Fields **absorbé** par Catalogue | Même domaine (config données entreprise) |
| Realtime + Automations **fusionnés** | "Système" = un endroit pour les ops techniques |
| Notifications **hors sidebar** | C'est perso, pas du pilotage |
| Mon Compte **hors sidebar** | Idem |
| Analytics **ajouté** | Le seul vrai manque produit côté finance |
| Coupons **reste un onglet** billing | Pas assez important pour un item nav dédié |

### Onglets Facturation (10 au lieu de 13)

```
Facturation
  ├── Vue d'ensemble          (dashboard widgets — existant)
  ├── Abonnements             (liste — existant)
  ├── Plans                   (CRUD plans — ex-page dédiée)
  ├── Factures                (liste + mutations — existant)
  ├── Paiements               (liste — existant)
  ├── Relance                 (dunning — existant)
  ├── Avoirs                  (credit notes — existant)
  ├── Coupons                 (CRUD — existant)
  ├── Portefeuilles           (wallets — existant)
  └── Audit                   (forensics + governance + ledger fusionnés)
```

**Supprimés** :
- "Scheduled Debits" → déplacé en sous-section de Paiements (SEPA est un détail de paiement)
- "Recovery" → déplacé dans Audit (c'est de la maintenance technique)
- "Forensics" + "Governance" + "Ledger" → fusionnés en "Audit" (même public : investigation)

### Onglets Paramètres (5 au lieu de 7)

```
Paramètres
  ├── Général
  ├── Apparence               (thème + typographie fusionnés)
  ├── Sessions
  ├── Maintenance
  └── Notifications           (config globale notifications, pas l'inbox)
```

**Supprimés** :
- "Billing" (doublon — la config billing est dans l'onglet Governance de Facturation)
- "Typography" (fusionné avec Theme → "Apparence")

---

## PARTIE 3 — Audit produit global

### 3.1 Ce qui marche bien (acquis Lot 1)

| Acquis | Qualité |
|--------|---------|
| Dashboard cockpit "Attention Required" | Bon — l'admin voit les urgences |
| Health score entreprises | Bon — 3 niveaux (healthy/attention/at-risk) |
| Activity feed | Bon — timeline lisible, filtrable |
| Centre d'alertes | Bon — mais doit absorber security |
| Billing 13 onglets (contenu) | Excellent — très complet |
| Fiche 360° entreprise | Bon — bio + billing + members + activity |
| Navigation groupée (8 groupes) | Correct — mais encore trop d'items |

### 3.2 Gros manques produit

#### MANQUE 1 — Revenue Analytics (CRITIQUE)

**Constat** : La facturation affiche des listes et des widgets instantanés (MRR now, AR now). Aucune tendance, aucun graphique d'évolution, aucune analyse de churn.

**Ce qu'un admin SaaS a besoin de voir** :
- MRR évolution sur 12 mois (courbe)
- Churn rate mensuel (courbe)
- Revenue par plan (camembert)
- Revenue par marché (barres)
- ARPU (Average Revenue Per User)
- Conversion trial → payant (funnel)
- Net Revenue Retention (>100% = croissance organique)

**Impact** : Sans analytics revenue, l'admin gère sa facturation comme un comptable, pas comme un dirigeant. Il ne peut pas répondre à "est-ce que le business croît ?" sans exporter dans Excel.

**Solution** : Ajouter un onglet "Analytics" dans Facturation. Les données existent (subscriptions, invoices, payments, financial_snapshots) — il faut les projeter en graphiques.

---

#### MANQUE 2 — Lifecycle abonnement incomplet (HAUT)

**Constat** : Le plan change et l'annulation côté entreprise sont excellents (preview, confirmation, proration). Mais 5 trous dans le lifecycle :

| Trou | Impact |
|------|--------|
| **Trial zombie** : pas d'automation d'expiration | Subscriptions trial gratuites indéfiniment |
| **Annulation sans info données** : le preview ne dit pas ce que l'entreprise perd | Annulation accidentelle sans comprendre les conséquences |
| **Pas de "undo cancel"** : une fois annulé, aucun moyen de revenir en arrière pendant la période de grâce | Churn accidentel irréversible |
| **Pay-now aveugle** : le bouton "Tout payer" ne montre pas le montant total | L'entreprise paie sans savoir combien |
| **Dunning → suspension sans preview** : l'admin platform peut forcer une transition qui suspend l'entreprise sans voir les conséquences | Suspension accidentelle |

---

#### MANQUE 3 — Système email inexistant (CRITIQUE)

**Constat** : Les emails transactionnels existent (11 pour le billing) mais sont inutilisables en production :

| Problème | Conséquence |
|----------|-------------|
| **Hardcodés en anglais** | Les utilisateurs français reçoivent des emails en anglais |
| **Pas de branding** | Emails texte brut sans logo, sans identité visuelle |
| **Pas d'email d'invitation** | Un nouveau membre est ajouté et... rien. Pas d'email. |
| **Documents : 0 email** | Un document expire, une demande arrive — notification in-app uniquement. Si l'utilisateur n'est pas connecté, il ne sait pas. |
| **Pas de log** | Impossible de prouver qu'un email a été envoyé. Le support ne peut pas dire "on vous a envoyé un email le..." |
| **Envoi synchrone** | Chaque email bloque le serveur PHP pendant l'envoi |

**Ce qui devrait exister** :
1. Template email avec branding (logo, couleurs, footer legal)
2. Emails en français ET anglais (selon la langue de l'utilisateur)
3. Email d'invitation membre (avec lien d'activation)
4. Emails documents (expiration, demande, validation)
5. Log transactionnel (qui a reçu quoi, quand, status)
6. Envoi asynchrone (file d'attente)

---

#### MANQUE 4 — Recherche globale (MOYEN)

**Constat** : Pour trouver une entreprise, l'admin va dans Entreprises. Pour trouver une facture, dans Facturation. Pour un ticket, dans Support. Pas de recherche transversale.

**Ce qui devrait exister** : Un champ de recherche dans le header qui cherche dans entreprises (nom, slug), factures (numéro), tickets (sujet), utilisateurs (email). Résultats groupés par type.

---

#### MANQUE 5 — Entreprises : pas de page dédiée (UX)

**Constat** : La liste des entreprises est un onglet dans "Supervision". Ce n'est pas à la hauteur — c'est LA page la plus importante pour un admin SaaS. Elle mérite sa propre route `/platform/companies` avec :
- Statistiques en tête (total, actives, à risque, MRR)
- Segments rapides (à risque, haute valeur, trial qui expire)
- Filtres avancés (plan, marché, santé, statut)
- Export CSV
- Actions bulk (suspendre, changer de plan)

La data existe déjà (enrichie Lot 1). C'est juste un problème de navigation — elle est enterrée dans un onglet.

---

### 3.3 Problèmes UX structurels

| Problème | Détail |
|----------|--------|
| **Billing 13 onglets** | Trop. Un admin ne sait pas où chercher. 3 onglets (Forensics, Governance, Ledger) font la même chose (investigation) → fusionner en "Audit". |
| **Settings 7 onglets dont 2 doublons** | "Billing" dans Settings = doublon de la config dans Facturation. "Notifications" dans Settings ≠ la page Notifications (inbox). Confusion garantie. |
| **Supervision / Entreprises : dénomination** | "Supervision" ne veut rien dire pour un admin. "Entreprises" est clair. |
| **Documents / Documentation : confusion** | Deux mots quasi-identiques pour deux choses différentes. "Documents" = types de pièces justificatives. "Documentation" = articles help center. Renommer Documents → "Catalogue" ou "Types de pièces". |
| **Fields orphelin** | Page à la racine, sans groupe, perdue dans le sidebar. |
| **3 pages pour les logs** | Activity feed, Platform Logs (dans Access), Company Logs (dans Supervision). 3 endroits pour voir "ce qui s'est passé". Activity Feed devrait être le seul point d'entrée lisible, les logs restent pour l'investigation technique. |
| **Notifications dans sidebar** | L'inbox de notifications est un outil perso, pas de pilotage. Ça appartient au header (comme Gmail, Slack, etc.) |
| **AI : 2 items pour 1 page** | "AI Providers" et "AI Usage" sont des onglets de la MÊME page. Pas besoin de 2 items nav. |

---

## PARTIE 4 — Améliorations produit

### 4.1 Fusions de pages (simplification immédiate)

| Fusion | Détail | Effort |
|--------|--------|--------|
| **Supervision → Entreprises** | Extraire _CompaniesTab comme page standalone `/platform/companies/index.vue`. Supprimer supervision. | Faible |
| **Security → Alertes** | Ajouter source "security" dans le filtre Alertes. Supprimer la page security. | Faible |
| **Realtime + Automations → Système** | Créer `system/[tab].vue` avec 2 onglets. | Faible |
| **Plans → onglet Billing** | Déplacer Plans dans billing/[tab].vue comme onglet "Plans". | Faible |
| **Fields → onglet Catalogue** | Ajouter onglet "Champs" dans documents/index.vue (renommé "Catalogue"). | Faible |
| **Forensics + Governance + Ledger → Audit** | Fusionner 3 onglets billing en 1 seul "Audit". | Faible |
| **Theme + Typography → Apparence** | Fusionner 2 onglets settings en 1. | Faible |

### 4.2 Nouvelles pages qui manquent vraiment

| Page | Quoi | Pourquoi | Priorité |
|------|------|----------|----------|
| **Analytics** (onglet Billing) | MRR trend, churn, ARPU, revenue by plan, trial conversion | Un SaaS sans analytics revenue est piloté à l'aveugle | P0 |
| **Entreprises** (page dédiée) | Liste enrichie avec stats, segments, export, bulk actions | C'est LE cockpit client — pas un onglet dans "Supervision" | P0 |
| **Recherche globale** | Champ header → résultats groupés (companies, factures, tickets, users) | Productivité admin : trouver en 2 secondes | P1 |

### 4.3 Améliorations UX (sans nouvelles pages)

| Amélioration | Détail | Priorité |
|--------------|--------|----------|
| **Emails pro** | Template Blade avec branding + i18n FR + queue async | P0 |
| **Email invitation membre** | Quand un admin ajoute un membre → email avec lien | P0 |
| **Emails documents** | Document expiré / demandé → email (pas juste in-app) | P1 |
| **Cancel preview enrichi** | Montrer ce que l'entreprise perd (données, modules, automations) | P1 |
| **Pay-now preview** | Afficher le total avant de payer | P1 |
| **Undo cancel** | Bouton "Réactiver" pendant la période de grâce | P2 |
| **Churn reason** | Capturer la raison d'annulation (pricing, features, competitor...) | P2 |

### 4.4 Ce qui n'est PAS prioritaire (P3+)

| Item | Pourquoi pas maintenant |
|------|------------------------|
| Onboarding funnel page | Utile mais pas critique tant qu'il n'y a pas de volume |
| Incident center | Les alertes suffisent pour l'instant |
| Feature flags | Le système de modules est déjà un feature flag de facto |
| Usage monitoring détaillé | Les health scores suffisent pour détecter l'inactivité |
| AI Operations enrichi | L'IA est secondaire vs le billing et les emails |
| Email campaigns/marketing | Le transactionnel d'abord, le marketing ensuite |
| Email preview panel platform | Les templates Blade se testent avec Mailpit |

---

## PARTIE 5 — Plan d'exécution

### Sprint 1 : "La platform qui simplifie" (fusions + nav)

| # | Item | Type | Effort |
|---|------|------|--------|
| 1 | Supervision → Entreprises (page dédiée) | Restructure | 2h |
| 2 | Security → absorbé par Alertes | Fusion | 1h |
| 3 | Realtime + Automations → Système | Fusion | 1h |
| 4 | Plans → onglet Billing | Fusion | 1h |
| 5 | Fields → onglet dans Catalogue | Fusion | 1h |
| 6 | 3 onglets billing → "Audit" | Fusion | 1h |
| 7 | Settings nettoyage (7→5 onglets) | Simplification | 1h |
| 8 | Navigation mise à jour (7 groupes) | Nav | 1h |
| 9 | Notifications hors sidebar (header only) | Nav | 30min |

**Total** : ~10h
**Résultat** : 34 pages → 28, 24 items nav → 17, navigation lisible

### Sprint 2 : "La platform qui pilote" (analytics + emails)

| # | Item | Type | Effort |
|---|------|------|--------|
| 10 | Revenue Analytics (onglet billing) | Nouveau | 3-4j |
| 11 | Emails : template Blade + branding + i18n FR | Refonte | 2-3j |
| 12 | Email invitation membre | Nouveau | 1j |
| 13 | Emails documents (expiré, demandé) | Nouveau | 1j |
| 14 | Cancel preview enrichi | Amélioration | 2h |
| 15 | Pay-now preview | Amélioration | 2h |

**Total** : ~8-10j
**Résultat** : Pilotage financier réel, emails professionnels FR, UX billing complète

### Sprint 3 : "La platform qui excelle" (polish)

| # | Item | Type | Effort |
|---|------|------|--------|
| 16 | Recherche globale | Nouveau | 2-3j |
| 17 | Undo cancel (grace period) | Amélioration | 1j |
| 18 | Churn reason capture | Amélioration | 2h |
| 19 | Dunning preview conséquences | Amélioration | 2h |

---

## Score cible

| Phase | Score | Gains |
|-------|-------|-------|
| Actuel (post Lot 1) | 78/100 | Cockpit, alertes, activity, health |
| Après Sprint 1 | 83/100 | Nav simple, pages fusionnées, 0 redondance |
| Après Sprint 2 | 92/100 | Analytics, emails pro, lifecycle complet |
| Après Sprint 3 | 96/100 | Recherche, polish UX |
