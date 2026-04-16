# V2-AUDIT-TENANCY — Isolation Multi-Tenant & Sécurité Données

> Mode : Security Architect | Audit exhaustif de l'isolation des données entre companies

## 1. Contexte actuel

Leezr est un SaaS multi-tenant à base de données partagée (shared database, shared schema). Chaque company est isolée par un champ `company_id` présent sur 40+ modèles Eloquent. L'isolation repose sur 3 mécanismes :

1. **Middleware `SetCompanyContext`** — valide le header `X-Company-Id`, vérifie l'existence de la company, contrôle le membership de l'utilisateur, stocke l'objet Company dans le request context
2. **Middleware `EnsureCompanyAccess`** — gating RBAC (4 abilities : access-surface, use-module, use-permission, manage-structure)
3. **Scoping explicite** — chaque query inclut `->where('company_id', $company->id)` manuellement

Il n'existe aucun global scope Eloquent, aucun trait `BelongsToCompany`, aucune protection automatique au niveau du modèle.

## 2. État existant

### Backend — Modèles avec company_id (40+ modèles)

**Core Models (3)** : Company (root aggregate), Membership, Shipment
**Billing Models (17)** : Invoice, Payment, Subscription, CompanyWallet, CompanyWalletTransaction, CompanyPaymentProfile, CompanyPaymentCustomer, CompanyAddonSubscription, CompanyEntitlements, LedgerEntry, ScheduledDebit, BillingCheckoutSession, BillingExpectedConfirmation, CreditNote, InvoiceLine, FinancialSnapshot, PlanChangeIntent
**Documents & Compliance (6)** : CompanyDocument, MemberDocument, DocumentRequest, DocumentType, DocumentTypeActivation, CompanyDocumentSetting
**Other Core (13)** : CompanyModule, CompanyModuleActivationReason, CompanyRole, FieldDefinition, FieldActivation, FieldValue, CompanyAuditLog, CompanyPresetSnapshot, SupportTicket, SupportMessage, NotificationPreference, NotificationEvent, AiRequestLog

### Backend — Middleware Stack

```
Company routes: api + auth:sanctum + company.context + session.governance
Platform routes: api + auth:platform + session.governance (NO company.context)
```

SetCompanyContext (7 étapes) :
1. Extraire company_id du header X-Company-Id ou query param
2. Rejeter si absent (400)
3. Charger company (404 si inexistante)
4. Vérifier suspension (403, sauf bypass billing)
5. Vérifier membership (403 si non-membre)
6. Invariant RBAC : non-owner doit avoir CompanyRole (403 + log critical)
7. Stocker company dans request context

### Backend — ReadModels

Tous les ReadModels company-scoped acceptent `Company $company` en paramètre :
- ShipmentReadModel, MyDeliveryReadModel, CompanyBillingReadService, CompanyDocumentReadModel, CompanyDocumentActivationReadModel, DocumentRequestQueueReadModel, CompanyUserProfileReadModel, CompanyProfileReadModel

Pattern uniforme : `Model::where('company_id', $company->id)->...`

### Backend — Schema

Toutes les tables tenant-scoped utilisent :
- `foreignId('company_id')->constrained()->cascadeOnDelete()`
- Index composites `(company_id, status)`, `(company_id, reference)` etc.
- Contrainte d'unicité composée quand applicable

### Frontend

Aucune isolation frontend spécifique. Le header `X-Company-Id` est envoyé automatiquement par l'API client HTTP configuré dans le runtime.

### Tests existants

- `AuditLogTest::test_company_audit_log_tenant_isolation()` — vérifie l'isolation cross-company sur les audit logs
- `CompanyAccessPolicyTest` — vérifie les 4 abilities (access-surface, use-module, use-permission, manage-structure)
- `AccessPipelineInvariantsTest` — invariants structurels (permissions existent, pas de routes orphelines)
- 34 fichiers de test référencent tenancy, isolation ou authorization

## 3. Problèmes identifiés

### P0 — CRITIQUE SÉCURITÉ

**P0-1 : Aucun global scope automatique**
L'isolation repose à 100% sur la discipline du développeur. Chaque query doit manuellement inclure `->where('company_id', ...)`. Un oubli = fuite de données cross-tenant. Le codebase actuel est correct (audit vérifié, 0 patterns dangereux trouvés), mais c'est fragile face à la croissance de l'équipe.

**P0-2 : Pas de trait BelongsToCompany**
Il n'existe aucun trait partagé qui centraliserait :
- Le global scope
- La relation `company()`
- La validation de company_id au create/update
- Le boot automatique du scope

### P1 — URGENT

**P1-1 : Tests d'isolation incomplets**
Seul AuditLogTest a un test explicite cross-tenant. Les 39 autres modèles n'ont pas de test dédié vérifiant qu'un utilisateur d'une company A ne peut pas accéder aux données d'une company B via l'API.

**P1-2 : Webhook processing sans double-check systématique**
StripeEventProcessor valide company_id après lookup par provider_payment_id. Le pattern est correct mais pas formalisé comme invariant testable.

### P2 — AMÉLIORATIONS

**P2-1 : Pas d'audit trail des tentatives cross-tenant**
Si un utilisateur tente d'accéder à des données hors de sa company, il reçoit un 403/404 mais aucun log de sécurité n'est enregistré pour détecter des patterns d'attaque.

**P2-2 : Absence de rate limiting sur les tentatives cross-tenant**
Pas de mécanisme pour détecter et bloquer un utilisateur qui testerait systématiquement des company_id différents.

## 4. Risques

### Risques techniques
- **Fuite de données** : Un `Model::find($id)` sans where company_id dans un nouveau controller = données cross-tenant exposées
- **Régression silencieuse** : Pas de filet de sécurité automatique, la CI ne détecte pas un oubli de scoping
- **Webhook injection** : Un attaquant forgeant un webhook Stripe avec un company_id arbitraire (mitigé par la signature Stripe)

