# LEEZR V2 — GO-TO-MARKET & POSITIONNEMENT

> Mode : Fondateur SaaS + Product Marketer + Growth
> Correction critique : wedge ≠ produit ≠ positionnement

---

# 0. LA CONFUSION QUI TUE LES SAAS

```
ERREUR FATALE :
  "Notre produit c'est la compliance documentaire"
  → attire les mauvais clients
  → bloque le pricing au module
  → enferme dans un use case
  → rend l'expansion incohérente

RÉALITÉ :
  La compliance est la PORTE D'ENTRÉE.
  Le produit est la PLATEFORME.
  Le positionnement est la PROMESSE LARGE.
```

Ce document sépare rigoureusement 3 couches :

| Couche | Ce que c'est | Ce que ça dit |
|--------|-------------|---------------|
| **Wedge** | Tactique d'acquisition | "Vos équipes sont-elles en règle ?" |
| **Produit** | La réalité de ce qu'on vend | "L'espace de travail qui s'adapte à votre métier" |
| **Positionnement** | La promesse marché | "La plateforme qui fait tourner vos opérations" |

---

# 1. WEDGE — LA PORTE D'ENTRÉE

## Rôle du wedge

Le wedge sert à **une seule chose** : convertir un prospect en utilisateur actif.

Il ne définit PAS le produit. Il ne contraint PAS la roadmap. Il ne limite PAS le pricing.

C'est un **hameçon** — pas la mer.

## Le wedge choisi : Compliance Équipe

> **"Vos équipes sont-elles en règle ? Leezr vous le dit en 30 secondes."**

### Pourquoi ce wedge fonctionne

| Critère | Score |
|---------|-------|
| **Douleur universelle** | Toute entreprise avec des employés terrain a des documents obligatoires |
| **Urgence réglementaire** | Contrôle = amendes, suspensions. La peur motive l'achat. |
| **Process actuel cassé** | Excel + email + Drive. 100% des prospects font ça mal. |
| **Valeur immédiate** | Upload → AI analyse → score compliance. Pas dans 3 mois. Maintenant. |
| **AI = wow moment** | L'AI lit le document, extrait, vérifie. L'utilisateur n'a rien à saisir. |
| **Cross-industrie** | Logistique (permis, FIMO), BTP (CACES), santé (diplômes), intérim (titres de séjour) |
| **Concurrent = Excel** | Aucun leader SaaS. Excel est le vrai concurrent. Et Excel perd. |

### Ce que le wedge N'EST PAS

- ❌ Le wedge n'est PAS le produit
- ❌ Le wedge n'est PAS la seule valeur de Leezr
- ❌ Le wedge ne doit PAS apparaître dans le positionnement produit
- ❌ Le wedge ne doit PAS verrouiller la perception ("ah c'est un outil de docs")

Le wedge est un **premier contact**. Ensuite, le produit prend le relais.

---

# 2. PRODUIT RÉEL — LA PLATEFORME

## Ce qu'est Leezr (source : BMAD 00-context)

> **Leezr est une plateforme SaaS modulaire multi-tenant multi-vertical.**
> Chaque entreprise dispose d'un espace de gestion adapté à son activité,
> assemblé automatiquement via un système de jobdomain (variante produit)
> et de modules (logique métier autonome).

## Le problème que Leezr résout (source : BMAD 01-business)

> Les solutions existantes sont soit **trop généralistes** (ERP configurables mais lourds),
> soit **trop verticales** (SaaS niche impossibles à étendre).
> Leezr propose un **socle flexible** dont l'expérience s'adapte au métier.

## L'architecture produit

```
┌──────────────────────────────────────────────────────────────┐
│                       LEEZR                                   │
│                                                               │
│  ┌──────────────────────────────────────────────────────┐    │
│  │  EXPÉRIENCE MÉTIER (Bulle UX)                         │    │
│  │  Navigation, dashboard, vocabulaire, workflows         │    │
│  │  → Assemblée automatiquement par le jobdomain          │    │
│  └──────────────────────────────────────────────────────┘    │
│                                                               │
│  ┌────────┐ ┌────────┐ ┌────────┐ ┌────────┐ ┌────────┐    │
│  │ Docs   │ │Billing │ │Support │ │Logist. │ │ ...    │    │
│  │        │ │        │ │        │ │        │ │        │    │
│  │ Module │ │ Module │ │ Module │ │ Module │ │ Module │    │
│  └────────┘ └────────┘ └────────┘ └────────┘ └────────┘    │
│  ↕ Activables par company, connectables entre eux            │
│                                                               │
│  ┌──────────────────────────────────────────────────────┐    │
│  │  CORE PLATFORM                                        │    │
│  │  Auth • Tenancy • Membres • AI Engine • Realtime      │    │
│  │  Automations • Notifications • Audit                   │    │
│  │  → Invariant, ne connaît aucun métier                  │    │
│  └──────────────────────────────────────────────────────┘    │
└──────────────────────────────────────────────────────────────┘
```

