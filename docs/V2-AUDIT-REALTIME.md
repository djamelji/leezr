# V2-AUDIT-REALTIME — Système SSE & Temps Réel

> Mode : Realtime Architect | Mapping exhaustif topic → store → UX

## 1. Contexte actuel

Leezr dispose d'une infrastructure SSE complète côté backend (ADR-125 à ADR-128), construite autour de Redis pub/sub, EventEnvelope, TopicRegistry, et un streaming controller. Le frontend a un client SSE (RealtimeClient), un bus d'événements (DomainEventBus), un router par catégorie (ChannelRouter) et un composable d'abonnement (useRealtimeSubscription).

**Le problème central** : sur 22 topics backend définis, seuls 3 sont consommés par le frontend. 19 topics émettent des événements que personne n'écoute.

## 2. État existant

### Backend — TopicRegistry (22 topics)

**Fichier** : `app/Core/Realtime/TopicRegistry.php` (251 lignes)

| # | Topic | Description | Catégories | Ciblage |
|---|-------|-------------|------------|---------|
| 1 | `rbac.changed` | Rôle/permissions modifiés | invalidation, domain, audit | company |
| 2 | `modules.changed` | Module activé/désactivé | invalidation, domain, audit | company |
| 3 | `plan.changed` | Plan company changé | invalidation, domain, audit | company |
| 4 | `jobdomain.changed` | Jobdomain assigné | invalidation, domain, audit | company |
| 5 | `members.changed` | Membre ajouté/supprimé/rôle | invalidation, domain, audit | company |
| 6 | `member.joined` | Nouveau membre rejoint | domain, notification | company |
| 7 | `member.removed` | Membre supprimé | domain, notification | company |
| 8 | `role.assigned` | Rôle assigné à un membre | domain, notification | company |
| 9 | `module.activated` | Module activé pour company | domain, notification | company |
| 10 | `module.deactivated` | Module désactivé | domain, notification | company |
| 11 | `document.updated` | Document uploadé/reviewé/supprimé | domain, notification | company |
| 12 | `billing.updated` | Subscription/invoice/payment | domain, notification | company |
| 13 | `automation.updated` | Automation rule exécutée | domain | company |
| 14 | `automation.run.completed` | Tâche scheduler terminée | domain | platform |
| 15 | `security.alert` | Alerte sécurité | security | platform |
| 16 | `audit.logged` | Événement audit enregistré | audit | company |
| 17 | `notification.created` | Notification in-app créée | notification | user |

### Backend — Points d'émission (20 fichiers)

**Documents (8 points)** : DocumentRequestController (3), MemberDocumentController (4), ProcessDocumentAiJob (2)
**Billing (2 points)** : CompanyPaymentMethodController (2)
**Modules (1 point)** : CompanyModuleController (1)
**Jobdomain (1 point)** : CompanyJobdomainController (1)
**Notifications (1 point)** : NotificationDispatcher (1)
**Audit (1 point)** : AuditLogger (1)
**Security (1 point)** : SecurityDetector (1)
**Scheduler (1 point)** : SchedulerInstrumentation (1)

### Backend — Transport & Infrastructure

**SseRealtimePublisher** : Redis sorted set par company (`leezr:realtime:company:{id}`), channel platform (`leezr:realtime:platform`), TTL 120s, dual-write ZADD + PUBLISH.

**EventEnvelope** : `{ id: ULID, topic, category, version: 2, company_id, user_id, payload, invalidates[], timestamp }`

**RealtimeStreamController** : Endpoint `GET /api/realtime/stream`, polling Redis ~1s, filtrage par categories/topics, heartbeat 30s, timeout 300s, reconnection max 3 attempts.

### Frontend — Infrastructure

**RealtimeClient** (`resources/js/core/realtime/RealtimeClient.js`) :
- EventSource vers `/api/realtime/stream?company_id={id}`
- Listeners sur 5 types SSE : `invalidate`, `domain`, `notification`, `audit`, `security`
- Debounce invalidation 2s
- Reconnection: 3 attempts, backoff exponentiel 2s→4s→8s
- Fallback polling après 3 échecs

**DomainEventBus** (`resources/js/core/realtime/DomainEventBus.js`) : Singleton `on(topic, callback)`, `off(topic, callback)`, `dispatch(envelope)`, `clear()`

**ChannelRouter** (`resources/js/core/realtime/ChannelRouter.js`) : Dispatch par type SSE vers handlers (invalidation, domain, notification, audit, security)

**useRealtimeSubscription** (`resources/js/core/realtime/useRealtimeSubscription.js`) : Composable avec auto-cleanup `onUnmounted`

### Frontend — Consommateurs actuels (SEULEMENT 3+1)

| # | Consommateur | Topic | Comportement |
|---|-------------|-------|-------------|
| 1 | `documents.store.js` | `document.updated` | Smart merge ai_status, soft refetch requests |
| 2 | `_DocumentsRequests.vue` | `document.updated` | Dispatch vers store.handleRealtimeEvent |
| 3 | `platform/automations/index.vue` | `automation.run.completed` | Refresh task list + drawer runs |
| 4 | `MemberDocumentsWorkflowPanel.vue` | `document.updated` | Update member doc ai_status |

