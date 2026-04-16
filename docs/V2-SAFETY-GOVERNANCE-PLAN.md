# V2 — Safety, Email & Governance Plan

> Date : 2026-04-15
> Objectif : Rendre la plateforme SAFE, MAÎTRISÉE, EXPLOITABLE
> Score actuel : Platform 78/100 (post Lot 1) — Cible : 95/100

---

## Synthèse exécutive

4 audits parallèles ont révélé **3 systèmes manquants** qui empêchent Leezr d'être un SaaS exploitable en production :

| Système | État | Risque | Effort |
|---------|------|--------|--------|
| **Billing Safety** | Partiel — company excellent, platform lacunaire | CRITIQUE | Moyen |
| **Email Platform** | Basique — emails hardcodés, pas de branding, pas d'i18n | HAUT | Moyen-Fort |
| **Action Governance** | Incohérent — billing parfait, reste du SaaS non gouverné | HAUT | Moyen |

---

## PARTIE 1 — AUDIT : Ce qui est dangereux maintenant

### 1.1 Billing Safety — Findings critiques

**Ce qui est EXCELLENT (ne pas toucher)** :
- Plan change company : preview + confirm + idempotency + proration + audit ✓
- Cancel subscription : preview + confirm + audit ✓
- Platform invoice mutations : 7 actions avec idempotency + confirm + audit ✓
- Wallet reversal automatique sur void ✓
- Financial freeze guard ✓

**Ce qui est DANGEREUX** :

| # | Finding | Sévérité | Fichier |
|---|---------|----------|---------|
| B1 | `approveSubscription` — PAS d'audit log | CRITIQUE | `ApproveSubscriptionUseCase.php` |
| B2 | `rejectSubscription` — PAS d'audit log | HAUT | `BillingConfigCrudService.php` |
| B3 | `pay-now` — PAS de preview du montant total | CRITIQUE | `SubscriptionMutationController.php` |
| B4 | `setBillingDay` — PAS d'audit log | MOYEN | `SubscriptionMutationController.php` |
| B5 | Coupons CRUD — PAS d'audit log | MOYEN | `CouponCrudController.php` |
| B6 | Dunning force transition — PAS de preview (peut suspendre!) | CRITIQUE | `PlatformAdvancedMutationController.php` |
| B7 | Trial expiry — PAS d'automation (zombie state possible) | CRITIQUE | Scheduler manquant |
| B8 | Last payment method — suppression sans guard | HAUT | `CompanyPaymentMethodController.php` |
| B9 | Cancel preview — ne montre PAS la perte de données | HAUT | `_BillingCancelDialog.vue` |
| B10 | Addon auto-deactivation — manquante sur plan downgrade | HAUT | `PlanChangeExecutor.php` |
| B11 | Coupon transfer policy — non documentée sur plan change | MOYEN | `PlanChangeExecutor.php` |
| B12 | Proration invoice auto-charge failure — PAS de notification | HAUT | `PlanChangeExecutor.php` |

### 1.2 Email System — Findings critiques

**Ce qui existe** :
- 30+ notification topics avec registry centralisé ✓
- In-app notifications avec SSE realtime ✓
- NotificationDispatcher comme point d'entrée unique ✓
- Préférences par utilisateur par canal ✓
- 11 notifications billing avec email ✓

**Ce qui manque** :