## Ce qui rend Leezr unique (vs ERP vs SaaS niche)

| Dimension | ERP classique | SaaS niche | **Leezr** |
|-----------|--------------|------------|-----------|
| Adaptation métier | Configuration lourde | Figé sur un vertical | **Automatique via jobdomain** |
| Modules | Monolithiques, tout inclus | 1 fonction, point final | **Activables, connectables** |
| Expérience utilisateur | Générique, complexe | Bonne mais limitée | **Adaptée au métier, extensible** |
| Intelligence | Manuelle | Parfois intégrée | **AI transverse à tous les modules** |
| Prix | 10K-100K€/an | 20-200€/mois | **Starter gratuit → Scale 349€** |
| Time-to-value | 3-12 mois | 1-7 jours | **5 minutes** |
| Extensibilité | Possible mais coûteux | Impossible | **Naturelle (ajouter un module)** |

## Les 3 piliers produit

### Pilier 1 : Adaptation automatique

L'utilisateur choisit son secteur → Leezr assemble son espace.

Pas de configuration. Pas de consultant. Pas de "paramétrage initial". Le jobdomain sélectionne : modules, navigation, dashboard, vocabulaire, types de documents, rôles types.

**C'est la proposition de valeur n°1 :** un outil pro adapté à ton métier, prêt en 2 minutes.

### Pilier 2 : Modules connectés

Chaque module fonctionne seul. Mais quand on les connecte, la valeur explose :

```
Document expiré → Suspendre la facturation (Docs × Billing)
Nouveau membre  → Demander ses documents + l'assigner (Members × Docs)
Ticket critique → Notifier le manager + créer une tâche (Support × Automation)
Livraison KO   → Ouvrir un ticket + alerter le client (Logistics × Support)
```

**C'est la proposition de valeur n°2 :** tes outils parlent entre eux. Impossible avec N outils séparés.

### Pilier 3 : AI transverse

L'AI n'est pas un feature d'un module. C'est une **capacité core** que chaque module consomme :

- Documents : analyse, extraction, vérification automatique
- Billing : détection d'anomalies, prédiction de churn, suggestions de relance
- Support : classification automatique, suggestions de réponse
- Logistics : optimisation de tournées, prédiction de retards
- Cross-module : insights, corrélations, alertes proactives

**C'est la proposition de valeur n°3 :** l'AI travaille partout, pas dans un silo.

---

# 3. POSITIONNEMENT MARCHÉ

## La promesse (pas le wedge, pas une feature — LA PROMESSE)

> **Leezr est la plateforme qui fait tourner vos opérations.**
> **Un seul espace. Vos modules. Votre métier. L'AI dedans.**

## Déclinaisons par audience

### Pour un dirigeant PME

> Leezr adapte votre espace de travail à votre métier et connecte tous vos outils opérationnels — documents, facturation, support, logistique — dans une seule plateforme. L'AI automatise ce qui peut l'être. Vous supervisez.

### Pour un DSI / responsable outils

> Une plateforme modulaire multi-tenant, où chaque module est activable par entreprise. Architecture découplée, AI intégrée, automations cross-modules. Pas un ERP monolithique, pas un SaaS jetable.

### Pour un investisseur

> Leezr est une plateforme d'opérations B2B modulaire. Land & expand model : entrée par un module à forte rétention (compliance documentaire), expansion naturelle vers billing, support, logistique. AI-native, cross-module automations, multi-vertical par design. TAM : toute PME avec des opérations terrain (2M+ en France).

### Pour un prospect en démo

> Dites-nous votre métier. Leezr vous prépare un espace adapté avec les bons outils, la bonne navigation, les bons documents. Commencez par un module, ajoutez les autres quand vous en avez besoin. Tout est connecté. L'AI gère le répétitif.