### Runtime Initialization

**Company scope** : ChannelRouter avec invalidation (cache), domain (bus), notification, audit, security handlers. Fallback polling après 3 échecs SSE.

**Platform scope** : ChannelRouter avec domain handler uniquement. Autres catégories: no-op.

**Disconnect** : Sur logout, company switch, scope switch — `disconnect()` + `domainEventBus.clear()`.

### Monitoring

**ConnectionTracker** : Tracks active SSE connections in Redis. Connect/disconnect counters, per-user/per-company limits.

## 3. Problèmes identifiés

### P0 — CRITIQUE

**P0-1 : 19 topics sans consommateur frontend**
Sur 22 topics définis, seuls `document.updated` et `automation.run.completed` sont écoutés. Les 20 autres émettent des événements vers le néant. Cela signifie que :
- Changements de rôle (`rbac.changed`) → l'utilisateur garde ses anciennes permissions
- Changements de plan (`plan.changed`) → l'UI n'est pas mise à jour
- Changements de modules (`modules.changed`) → la navigation reste obsolète
- Notifications (`notification.created`) → aucune notification in-app en temps réel
- Alertes sécurité (`security.alert`) → platform admin non alerté en temps réel

### P1 — URGENT

**P1-1 : Pas de matrice topic → store → UX**
Il n'existe aucun document définissant pour chaque topic : quel store doit réagir, quelle action (refetch, merge, invalidate), quel feedback UX (toast, badge, refresh).

**P1-2 : Invalidation handler existe mais n'est branché sur aucun store**
Le ChannelRouter route les événements `invalidate` vers un handler qui gère le cache, mais aucun store ne s'abonne aux invalidations pour se rafraîchir.

**P1-3 : Fallback polling non implémenté**
Le RealtimeClient appelle `onFallback()` après 3 échecs SSE, mais les stores ne passent pas en mode polling. Seul le documents store fait du polling indépendant.

### P2 — AMÉLIORATIONS

**P2-1 : Pas de notification badge temps réel**
Le topic `notification.created` est émis mais aucun composant ne l'affiche. Pas de badge sur l'icône notification dans la navbar.

**P2-2 : Pas d'indicator de connexion SSE**
L'utilisateur ne sait pas si le SSE est connecté, déconnecté, ou en fallback polling. Pas de status indicator dans l'UI.

## 4. Risques

### Risques techniques
- **Stale data** : Sans consommation SSE, les données affichées deviennent obsolètes silencieusement
- **Race conditions** : Deux utilisateurs modifient la même donnée → pas de conflit visible
- **Charge serveur inutile** : Le backend émet 20 types d'événements que personne ne consomme → charge Redis pour rien

### Risques produit
- **Collaboration** : Deux admins sur la même page → modifications invisibles de l'autre
- **Confiance** : "J'ai changé le rôle mais rien ne se passe" → l'utilisateur recharge la page
- **Billing** : Un paiement réussi n'actualise pas l'UI en temps réel → l'utilisateur pense que le paiement a échoué

## 5. Gaps architecturels

| Gap | Gravité | Existant | Cible |
|-----|---------|----------|-------|
| Topics consommés | CRITIQUE | 2/22 (9%) | 22/22 (100%) |
| Matrice topic→store→UX | ÉLEVÉE | Aucune | Document de référence |
| Notification badge temps réel | ÉLEVÉE | Aucun | Badge + popup |
| Fallback polling stores | MOYENNE | 1 store | Tous les stores |
| Connection status UI | BASSE | Aucun | Indicator dans navbar |

## 6. Contrats manquants

### Backend
- Aucun contrat manquant — le backend est complet. 22 topics, 20 émetteurs, transport Redis, streaming controller.

### Frontend
- **Contrat store SSE** : Chaque store doit exposer `handleRealtimeEvent(payload)` et `handleInvalidation(keys)`
- **Contrat topic routing** : Le DomainEventBus doit avoir des handlers enregistrés pour chaque topic
- **Contrat fallback** : Chaque store SSE-aware doit supporter un mode polling (interval configurable)
- **Contrat UX** : Chaque topic doit définir son feedback UX (toast, badge, refetch, merge)

## 7. UX Impact

### Topic → UX Behavior Matrix (architecture cible)