### Risques produit
- **Conformité RGPD** : En cas de fuite cross-tenant, obligation de notification dans les 72h
- **Confiance client** : Une fuite de données détruit la confiance (B2B = risque de churning massif)
- **Certification** : ISO 27001 / SOC 2 exigent une isolation formalisée et testée

## 5. Gaps architecturels

| Gap | Gravité | Existant | Cible |
|-----|---------|----------|-------|
| Global scope automatique | CRITIQUE | Aucun | Trait + addGlobalScope |
| Trait BelongsToCompany | CRITIQUE | Aucun | Trait avec scope + relation + boot |
| Tests cross-tenant par modèle | ÉLEVÉE | 1/40 | 40/40 |
| Audit tentatives cross-tenant | MOYENNE | Aucun | SecurityDetector enrichi |
| Rate limiting cross-tenant | BASSE | Aucun | Throttle middleware |

## 6. Contrats manquants

### Backend
- `BelongsToCompany` trait avec global scope automatique
- `CompanyScope` global scope qui ajoute `where company_id = context.company_id`
- Test d'invariant : aucun modèle avec company_id sans le trait
- Test cross-tenant automatisé pour chaque endpoint company-scoped

### Frontend
- Aucun contrat manquant (l'isolation est 100% backend)
- Le header X-Company-Id est géré par le runtime

## 7. UX Impact

- **Transparent** : L'isolation est invisible pour l'utilisateur final
- **Confiance** : Les administrateurs doivent pouvoir certifier que leurs données sont isolées
- **Erreur 403** : En cas de tentative cross-tenant, l'UX actuelle renvoie un JSON 403 sans page dédiée

## 8. Proposition V2 — Architecture cible

### Phase 1 : Trait BelongsToCompany (Sprint V2-0.5)

```php
trait BelongsToCompany
{
    public static function bootBelongsToCompany(): void
    {
        static::addGlobalScope('company', new CompanyScope);

        static::creating(function ($model) {
            if (! $model->company_id && app()->bound('company.context')) {
                $model->company_id = app('company.context')->id;
            }
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
```

### Phase 2 : CompanyScope

```php
class CompanyScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if (app()->bound('company.context')) {
            $builder->where(
                $model->getTable() . '.company_id',
                app('company.context')->id
            );
        }
    }
}
```

### Phase 3 : Binding dans SetCompanyContext

```php
// Dans SetCompanyContext::handle(), après validation :
app()->instance('company.context', $company);
```

### Phase 4 : Migration des 40+ modèles
Ajouter `use BelongsToCompany;` sur chaque modèle tenant-scoped. Supprimer les `where('company_id', ...)` explicites rendus redondants dans les ReadModels.

### Phase 5 : Tests automatisés
- Test d'invariant : tous les modèles avec migration `company_id` doivent avoir le trait
- Test cross-tenant par endpoint : créer 2 companies, vérifier que les données ne fuient pas
- Test webhook : vérifier que les webhooks valident company_id

## 9. Règles non négociables

1. **Tout modèle avec company_id DOIT utiliser le trait BelongsToCompany** — aucune exception
2. **Le global scope NE DOIT PAS être désactivé** sauf dans les jobs platform-admin explicitement marqués `withoutGlobalScope('company')`
3. **Les tests cross-tenant sont OBLIGATOIRES** pour chaque nouveau endpoint company-scoped
4. **Le binding `company.context`** est la source de vérité unique — pas de company_id en dur dans les queries
5. **Platform routes** ne doivent JAMAIS avoir le binding company.context actif

## 10. Plan d'implémentation

| Phase | Scope | Effort | Dépendance |
|-------|-------|--------|------------|
| Phase 1 | Créer trait + scope + binding | 0.5j | Aucune |
| Phase 2 | Migrer les 40+ modèles | 1j | Phase 1 |
| Phase 3 | Adapter ReadModels (supprimer where redondants) | 1j | Phase 2 |
| Phase 4 | Tests cross-tenant exhaustifs | 1.5j | Phase 2 |
| Phase 5 | Audit trail cross-tenant | 0.5j | Phase 1 |
| **Total** | | **4.5j** | |

## 11. Impacts sur autres modules

- **Billing** : Les 17 modèles billing doivent adopter le trait. Les webhooks Stripe doivent contourner le scope (platform context)
- **Documents** : Les 6 modèles documents doivent adopter le trait
- **AI** : AiRequestLog doit adopter le trait (a company_id nullable — attention au scope conditionnel)
- **Support** : SupportTicket et SupportMessage doivent adopter le trait
- **Audit** : CompanyAuditLog doit adopter le trait
- **Platform admin** : Les controllers platform qui listent les données cross-company doivent utiliser `withoutGlobalScope('company')` explicitement

## 12. Dépendances avec autres audits

- **V2-AUDIT-RBAC** : Le trait BelongsToCompany s'appuie sur le même middleware SetCompanyContext que le RBAC. Les deux sont complémentaires (tenancy = isolation des données, RBAC = contrôle des actions)
- **V2-AUDIT-REALTIME** : Les événements SSE sont déjà scopés par company_id dans EventEnvelope. Le trait ne change rien au realtime
- **V2-AUDIT-AI-ENGINE** : AiRequestLog a un company_id nullable (logs platform aussi). Le trait doit gérer ce cas via une condition dans le scope

---

> **Verdict** : L'isolation actuelle est **fonctionnelle et correcte** (0 fuite détectée), mais **fragile** face à la croissance. Le trait BelongsToCompany + global scope est un investissement de 4.5 jours qui élimine une classe entière de bugs de sécurité.