## Ce que Leezr dit TOUJOURS

- "Adapté à votre métier" — pas générique, pas custom
- "Vos opérations, connectées" — modules qui parlent entre eux
- "L'AI fait le travail répétitif" — pas "powered by AI" vide
- "Commencez gratuitement, grandissez quand vous voulez" — pas de lock-in
- "Prêt en 5 minutes" — pas 3 mois de déploiement

## Ce que Leezr ne dit JAMAIS

- ❌ "Outil de gestion documentaire" → trop réducteur
- ❌ "OS business" → trop abstrait
- ❌ "ERP nouvelle génération" → effrayant
- ❌ "Plateforme modulaire" → jargon technique
- ❌ "Compliance automatisée" → c'est le wedge, pas le produit
- ❌ "Solution tout-en-un" → promesse vide

---

# 4. MESSAGING STRUCTURÉ — 3 COUCHES

## Couche 1 : Message d'acquisition (wedge)

> **"Vos équipes sont-elles en règle ?"**
> Leezr vérifie les documents de vos collaborateurs, vous alerte avant expiration, et automatise les relances. L'AI fait le reste.

**Cible** : Admin, RH, dirigeant PME avec des équipes terrain
**Canal** : Landing page, Google Ads, LinkedIn
**CTA** : "Essayer gratuitement — 2 minutes de setup"
**Promesse** : visibilité immédiate sur la compliance

Ce message **convertit**. Il parle d'un problème concret. C'est le hameçon.

## Couche 2 : Message produit (plateforme)

> **"Tous vos outils opérationnels. Un seul espace. Adapté à votre métier."**
> Leezr assemble automatiquement l'espace de travail de votre entreprise : documents, facturation, support, logistique — les modules dont vous avez besoin, connectés entre eux. L'AI automatise le répétitif.

**Cible** : Utilisateur actif qui découvre le reste de la plateforme
**Canal** : In-app, page "Explorer les modules", email Semaine 2
**CTA** : "Découvrez ce que Leezr peut faire pour votre métier"
**Promesse** : un espace adapté, extensible, intelligent

Ce message **éduque**. Il ouvre la perception au-delà du wedge.

## Couche 3 : Message d'expansion (modules connectés)

> **"Vos modules parlent entre eux. C'est impossible ailleurs."**
> Quand un document expire, la facturation se suspend. Quand un nouveau membre arrive, ses documents sont demandés automatiquement. Quand un ticket critique est ouvert, le manager est alerté. C'est ça, des opérations connectées.

**Cible** : Utilisateur Pro qui utilise 1-2 modules
**Canal** : In-app nudges, email Mois 2-3, page Automations
**CTA** : "Connectez vos modules — essayez Scale 14 jours"
**Promesse** : des opérations qui s'exécutent seules

Ce message **expand**. Il montre la valeur plateforme.

## Résumé du messaging funnel

```
ACQUISITION (Wedge)      →  "Êtes-vous en règle ?"
  ↓ signup + activation
PRODUIT (Plateforme)     →  "Votre espace, adapté à votre métier"
  ↓ adoption + 2ème module
EXPANSION (Connexion)    →  "Vos opérations, connectées et autonomes"
  ↓ Scale + automations
RÉTENTION (Dépendance)   →  "Comment faisiez-vous avant ?"
```

---

# 5. NARRATIF LANDING PAGE

## Ce que voit un prospect — la descente en entonnoir

### Bloc 1 : HERO — Le problème concret (wedge)

```
┌──────────────────────────────────────────────────────────────┐
│                                                               │
│   Vos équipes sont-elles en règle ?                          │
│                                                               │
│   Leezr vérifie les documents de vos collaborateurs,         │
│   vous alerte avant expiration, et automatise les relances.  │
│   L'AI fait le reste.                                        │
│                                                               │
│   [Essayer gratuitement]     [Voir une démo]                 │
│                                                               │
│   ✓ Gratuit    ✓ 2 min de setup    ✓ Pas de carte bancaire  │
│                                                               │
└──────────────────────────────────────────────────────────────┘
```

**Objectif** : le prospect comprend en 5 secondes. Un problème qu'il a. Une solution claire.

### Bloc 2 : PREUVE — Comment ça marche (3 étapes)