| Topic | Store cible | Action | UX Feedback |
|-------|-------------|--------|-------------|
| `rbac.changed` | authStore | refetch /me | Toast "Vos permissions ont été mises à jour" |
| `modules.changed` | moduleStore | refetch modules | Toast + refresh navigation |
| `plan.changed` | billingStore | refetch overview | Toast "Votre plan a été mis à jour" |
| `jobdomain.changed` | settingsStore | refetch company | Toast + refresh navigation |
| `members.changed` | membersStore | refetch list (silent) | Aucun (merge silencieux) |
| `member.joined` | membersStore | refetch list (silent) | Toast "X a rejoint l'équipe" |
| `member.removed` | membersStore | refetch list (silent) | Toast "X a quitté l'équipe" |
| `role.assigned` | membersStore | refetch member | Aucun |
| `module.activated` | moduleStore | refetch modules | Toast "Module X activé" |
| `module.deactivated` | moduleStore | refetch modules | Toast "Module X désactivé" |
| `document.updated` | documentsStore | smart merge / refetch | Aucun (déjà implémenté) |
| `billing.updated` | billingStore | refetch overview | Aucun (merge silencieux) |
| `automation.updated` | — | — | Aucun (company-side, pas encore de UI) |
| `automation.run.completed` | automationsStore | refetch tasks | Aucun (déjà implémenté) |
| `security.alert` | — | — | Badge notification platform |
| `audit.logged` | auditStore | refetch if on audit page | Aucun |
| `notification.created` | notificationStore | append + badge++ | Badge notification + popup |

## 8. Proposition V2 — Architecture cible

### Store SSE Contract

```javascript
// Chaque store SSE-aware DOIT implémenter :
{
  // Called by DomainEventBus when topic matches
  handleRealtimeEvent(envelope) {
    const { type, ...payload } = envelope.payload
    // Dispatch par type d'événement
  },

  // Called by invalidation handler when store key in invalidates[]
  handleInvalidation() {
    this.fetchData({ silent: true })
  },

  // Fallback polling interval (ms), 0 = disabled
  POLLING_INTERVAL: 30000,
}
```

### Topic Registration (centralisé)

```javascript
// core/realtime/topicHandlers.js
export const topicHandlers = {
  'rbac.changed': (envelope) => {
    useAuthStore().fetchMe()
    toast.info(t('realtime.rbacChanged'))
  },
  'modules.changed': (envelope) => {
    useModuleStore().fetchModules({ silent: true })
    useNavStore().refresh()
  },
  'notification.created': (envelope) => {
    useNotificationStore().append(envelope.payload)
    // Badge increment handled by store
  },
  // ... all 22 topics
}
```

### Connection Status Widget

```vue
<RealtimeStatusIndicator />
<!-- Green dot = connected, Yellow = reconnecting, Red = disconnected/polling -->
```

## 9. Règles non négociables

1. **Chaque topic DOIT avoir un handler frontend** — aucun événement émis sans consommateur
2. **Le DomainEventBus est la source de vérité** pour le dispatch des événements domain
3. **Le feedback UX est défini par la matrice** — pas de toast/badge ad hoc
4. **Le fallback polling est OBLIGATOIRE** pour les stores critiques (auth, billing, members)
5. **La reconnection SSE est transparente** — l'utilisateur ne doit pas remarquer une reconnection (sauf indicateur subtil)

## 10. Plan d'implémentation

| Phase | Scope | Effort | Dépendance |
|-------|-------|--------|------------|
| Phase 1 | Définir la matrice topic→store→UX (ce document) | 0.5j | Aucune |
| Phase 2 | Brancher les 5 topics critiques (rbac, modules, plan, billing, notification) | 2j | V2 Sprint 1 (stores standardisés) |
| Phase 3 | Brancher les 10 topics secondaires | 1.5j | Phase 2 |
| Phase 4 | Fallback polling pour stores critiques | 1j | Phase 2 |
| Phase 5 | Notification badge temps réel | 0.5j | Phase 2 |
| Phase 6 | Connection status indicator | 0.5j | Phase 2 |
| **Total** | | **6j** | |

## 11. Impacts sur autres modules

- **Auth** : Le store auth doit réagir à `rbac.changed` pour rafraîchir les permissions
- **Billing** : Le store billing doit réagir à `billing.updated` et `plan.changed`
- **Members** : Le store members doit réagir à `members.changed`, `member.joined`, `member.removed`
- **Documents** : Déjà implémenté — modèle à suivre pour les autres
- **Modules** : Le store modules doit réagir à `modules.changed`, `module.activated`, `module.deactivated`
- **Notifications** : Nouveau store à créer pour gérer les notifications in-app temps réel
- **Automations** : Déjà implémenté pour platform — pas de changement nécessaire

## 12. Dépendances avec autres audits

- **V2-AUDIT-RBAC** : Le topic `rbac.changed` est le lien direct. L'audit RBAC définit le frontend permission contract, l'audit realtime définit comment les permissions sont rafraîchies en temps réel
- **V2-AUDIT-TENANCY** : Les événements SSE sont scopés par company_id dans EventEnvelope. L'isolation est assurée au niveau transport
- **V2-AUDIT-AI-ENGINE** : Le topic `document.updated` avec type `ai.completed`/`ai.failed` est déjà consommé. Si l'AI s'étend à d'autres modules, de nouveaux topics seront nécessaires
- **V2-AUDIT-AUTOMATION** : Le topic `automation.run.completed` est déjà consommé. Si des automations company-scoped sont ajoutées, le topic `automation.updated` devra être consommé

---

> **Verdict** : Le backend SSE est **production-grade et complet**. Le gap est 100% frontend : 19/22 topics sans consommateur. L'effort est de 6 jours pour un système temps réel complet, avec le documents store comme modèle de référence.