| # | Finding | Sévérité | Impact |
|---|---------|----------|--------|
| E1 | Emails hardcodés en anglais (MailMessage fluent API) | CRITIQUE | Users FR reçoivent emails EN |
| E2 | Pas de templates Blade — pas de branding, logo, footer | HAUT | Emails non professionnels |
| E3 | Envoi synchrone — bloque le thread PHP | HAUT | Performance dégradée |
| E4 | Pas de log transactionnel email | HAUT | Impossible de prouver envoi |
| E5 | Pas d'email d'invitation membre | CRITIQUE | Nouveau membre = pas d'email |
| E6 | Pas d'email password reset / email verification | CRITIQUE | Sécurité basique manquante |
| E7 | Pas de lien unsubscribe | MOYEN | Non-conformité RGPD |
| E8 | Documents notifications = in-app only (pas d'email) | HAUT | Documents expirés non signalés |
| E9 | MailingList models existent mais inutilisés | INFO | Code mort |
| E10 | Pas de retry/DLQ sur envoi email échoué | MOYEN | Emails perdus silencieusement |

### 1.3 Action Governance — Findings critiques

**Best pattern identifié** (à répliquer partout) :
```
Platform billing mutations :
1. Confirmation dialog (titre + corps contextualisé)
2. Idempotency key (UUID par tentative)
3. :loading + :disabled sur bouton pendant exécution
4. API avec idempotency_key validé
5. Audit log backend (AuditAction + AuditLogger)
6. Toast success/error feedback
```

**Matrice de gouvernance par domaine** :

| Domaine | Confirm | Preview | Audit | Idempotency | Toast | Double-Click | Maturité |
|---------|---------|---------|-------|-------------|-------|--------------|----------|
| Company Billing | ✓✓ | ✓✓ | ✓✓ | ✓✓ | ✓✓ | ✓✓ | **Excellent** |
| Platform Billing | ✓✓ | ✓ | ✓✓ | ✓✓ | ✓✓ | ✓✓ | **Excellent** |
| Roles (company) | ✓ | ✗ | ✓ | ✗ | ✓ | Partial | **Bon** |
| Members | ✓ | ✗ | ✓ | ✗ | ✓ | **✗** | **Moyen** |
| Documents | ? | ✗ | ✗ | ✗ | ✗ | ? | **Pauvre** |
| Workflows | ✓ | ✗ | ✗ | ✗ | Partial | ✓ | **Moyen** |
| 2FA | ✓ | ✗ | ✗ | ✗ | ✗ | ? | **Pauvre** |
| Platform Admin | ? | ✗ | Partial | ✗ | ? | ? | **Moyen** |

**Controllers sans audit logs (mutations destructives)** :
- `DocumentRequestController::cancel()`
- `CompanyDocumentController::destroy()`
- `CustomDocumentTypeController::destroy()`
- `MemberDocumentController::destroy()`
- `CompanyFieldDefinitionController::destroy()`
- `WorkflowRuleController::destroy()`
- `TwoFactorController::disable()`
- `CompanyPaymentMethodController::deleteCard()`
- Platform: `FieldDefinitionController`, `UserController`, `RoleController` destroy()

---

## PARTIE 2 — BILLING SAFETY SYSTEM

### Objectif
Chaque action billing critique DOIT avoir : **preview → confirm → execute → audit → feedback**.

### 2.1 Corrections P0 (backend, sans UI)

#### P0-BS1 : Audit logs manquants (4 endpoints)

**Fichiers à modifier** :

```php
// 1. ApproveSubscriptionUseCase.php — après DB::transaction
app(AuditLogger::class)->logPlatform(
    AuditAction::SUBSCRIPTION_APPROVED, 'subscription', (string) $subscription->id,
    ['company_id' => $company->id, 'plan_key' => $subscription->plan_key],
);

// 2. BillingConfigCrudService::rejectSubscription() — après update
app(AuditLogger::class)->logPlatform(
    AuditAction::SUBSCRIPTION_REJECTED, 'subscription', (string) $subscription->id,
    ['company_id' => $company->id, 'reason' => $reason],
);

// 3. SubscriptionMutationController::setBillingDay() — après update
app(AuditLogger::class)->log(
    AuditAction::BILLING_DAY_CHANGED, 'subscription', (string) $subscription->id,
    ['old_day' => $oldDay, 'new_day' => $newDay],
);

// 4. CouponCrudController — store/update/destroy
app(AuditLogger::class)->logPlatform(
    AuditAction::COUPON_CREATED|UPDATED|DELETED, 'coupon', (string) $coupon->id,
    ['code' => $coupon->code, ...],
);
```

**Nouvelles constantes AuditAction** :
- `SUBSCRIPTION_APPROVED`
- `SUBSCRIPTION_REJECTED`
- `BILLING_DAY_CHANGED`
- `COUPON_CREATED`, `COUPON_UPDATED`, `COUPON_DELETED`

**Effort** : 1h — copier le pattern existant

#### P0-BS2 : Trial expiry automation

**Problème** : `trial_ends_at` passé → subscription reste `trialing` indéfiniment (zombie).

**Solution** : Ajouter commande schedulée :

```php
// app/Console/Commands/BillingCheckTrialExpiredCommand.php
class BillingCheckTrialExpiredCommand extends Command
{
    protected $signature = 'billing:check-trial-expired';

    public function handle()
    {
        $expired = Subscription::where('status', 'trialing')
            ->where('is_current', true)
            ->where('trial_ends_at', '<=', now())
            ->get();

        foreach ($expired as $sub) {
            // Si payment method exists → transition active + create renewal invoice
            // Si pas de payment method → transition expired + notify
            // Audit log dans les deux cas
        }
    }
}
```

**Scheduler** : `Schedule::command('billing:check-trial-expired')->dailyAt('06:00')`

**Effort** : 2-3h

#### P0-BS3 : Last payment method guard

```php
// CompanyPaymentMethodController::deleteCard()
$count = CompanyPaymentProfile::where('company_id', $company->id)->count();
if ($count <= 1) {
    return response()->json([
        'message' => 'Cannot delete last payment method.',
    ], 422);
}
```

**Effort** : 15min

### 2.2 Enrichissements P1 (UI + backend)

#### P1-BS1 : Pay-now preview

**Backend** : Ajouter `GET /billing/subscription/pay-now-preview`
```php
public function payNowPreview(): JsonResponse
{
    $invoices = Invoice::where('company_id', $company->id)
        ->whereIn('status', ['open', 'overdue'])
        ->get();

    return response()->json([
        'invoices_count' => $invoices->count(),
        'total_amount' => $invoices->sum('amount_due'),
        'currency' => $company->market?->currency ?? 'EUR',
        'invoices' => $invoices->map(fn ($i) => [
            'id' => $i->id, 'number' => $i->display_number,
            'amount_due' => $i->amount_due, 'status' => $i->status,
        ]),
    ]);
}
```

**Frontend** : Dialog de confirmation avec liste des factures + total

**Effort** : 2h

#### P1-BS2 : Cancel preview enrichi (données perdues)

**Backend** : Enrichir `cancelPreview()` avec :
```php
'data_consequences' => [
    'documents' => 'read_only_30_days',
    'members' => 'remain_limited',
    'automations' => 'stopped_immediately',
    'ai_quota' => 'reset',
],
```

**Frontend** : Section "Conséquences" dans `_BillingCancelDialog.vue`

**Effort** : 1-2h

#### P1-BS3 : Dunning transition preview

**Backend** : Endpoint ou inline data montrant :
```php
'consequences' => [
    'current_status' => 'overdue',
    'target_status' => 'uncollectible',
    'failure_action' => 'suspend', // from policy
    'will_suspend' => true,
],
```

**Frontend** : Warning rouge dans le dialog de confirmation existant

**Effort** : 1h

#### P1-BS4 : Addon auto-deactivation sur downgrade

```php
// PlanChangeExecutor.php — dans syncAddonSubscriptions()
$catalog = ModuleCatalogReadModel::forCompany($company);
foreach ($activeAddons as $addon) {
    $module = $catalog->firstWhere('key', $addon->module_key);
    if ($module && !$module['available_on_plan']) {
        $addon->update(['deactivated_at' => now()]);
        // Notification + audit
    }
}
```

**Effort** : 2h

---

## PARTIE 3 — EMAIL PLATFORM SYSTEM

### Objectif
Emails professionnels, multilingues, traçables, avec branding.

### Architecture cible

```
config/mail.php                    # Config Laravel (SMTP/SES/Postmark)
    ↓
NotificationDispatcher::send()     # Point d'entrée unique (existant)
    ↓
├── In-app channel                 # NotificationEvent (existant, mature)
└── Email channel                  # À refondre ↓
    ↓
LeezrMailTemplate (Blade)          # Layout commun (NOUVEAU)
    ↓
├── header (logo + nom)
├── body (contenu notification)
├── action button (CTA)
├── footer (unsubscribe + legal)
    ↓
Queue (dispatch via ShouldQueue)   # Async (NOUVEAU)
    ↓
EmailLog model                     # Traçabilité (NOUVEAU)
```

### 3.1 Phase A — Fondations email (P0)

#### A1 : Layout Blade commun

**Fichier** : `resources/views/emails/layout.blade.php`

```blade
<!DOCTYPE html>
<html lang="{{ $locale ?? 'fr' }}">
<head>
    <meta charset="utf-8">
    <style>
        /* Inline CSS pour email clients */
        .container { max-width: 600px; margin: 0 auto; font-family: -apple-system, sans-serif; }
        .header { background: #7367F0; padding: 24px; text-align: center; }
        .header img { height: 32px; }
        .body { padding: 32px 24px; }
        .btn { display: inline-block; padding: 12px 24px; background: #7367F0; color: #fff;
               text-decoration: none; border-radius: 6px; font-weight: 600; }
        .footer { padding: 16px 24px; font-size: 12px; color: #999; text-align: center;
                  border-top: 1px solid #eee; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <img src="{{ asset('images/logo-white.svg') }}" alt="Leezr">
        </div>
        <div class="body">
            <p>{{ __("emails.greeting", ['name' => $recipientName], $locale) }}</p>
            @yield('content')
            @hasSection('action')
                <p style="text-align: center; margin: 24px 0;">
                    <a href="@yield('action_url')" class="btn">@yield('action_label')</a>
                </p>
            @endif
        </div>
        <div class="footer">
            <p>{{ __("emails.footer_company", [], $locale) }}</p>
            @if($unsubscribeUrl ?? false)
                <p><a href="{{ $unsubscribeUrl }}">{{ __("emails.unsubscribe", [], $locale) }}</a></p>
            @endif
        </div>
    </div>
</body>
</html>
```

**Effort** : 2h

#### A2 : Queue tous les emails

**Modifier** : Toutes les 11 classes `Notifications/Billing/*.php`
```php
class TrialStarted extends Notification implements ShouldQueue
{
    use Queueable;
    public $queue = 'default';
    // ...
}
```

**Effort** : 30min (11 fichiers, ajout identique)

#### A3 : i18n des emails

**Modifier** : Toutes les classes de notification pour utiliser `__()` :
```php
public function toMail($notifiable): MailMessage
{
    $locale = $notifiable->preferredLocale() ?? 'fr';

    return (new MailMessage)
        ->subject(__('emails.trial_started.subject', [], $locale))
        ->greeting(__('emails.greeting', ['name' => $notifiable->first_name], $locale))
        ->line(__('emails.trial_started.line1', ['days' => $this->trialDays], $locale))
        ->action(__('emails.trial_started.action', [], $locale), url('/company/billing'));
}
```

**Nouvelles clés i18n** (fr.json + en.json) :
```json
"emails": {
    "greeting": "Bonjour {name},",
    "footer_company": "Leezr SAS — Tous droits réservés",
    "unsubscribe": "Se désinscrire de ces notifications",
    "trial_started": {
        "subject": "Votre essai gratuit a commencé !",
        "line1": "Votre essai gratuit de {days} jours a démarré.",
        "action": "Voir ma facturation"
    },
    "trial_expiring": {
        "subject": "Votre essai expire bientôt",
        "line1": "Votre essai expire dans {days} jours.",
        "action": "Choisir un plan"
    }
    // ... 9 autres topics billing
}
```

**Effort** : 3-4h

#### A4 : Email log transactionnel

**Migration** : `create_email_logs_table`
```php
Schema::create('email_logs', function (Blueprint $table) {
    $table->id();
    $table->string('topic_key');
    $table->morphs('recipient'); // recipient_type + recipient_id
    $table->foreignId('company_id')->nullable()->constrained();
    $table->string('to_email');
    $table->string('subject');
    $table->string('status')->default('sent'); // sent, failed, bounced
    $table->text('error')->nullable();
    $table->timestamp('sent_at');
    $table->timestamps();
    $table->index(['company_id', 'sent_at']);
    $table->index(['topic_key', 'sent_at']);
});
```

**Service** : `EmailLogger::log()` appelé par NotificationDispatcher après envoi

**Effort** : 2h

### 3.2 Phase B — Emails transactionnels manquants (P0-P1)

| Email | Topic key | Priorité | Template |
|-------|-----------|----------|----------|
| Invitation membre | `members.invited` | P0 | Nom inviteur + rôle + lien d'activation |
| Document expiré | `documents.expired` | P1 | Nom document + date expiration + lien |
| Document demandé | `documents.request_new` | P1 | Type + date limite + lien upload |
| Ticket répondu (company user) | `support.ticket_replied` | P1 | Extrait message + lien ticket |
| Paiement échoué (retry) | `billing.payment_failed` | P0 | Montant + raison + lien update card |

**Effort** : 1-2h par email (5-10h total)

### 3.3 Phase C — Platform Email Management (P2)

**Pas de page `/platform/emails`** pour le moment — c'est P2.

Ce qui est P2 :
- Panel de prévisualisation email
- Templates éditables sans code
- Campagnes marketing (MailingList)
- Provider webhooks (bounces, opens)
- Digest/regroupement

**Raison** : Le système actuel (NotificationDispatcher + preferences) est suffisant pour P0/P1. Les emails doivent d'abord être professionnels et multilingues avant d'ajouter du management.

---

## PARTIE 4 — ACTION GOVERNANCE PATTERN

### Objectif
**Tout** contrôleur qui fait une mutation destructive/financière DOIT suivre le même pattern.

### 4.1 Le pattern cible (6 couches)

```
┌─────────────────────────────────────────────┐
│ LAYER 1 — CONFIRMATION                      │
│ Frontend: useConfirm() ou VDialog custom     │
│ Question contextualisée + conséquences       │
└─────────────────────────────────────────────┘
         ↓ user confirms
┌─────────────────────────────────────────────┐
│ LAYER 2 — DOUBLE-CLICK PROTECTION           │
│ Frontend: :loading + :disabled sur bouton    │
│ Backend: idempotency_key (si financier)      │
└─────────────────────────────────────────────┘
         ↓ request sent
┌─────────────────────────────────────────────┐
│ LAYER 3 — BACKEND GUARDS                    │
│ Validation Laravel standard                  │
│ Business rules (financial_freeze, count>0)   │
│ Authorization (permissions/role)             │
└─────────────────────────────────────────────┘
         ↓ guards passed
┌─────────────────────────────────────────────┐
│ LAYER 4 — MUTATION + AUDIT                   │
│ DB::transaction() pour multi-step            │
│ AuditLogger::log() avec diffs               │
│ NotificationDispatcher si impacte un user    │
└─────────────────────────────────────────────┘
         ↓ mutation done
┌─────────────────────────────────────────────┐
│ LAYER 5 — FEEDBACK                           │
│ Frontend: toast success (useAppToast)        │
│ Frontend: toast error (catch → toast)        │
│ Refresh data affectée                        │
└─────────────────────────────────────────────┘
         ↓ logged
┌─────────────────────────────────────────────┐
│ LAYER 6 — TRAÇABILITÉ                        │
│ Activity Feed (via audit log existant)       │
│ Email log (si notification envoyée)          │
└─────────────────────────────────────────────┘
```

### 4.2 Niveau de gouvernance par type d'action

| Type | Confirm | Preview | Idempotency | Audit | Notification |
|------|---------|---------|-------------|-------|--------------|
| **Financier** (facture, remboursement, paiement) | Dialog + conséquences | OUI (preview endpoint) | OUI (UUID) | OUI (severity=warning+) | OUI (owner) |
| **Destructif** (supprimer membre, rôle, document) | useConfirm() | NON (sauf si impact financier) | NON | OUI | OUI (si impacte un user) |
| **État** (suspendre, activer, désactiver) | Dialog simple | OUI (si conséquences) | NON | OUI | OUI (owner) |
| **Config** (changer jour, préférences) | NON | NON | NON | OUI (severity=info) | NON |

### 4.3 Plan de déploiement — 12 controllers à corriger

#### Batch 1 : Audit logs manquants (backend only, 0 risque)

| Controller | Méthode | AuditAction à ajouter |
|------------|---------|----------------------|
| `WorkflowRuleController` | `destroy()` | `WORKFLOW_DELETED` |
| `WorkflowRuleController` | `toggleEnabled()` | `WORKFLOW_TOGGLED` |
| `TwoFactorController` | `disable()` | `TWO_FACTOR_DISABLED` |
| `CompanyDocumentController` | `destroy()` | `DOCUMENT_DELETED` |
| `MemberDocumentController` | `destroy()` | `MEMBER_DOCUMENT_DELETED` |
| `CustomDocumentTypeController` | `destroy()` | `CUSTOM_DOC_TYPE_DELETED` |
| `DocumentRequestController` | `cancel()` | `DOCUMENT_REQUEST_CANCELLED` |
| `CompanyFieldDefinitionController` | `destroy()` | `FIELD_DEFINITION_DELETED` |
| Platform `UserController` | `destroy()` | `PLATFORM_USER_DELETED` |
| Platform `RoleController` | `destroy()` | `PLATFORM_ROLE_DELETED` |

**Pattern** (copier-coller) :
```php
app(AuditLogger::class)->log(
    AuditAction::WORKFLOW_DELETED, 'workflow', (string) $rule->id,
    ['name' => $rule->name],
);
```

**Effort** : 2h (10 controllers × 12min)

#### Batch 2 : Double-click protection frontend

| Page | Action | Fix |
|------|--------|-----|
| `members/index.vue` | Remove member | Ajouter `:disabled="removing"` sur bouton confirm |
| `workflows/index.vue` | Delete rule | Ajouter `:disabled="deleting"` |
| `roles.vue` | Delete role | `:disabled` déjà partial → compléter |
| `documents/_DocumentsVault.vue` | Delete document | Vérifier + ajouter si manquant |

**Effort** : 1h

#### Batch 3 : Toast feedback manquant

| Page | Action | Fix |
|------|--------|-----|
| `TwoFactorController` | disable | Toast côté frontend |
| `documents/` | delete | Toast success/error |
| `fields/` | delete | Toast success/error |
| `workflows/` | delete | Explicit toast (pas juste store.error) |

**Effort** : 1h

---

## PARTIE 5 — ROADMAP PRIORISÉE

### P0 — Dangereux MAINTENANT (Sprint immédiat)

| # | Item | Type | Effort | Impact |
|---|------|------|--------|--------|
| 1 | Audit logs manquants billing (approve/reject/billing-day/coupons) | Backend | 1h | CRITIQUE — mutations financières non tracées |
| 2 | Audit logs manquants governance (10 controllers) | Backend | 2h | HAUT — mutations destructives non tracées |
| 3 | Trial expiry automation | Backend | 2-3h | CRITIQUE — zombie subscriptions possibles |
| 4 | Last payment method guard | Backend | 15min | HAUT — suppression accidentelle |
| 5 | Email i18n + Blade layout | Backend | 4-5h | CRITIQUE — users FR reçoivent emails EN |
| 6 | Email queue (ShouldQueue) | Backend | 30min | HAUT — envoi sync bloque PHP |
| 7 | Email invitation membre | Backend | 1h | CRITIQUE — aucun email d'invitation |
| 8 | Double-click protection (4 pages) | Frontend | 1h | HAUT — mutations dupliquées possibles |
| 9 | Toast feedback manquant (4 pages) | Frontend | 1h | MOYEN — erreurs silencieuses |

**Total P0** : ~14h de travail
**Résultat** : Toute mutation tracée, emails professionnels FR, pas de zombie, pas de double-click.

---

### P1 — Manquant pour SaaS sérieux (Sprint suivant)

| # | Item | Type | Effort | Impact |
|---|------|------|--------|--------|
| 10 | Pay-now preview (montant total) | Backend+Frontend | 2h | HAUT — paiement aveugle |
| 11 | Cancel preview enrichi (données perdues) | Backend+Frontend | 2h | HAUT — annulation sans info |
| 12 | Dunning transition preview (conséquences) | Frontend | 1h | CRITIQUE — suspension sans preview |
| 13 | Addon auto-deactivation sur downgrade | Backend | 2h | HAUT — addon incompatible facturé |
| 14 | Email log transactionnel | Backend | 2h | HAUT — pas de preuve d'envoi |
| 15 | Emails documents (expiré, demandé) | Backend | 2h | HAUT — documents non signalés |
| 16 | Email paiement échoué enrichi | Backend | 1h | HAUT — raison + lien update card |
| 17 | Coupon transfer policy documentée | Docs | 30min | MOYEN — comportement implicite |

**Total P1** : ~14h de travail
**Résultat** : Previews complets, emails transactionnels couverts, addon safety.

---

### P2 — Excellence SaaS (Sprint ultérieur)

| # | Item | Type | Effort |
|---|------|------|--------|
| 18 | Cancellation undo grace period | Backend+Frontend | 3h |
| 19 | Churn reason capture | Backend+Frontend | 2h |
| 20 | Email preview panel (platform) | Frontend | 3-4h |
| 21 | Provider webhooks (bounces) | Backend | 3h |
| 22 | Proration preview disclaimer (1h validity) | Frontend | 30min |
| 23 | SEPA debit day validation (1-28) | Backend | 30min |
| 24 | Email digest/regroupement | Backend | 4-5h |

**Total P2** : ~16h de travail

---

## Plan d'exécution — Premier sprint

### Sprint "SaaS SAFE" (P0)

```
Jour 1 matin :
├── Item 1 : Audit logs billing (4 endpoints)           ← 1h
├── Item 2 : Audit logs governance (10 controllers)     ← 2h  (parallélisable)
├── Item 3 : Trial expiry command + scheduler            ← 2h
└── Item 4 : Last payment method guard                   ← 15min

Jour 1 après-midi :
├── Item 5 : Email Blade layout + i18n (11 notifications) ← 4h
└── Item 6 : ShouldQueue sur 11 notifications             ← 30min

Jour 2 matin :
├── Item 7 : Email invitation membre                     ← 1h
├── Item 8 : Double-click protection (4 pages)           ← 1h
└── Item 9 : Toast feedback (4 pages)                    ← 1h

Jour 2 après-midi :
├── ADR dans 04-decisions.md
├── php artisan test (suite verte)
└── pnpm build (0 erreur)
```

**Résultat** : Score safety passe de ~55/100 à ~85/100

### Sprint "SaaS COMPLET" (P1)

```
Items 10-17 en 2 jours
Score safety → 95/100
```

---

## Fichiers impactés (résumé P0)

| Action | Fichier |
|--------|---------|
| MODIFIER | `app/Core/Audit/AuditAction.php` (7 nouvelles constantes) |
| MODIFIER | `app/Modules/Platform/Billing/UseCases/ApproveSubscriptionUseCase.php` |
| MODIFIER | `app/Core/Billing/BillingConfigCrudService.php` |
| MODIFIER | `app/Modules/Core/Billing/Http/SubscriptionMutationController.php` |
| MODIFIER | `app/Modules/Platform/Billing/Http/CouponCrudController.php` |
| MODIFIER | 10 controllers (audit logs governance) |
| MODIFIER | 11 fichiers `app/Notifications/Billing/*.php` (i18n + queue) |
| MODIFIER | `app/Core/Notifications/NotificationDispatcher.php` (email log) |
| MODIFIER | 4 pages Vue (double-click + toast) |
| CRÉER | `resources/views/emails/layout.blade.php` |
| CRÉER | `app/Console/Commands/BillingCheckTrialExpiredCommand.php` |
| CRÉER | Migration `create_email_logs_table` |
| MODIFIER | `routes/console.php` (scheduler trial check) |
| MODIFIER | `resources/js/plugins/i18n/locales/fr.json` (~50 clés emails) |
| MODIFIER | `resources/js/plugins/i18n/locales/en.json` (~50 clés emails) |
| MODIFIER | `docs/bmad/04-decisions.md` (ADR) |