```
┌──────────────────────────────────────────────────────────────┐
│                                                               │
│   Comment ça marche                                          │
│                                                               │
│   1. Ajoutez vos collaborateurs                              │
│      Leezr leur demande automatiquement les documents        │
│      requis pour leur poste.                                 │
│      [illustration : ajout collaborateur → docs auto]        │
│                                                               │
│   2. L'AI vérifie tout                                       │
│      Type reconnu, dates extraites, validité vérifiée.       │
│      En 10 secondes, pas 10 minutes.                         │
│      [illustration : upload → analyse AI → ✓ validé]        │
│                                                               │
│   3. Zéro relance manuelle                                   │
│      Les documents expirent ? Leezr relance pour vous.       │
│      Vous supervisez. Le système exécute.                    │
│      [illustration : notification → relance auto → reçu]    │
│                                                               │
└──────────────────────────────────────────────────────────────┘
```

**Objectif** : montrer la simplicité. 3 étapes. Pas 30.

### Bloc 3 : OUVERTURE — Plus qu'un outil documentaire (transition produit)

```
┌──────────────────────────────────────────────────────────────┐
│                                                               │
│   Commencez par la compliance.                               │
│   Continuez avec tout le reste.                              │
│                                                               │
│   Leezr s'adapte à votre métier et vous donne les outils    │
│   dont vous avez vraiment besoin.                            │
│                                                               │
│   ┌─────────┐  ┌─────────┐  ┌─────────┐  ┌─────────┐      │
│   │ 📄      │  │ 💰      │  │ 🎫      │  │ 🚚      │      │
│   │Documents│  │Billing  │  │Support  │  │Logist.  │      │
│   │         │  │         │  │         │  │         │      │
│   │Compliance│ │Factures │ │Tickets  │ │Expédit. │      │
│   │AI auto  │  │Relances │  │SLA      │  │Suivi    │      │
│   │Relances │  │Dunning  │  │Base FAQ │  │Tournées │      │
│   └─────────┘  └─────────┘  └─────────┘  └─────────┘      │
│                                                               │
│   Activez un module quand vous en avez besoin.               │
│   Désactivez-le quand vous n'en avez plus.                   │
│   Connectez-les pour automatiser vos opérations.             │
│                                                               │
└──────────────────────────────────────────────────────────────┘
```

**Objectif** : le prospect comprend que Leezr n'est PAS un outil de docs. C'est une plateforme. La compliance est l'entrée.

### Bloc 4 : CONNEXION — La valeur plateforme (différenciation)

```
┌──────────────────────────────────────────────────────────────┐
│                                                               │
│   Vos outils parlent entre eux.                              │
│   Impossible avec N logiciels séparés.                       │
│                                                               │
│   ┌─────────────────────────────────────────────────┐        │
│   │                                                  │        │
│   │  SI  document expiré                             │        │
│   │  →   suspendre la facturation du collaborateur   │        │
│   │  →   notifier son manager                        │        │
│   │  →   ouvrir un ticket de suivi                   │        │
│   │                                                  │        │
│   │  Docs × Billing × Support — en 1 automation     │        │
│   │                                                  │        │
│   └─────────────────────────────────────────────────┘        │
│                                                               │
│   C'est ça, des opérations connectées.                       │
│   Pas N onglets. Un seul espace. Un seul flux.               │
│                                                               │
└──────────────────────────────────────────────────────────────┘
```

**Objectif** : montrer le moat. Ce que Leezr fait que personne ne peut faire avec des outils séparés.

### Bloc 5 : SECTEURS — L'adaptation métier (preuve multi-vertical)

```
┌──────────────────────────────────────────────────────────────┐
│                                                               │
│   Adapté à votre secteur. Pas un outil générique.            │
│                                                               │
│   ┌──────────────┐ ┌──────────────┐ ┌──────────────┐        │
│   │  🚛 Transport │ │  🏗️ BTP      │ │  🏥 Santé    │        │
│   │               │ │               │ │               │        │
│   │ Permis, FIMO  │ │ CACES, Habil.│ │ Diplômes, DPC│        │
│   │ Carte conduct.│ │ Électrique   │ │ Vaccinations │        │
│   │ Expéditions   │ │ Chantiers    │ │ Plannings    │        │
│   │ Tournées      │ │ Sécurité     │ │ Patients     │        │
│   └──────────────┘ └──────────────┘ └──────────────┘        │
│                                                               │
│   ┌──────────────┐ ┌──────────────┐ ┌──────────────┐        │
│   │  👷 Intérim   │ │  🍽️ Restaur. │ │  🏢 Services │        │
│   │               │ │               │ │               │        │
│   │ Titres séjour │ │ HACCP, Hygiène│ │ Certifs, ISO│        │
│   │ Autorisations │ │ Formations   │ │ Habilitations│        │
│   │ Contrats      │ │ Licences     │ │ Audits       │        │
│   └──────────────┘ └──────────────┘ └──────────────┘        │
│                                                               │
│   Choisissez votre secteur. Leezr fait le reste.             │
│                                                               │
└──────────────────────────────────────────────────────────────┘
```

**Objectif** : prouver le multi-vertical. Le prospect voit SON métier. C'est fait pour lui.

### Bloc 6 : SOCIAL PROOF + CTA FINAL

```
┌──────────────────────────────────────────────────────────────┐
│                                                               │
│   "On a commencé par les documents.                          │
│    Aujourd'hui, toute notre gestion tourne sur Leezr."       │
│   — DirectFleet, 45 collaborateurs                           │
│                                                               │
│   "92% de compliance en 3 semaines. Avant, c'était le chaos."│
│   — BTP Azur, 120 ouvriers                                  │
│                                                               │
│   ────────────────────────────────────────────                │
│                                                               │
│   Commencez gratuitement.                                    │
│   Ajoutez des modules quand vous en avez besoin.             │
│                                                               │
│   [Créer mon espace — Gratuit]                               │
│                                                               │
│   ✓ Pas de carte bancaire                                    │
│   ✓ Prêt en 2 minutes                                       │
│   ✓ Adapté à votre secteur                                   │
│                                                               │
└──────────────────────────────────────────────────────────────┘
```

**Objectif** : preuve sociale + conversion. Le témoignage montre la progression wedge → plateforme.

## Résumé narratif landing

```
Hero      → Problème concret (compliance) — WEDGE
Proof     → Comment ça marche (3 étapes) — CRÉDIBILITÉ
Opening   → Plus qu'un outil docs (modules) — PRODUIT
Connect   → Modules connectés (automations) — DIFFÉRENCIATION
Sectors   → Adapté à votre métier — MULTI-VERTICAL
Social    → Témoignages + CTA final — CONVERSION
```

La page descend de **spécifique** (wedge) vers **large** (plateforme), sans jamais perdre le prospect. Il entre par son problème, il découvre la solution, il voit que c'est plus grand.

---

# 6. FIRST USER EXPERIENCE — Le parcours réel

## Minute 0:00 — Landing Page

Voir section 5 ci-dessus. Le prospect clique "Essayer gratuitement".

## Minute 0:30 — Signup (3 étapes)

```
Étape 1/3 : Vous
  → Email + mot de passe (ou Google SSO)

Étape 2/3 : Votre entreprise
  → Nom de l'entreprise
  → Secteur [Transport ▾] [BTP ▾] [Santé ▾] [Intérim ▾] [Autre ▾]
  → Taille équipe [1-10 ▾] [11-50 ▾] [51-200 ▾] [200+ ▾]

Étape 3/3 : C'est parti
  → "Leezr configure votre espace..."
  → (3 secondes — animation)
  → ✅ Modules adaptés à votre secteur
  → ✅ Types de documents pré-configurés
  → ✅ Dashboard prêt
```

**Le secteur (Étape 2) déclenche le jobdomain** → assemble modules par défaut, types de documents, navigation, vocabulaire. L'utilisateur n'a rien à configurer.

## Minute 1:30 — Premier écran (Home)

```
┌──────────────────────────────────────────────────────────────┐
│ Bienvenue sur Leezr                                          │
│                                                               │
│ Votre espace Transport est prêt. Voici comment démarrer :   │
│                                                               │
│ ████████░░░░░░░░░░░░░░ 25% complété                         │
│                                                               │
│ ✅ Créer votre espace                                        │
│ ➡️ Ajouter vos premiers collaborateurs                       │
│    "Ajoutez 2-3 personnes pour voir Leezr en action"         │
│    [Ajouter un collaborateur]  [Importer un CSV]             │
│                                                               │
│ ⬜ Recevoir les premiers documents                            │
│ ⬜ Voir votre score de compliance                             │
│                                                               │
│ "Les entreprises qui ajoutent leur équipe le 1er jour        │
│  atteignent 90% de compliance en 3 semaines"                 │
└──────────────────────────────────────────────────────────────┘
```

**Pas de dashboard vide.** Pas de "explorez nos fonctionnalités". Une seule action claire.

## Minute 2:00 — Ajouter un collaborateur

```
┌──────────────────────────────────────────────────────────────┐
│ Ajouter un collaborateur                                     │
│                                                               │
│ Prénom : [Mohamed          ]                                 │
│ Nom :    [Alami            ]                                 │
│ Email :  [m.alami@mail.com ]                                 │
│ Poste :  [Chauffeur PL ▾   ]                                │
│                                                               │
│ Documents requis pour ce poste :                              │
│   ✓ Permis C (automatique)                                   │
│   ✓ FIMO marchandises (automatique)                          │
│   ✓ Carte conducteur (automatique)                           │
│   ✓ Visite médicale (automatique)                            │
│                                                               │
│ "Mohamed recevra un email lui demandant ces documents"       │
│                                                               │
│ [Ajouter et inviter]                                         │
└──────────────────────────────────────────────────────────────┘
```

Le poste déclenche les documents requis (configuré par le jobdomain). Mohamed reçoit un email avec un lien de soumission (pas besoin de compte Leezr).

## Minute 3:00 — Dashboard compliance (le problème quantifié)

```
┌──────────────────────────────────────────────────────────────┐
│ Compliance — Votre équipe                                    │
│                                                               │
│ ┌──────────┐  ┌──────────┐  ┌──────────┐                    │
│ │    3     │  │    0     │  │   12     │                    │
│ │ membres  │  │ conformes│  │ docs     │                    │
│ │          │  │          │  │ manquants│                    │
│ └──────────┘  └──────────┘  └──────────┘                    │
│                                                               │
│ Compliance globale : 0%                                      │
│ ░░░░░░░░░░░░░░░░░░░░░░░░░░ 0%                               │
│                                                               │
│ En attente de documents (3 personnes invitées)               │
│                                                               │
│ ████████████░░░░░░░░░░ 50% du setup complété                │
│ Prochaine étape : Recevoir les premiers documents            │
└──────────────────────────────────────────────────────────────┘
```

12 documents manquants. 0% compliance. Le problème est **un chiffre**. Plus abstrait.

## Minute 3:30 — Le Wow Moment (AI)

L'admin upload un document test :

```
┌──────────────────────────────────────────────────────────────┐
│ Analyse AI en cours...                                       │
│                                                               │
│ ████████████████████████░░░░ 85%                             │
│                                                               │
│ ✅ Type reconnu : Permis de conduire catégorie B             │
│ ✅ Nom extrait : ALAMI Mohamed                               │
│ ✅ Date d'expiration : 22/09/2028                            │
│ ✅ Numéro : 12AB34567                                        │
│                                                               │
│ Confiance : 94% — HAUTE                                      │
│                                                               │
│ Document valide — expire dans 2 ans et 5 mois               │
│                                                               │
│ [Approuver] [Voir les détails]                               │
│                                                               │
│ "Avec le plan Pro, l'AI approuve automatiquement             │
│  les documents à haute confiance (>90%)"                     │
└──────────────────────────────────────────────────────────────┘
```

**Wow moment.** Upload photo → 10 secondes → tout extrait et vérifié. L'admin comprend : "Si ça fait ça pour 1 doc, imaginez pour 50 collab × 4 docs. Adieu Excel."

## Time-to-value

```
0:00  Landing → comprend le problème
0:30  Signup → 3 étapes, secteur auto-config
1:30  Home → une seule action : ajouter un collaborateur
2:00  Ajout → documents requis auto-détectés
3:00  Dashboard → problème quantifié (12 docs manquants)
3:30  Upload test → AI wow moment (10 secondes)
5:00  L'admin est convaincu. Il a vu la valeur.

Time-to-value : 3 minutes 30.
```

---

# 7. PACKAGING PRODUIT

## Logique de packaging

Le packaging suit les 3 couches de messaging :

```
Starter (gratuit) → Wedge : "Voir le problème"
Pro (payant)      → Produit : "Le résoudre automatiquement"
Scale (premium)   → Plateforme : "Tout connecter"
```

## Plan Starter — Gratuit

> "Voir où vous en êtes"

| Inclus | Limite |
|--------|--------|
| Dashboard compliance | 10 collaborateurs |
| Types de documents pré-configurés (par secteur) | 5 types |
| Invitations collaborateurs (email) | — |
| Upload documents | — |
| Score compliance temps réel | — |
| Relances manuelles | — |
| Export PDF compliance | 1/mois |
| AI analyse de base (type + expiration) | 20 analyses/mois |

**Ce qui manque** : auto-review AI, relances auto, modules additionnels, automations.

**Rôle** : adoption. Prouver la valeur avant de payer. Convertir Excel → Leezr.

## Plan Pro — Le plan de croissance

> "Automatiser vos opérations"

| Inclus | Limite |
|--------|--------|
| Tout Starter + | — |
| Collaborateurs illimités | — |
| Types de documents illimités | — |
| AI auto-review (seuil configurable) | 200 analyses/mois |
| AI extraction complète | — |
| Relances automatiques | — |
| Renouvellement auto des demandes | — |
| Export illimité (PDF, CSV) | — |
| Historique & audit trail | 1 an |
| +1 module additionnel au choix | — |
| Automations basiques (même module) | 5 règles |
| Support email prioritaire | — |

**Prix** : 49€/mois (≤50) — 79€/mois (≤200) — 129€/mois (illimité)

**Trigger d'upgrade** :
- ">10 membres → Passez en Pro pour l'illimité"
- "20 analyses AI épuisées → Pro = auto-review + illimité"
- "3 docs expirés sans relance → Pro = relances automatiques"

## Plan Scale — La plateforme complète

> "Tous vos outils. Connectés. Autonomes."

| Inclus | Limite |
|--------|--------|
| Tout Pro + | — |
| Tous les modules | — |
| AI illimité (toutes primitives) | Fair use |
| AI Copilote (insights proactifs) | — |
| Automations cross-modules | Illimité |
| Visual Rule Builder | — |
| Inbox intelligent | — |
| Activity Feed temps réel | — |
| Command Bar (⌘K) | — |
| API access | — |
| Rôles & permissions avancés | — |
| Historique illimité | — |
| Support dédié (chat + visio) | — |

**Prix** : 199€/mois (≤200) — 349€/mois (illimité) — Sur devis (500+)

**Trigger d'upgrade** :
- "Vous utilisez 2 modules → Scale pour les connecter"
- "Équipe qui grandit → Scale = AI Copilote + permissions avancées"

## Module add-ons (Pro uniquement)

| Module | Prix | Valeur |
|--------|------|--------|
| Billing | +29€/mois | Facturation, abonnements, dunning |
| Support | +19€/mois | Tickets, SLA, base de connaissance |
| Logistics | +29€/mois | Expéditions, suivi, tournées |
| HR (futur) | +29€/mois | Onboarding, congés, évaluations |

Scale = tout inclus, pas d'add-ons.

---

# 8. STRATÉGIE D'EXPANSION

## Land → Expand → Platform

```
LAND (Mois 1-2)
  Wedge compliance → Starter gratuit ou Pro
  1 admin + ses collaborateurs
  Valeur : visibilité + automatisation docs

EXPAND (Mois 3-6)
  +1 module (Billing, Support, ou Logistics)
  +1-2 utilisateurs admin (comptable, manager)
  Valeur : opérations connectées
  Plan : Pro + add-on

PLATFORM (Mois 6-12)
  Tous modules + automations cross-modules
  Toute l'équipe admin
  Valeur : "tout tourne dans un seul outil"
  Plan : Scale
```

## Triggers d'expansion naturels

### Documents → Billing

```
Trigger : L'admin gère ses collab dans Leezr. Il facture dans un autre outil.

Nudge : "Vous gérez 45 collaborateurs dans Leezr.
         Gérez aussi vos factures ici et connectez-les :
         document expiré → facturation suspendue."

Valeur : cross-module. Impossible avec 2 outils séparés.
```

### Documents → Support

```
Trigger : Les collab posent des questions sur les documents par email.

Nudge : "Vos collaborateurs vous contactent pour leurs documents ?
         Le module Support centralise les demandes.
         Historique docs visible dans chaque ticket."
```

### Pro → Scale

```
Trigger : L'admin utilise 2+ modules et veut les connecter.

Nudge : "Vous utilisez Documents + Billing séparément.
         En Scale : 'Si doc expiré → suspendre la facturation'
         Essayez Scale 14 jours gratuitement."

Valeur : les modules parlent entre eux.
```

## Le moment "plateforme"

L'utilisateur ne pense pas "plateforme" au jour 1. Il pense "compliance".

Au jour 90, Documents + Billing + Automations fonctionnent ensemble. Il réalise :

> "Je ne pourrais plus séparer ces outils. Tout est connecté."

C'est le moment où Leezr est devenu sa plateforme. Il ne l'a pas décidé. C'est arrivé module par module.

---

# 9. UX D'ADOPTION

## Onboarding adaptatif (pas un wizard statique)

```
Phase 1 — Setup (Jour 1)
  ████░░░░░░░░░░░░ 25%
  ✅ Créer l'espace
  ➡️ Ajouter l'équipe
  ⬜ Recevoir des documents
  ⬜ Voir la compliance

Phase 2 — Activation (Jours 2-7)
  ████████░░░░░░░░ 50%
  ✅ 5 collaborateurs ajoutés
  ✅ 8 documents reçus
  ➡️ Activer les relances auto
  ⬜ Configurer l'auto-review AI

Phase 3 — Habitude (Jours 8-30)
  ████████████░░░░ 75%
  ✅ Relances auto activées
  ✅ Auto-review AI configuré
  ➡️ Compliance > 80%
  ⬜ Explorer un deuxième module

Phase 4 — Expansion (Jour 30+)
  ████████████████ 100%
  ✅ Compliance > 90%
  "Prêt pour le niveau suivant ?
   Découvrez les modules disponibles pour votre secteur."
```

## Nudges contextuels

```
Pas d'activité 24h après signup :
  → Email : "Votre espace vous attend. Ajoutez un collaborateur en 30 secondes."

Collaborateurs ajoutés, pas de relances auto :
  → In-app : "3 documents en retard. Activez les relances automatiques ?"

80% compliance atteinte :
  → Toast : "80% de compliance ! Top 20% de votre secteur."
  → Suggestion : "Activez l'auto-review pour les 20% restants"

2ème mois, 1 seul module :
  → Email : "Vous gérez vos documents dans Leezr. Et vos factures ?"
```

## Réduction de friction

| Friction | Solution |
|----------|---------|
| "Quels documents demander ?" | Auto-configuré par secteur + poste (jobdomain) |
| "Mes collab ne répondront pas" | Email + rappels auto + soumission mobile (photo) |
| "C'est compliqué à configurer" | Zéro config jour 1 — jobdomain fait tout |
| "Et si l'AI se trompe ?" | Score de confiance + explication + override facile |
| "C'est cher" | Starter gratuit, valeur prouvée avant de payer |
| "C'est juste un outil de docs" | **NON. Bloc 3-4 de la landing page ouvrent la perception.** |

---

# 10. SYNTHÈSE — LES 3 COUCHES

```
┌──────────────────────────────────────────────────────────────┐
│                                                               │
│  WEDGE (acquisition)                                         │
│  "Vos équipes sont-elles en règle ?"                         │
│  → Compliance documentaire, AI auto, relances                │
│  → Convertit le prospect. C'est tout.                        │
│                                                               │
│  ─────────────────────────────────────────────                │
│                                                               │
│  PRODUIT (réalité)                                           │
│  "L'espace de travail adapté à votre métier"                 │
│  → Plateforme modulaire, multi-vertical, AI transverse       │
│  → Jobdomain assemble, modules activables, tout connecté     │
│                                                               │
│  ─────────────────────────────────────────────                │
│                                                               │
│  POSITIONNEMENT (marché)                                     │
│  "La plateforme qui fait tourner vos opérations"             │
│  → Ni ERP lourd, ni SaaS jetable                            │
│  → Adapté. Extensible. Intelligent. Connecté.                │
│                                                               │
│  ─────────────────────────────────────────────                │
│                                                               │
│  DIFFÉRENCIATEUR                                              │
│  Adaptation métier automatique (jobdomain)                   │
│  + Modules connectés (automations cross-module)              │
│  + AI transverse (pas dans 1 module, partout)                │
│  + De gratuit à plateforme complète                          │
│                                                               │
│  CONCURRENT RÉEL : Excel + email + N outils séparés          │
│  PRICING : Starter 0€ → Pro 49-129€ → Scale 199-349€        │
│  TIME-TO-VALUE : 3 minutes 30                                │
│                                                               │
└──────────────────────────────────────────────────────────────┘
```
