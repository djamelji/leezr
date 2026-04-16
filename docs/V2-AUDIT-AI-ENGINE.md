# V2-AUDIT-AI-ENGINE — Moteur AI & Intégration Multi-Module

> Mode : AI Architect | Audit exhaustif du moteur AI et plan d'extension transverse

## 1. Contexte actuel

Leezr dispose d'un moteur AI production-grade (ADR-411 à ADR-431), calqué sur le pattern PaymentGateway : un AiGatewayManager résout les adapters par capability, un AiPolicyResolver gate l'activation par module et par company, et un pipeline de jobs asynchrones orchestre l'analyse. Le système est **fonctionnel et en production** pour le module Documents uniquement.

**Le problème central** : l'architecture AI est conçue pour être multi-module (`match($moduleKey)` dans le PolicyResolver), mais seul Documents l'utilise. Aucun autre module n'a été branché, et aucun plan d'extension n'existe.

## 2. État existant

### Core AI — Interface & Contracts

**AiProviderAdapter** (`app/Core/Ai/Contracts/AiProviderAdapter.php`) — Interface 7 méthodes :
- `key()`, `capabilities(): AiCapability[]`, `isAvailable(): bool`, `healthCheck(): AiHealthResult`
- `complete(prompt, options): AiResponse`, `vision(imagePath, prompt, options): AiResponse`, `extractText(imagePath, options): AiResponse`

**AiCapability** (enum) : `Vision`, `Completion`, `TextExtraction`

**AiResponse** (DTO) : `text`, `structuredData`, `confidence` (0.0-1.0), `tokensUsed`, `latencyMs`, `model`, `provider`

**AiHealthResult** (DTO) : `status` (healthy/degraded/down/misconfigured), `message`, `latencyMs`

**AiInsight** (DTO) : `type`, `severity` (info/success/warning/error), `messageKey` (i18n), `messageParams`, `metadata`

**AiDecisionResult** (DTO) : Intentions only (NEVER mutations) — `shouldAutoFillExpiry`, `detectedExpiryDate`, `shouldAutoReject`, `autoRejectReason`, `shouldNotifyExpiry`, `shouldNotifyErrors`, `insights[]`

### Core AI — Gateway Manager

**AiGatewayManager** (`app/Core/Ai/AiGatewayManager.php`) — 120 lignes :
- `getDefaultDriver()` — PlatformSetting.ai['driver'] → active PlatformAiModule → NullAiAdapter
- `adapterFor(providerKey)` — résout adapter par clé provider
- `adapterForCapability(AiCapability)` — premier adapter actif supportant la capability
- `availableProviders()` — array de providers installés/actifs avec health

### Core AI — Provider Registry

**AiProviderRegistry** (`app/Core/Ai/AiProviderRegistry.php`) — 106 lignes, 4 providers :

| Provider | Capabilities | Requires Credentials | Config Fields |
|----------|-------------|---------------------|---------------|
| null | [] | Non | — |
| ollama | Vision, Completion, TextExtraction | Oui | host, model, vision_model, timeout |
| anthropic | Vision, Completion, TextExtraction | Oui | api_key, model, timeout |
| openai | Vision, Completion, TextExtraction | Oui | api_key, organization, model, timeout |

### Core AI — Adapter Implementations

**AnthropicAiAdapter** (376 lignes) : API v1, claude-sonnet-4-5-20250929, timeout 60s, retry 3x backoff [1s,2s,4s], native PDF support (base64 document), JSON parsing (direct → markdown → regex), confidence extraction, rate limiting 429 handling, AiRequestLog.record().

**OllamaAiAdapter** (306 lignes) : `/api/generate`, models glm-ocr + qwen2.5-vl, timeout 60s, retry 3x, health check via `/api/tags`, image base64 in `images[]` array.

**NullAiAdapter** (52 lignes) : Always returns empty AiResponse (text='', confidence=0). Used when no provider configured.

### Core AI — Policy Resolution

**AiPolicy** (value object, 51 lignes) : `analysisEnabled`, `ocrEnabled`, `autoFillExpiry`, `autoRejectTypeMismatch`, `notifyExpiryDetected`, `notifyValidationErrors`, `minConfidenceThreshold`

**AiPolicyResolver** (54 lignes) — Cascade :
```
1. Gate: PlatformAiModule.active() exists? If no → disabled
2. match ($moduleKey) {
     'documents' => resolveDocuments($companyId),
     default => disabled ← TOUS LES AUTRES MODULES
   }
```

Document resolution : lit `CompanyDocumentSetting.ai_features`, fallback `PlatformSetting.ai['document_defaults']`.

### Document AI Pipeline

**ProcessDocumentAiJob** (398 lignes) — Queue `ai`, 3 retries, backoff [10s,30s,60s], timeout 120s :
1. Load document, set ai_status='processing'
2. Resolve policy (gate if disabled)
3. Download file to temp
4. PDF→image conversion (Anthropic: native, Ollama: ImageProcessor)
5. Analysis via DocumentAiAnalysisService
6. Decision via DocumentAiDecisionService
7. Execute intentions (mutations)
8. Store insights in ai_insights JSON
9. Build profile suggestions (MemberDocument only)
10. Mark ai_status='completed'
11. Publish SSE event document.updated

**DocumentAiAnalysisService** (259 lignes) — Cascade : MRZ → AI Vision → OCR fallback → empty result. Prompt engineering avec 10 document types (cni, passport, driving_license, residence_permit, kbis, rib, attestation, payslip, invoice, other). Confidence rules strictes (0.9-1.0 certain, 0.1-0.2 wrong type).

**DocumentAiDecisionService** (132 lignes) — Intentions only : auto-fill expiry, auto-reject type mismatch, notify expiry, notify errors. Confidence gate avant décision.

### Database Schema

**platform_ai_modules** : `provider_key` (unique), `name`, `is_installed`, `is_active`, `credentials` (encrypted), `config` (JSON), `health_status`, `sort_order`

**ai_request_logs** : `provider`, `model`, `capability`, `input_tokens`, `output_tokens`, `latency_ms`, `status`, `error_message`, `company_id` (nullable), `module_key`, `metadata` (JSON). Indexed by `(provider, created_at)`, `(company_id, created_at)`, `(module_key, created_at)`.

**Document columns** : `ai_status` (pending/processing/completed/failed), `ai_analysis` (JSON), `ai_insights` (JSON), `ai_suggestions` (JSON, MemberDocument only)

### Platform Governance

**PlatformAiController** : `/api/platform/ai/providers`, `/usage`, `/routing`, `/config`, `/health`

**PlatformAiMutationController** : PUT `/config`, `/providers/{key}/install`, `/activate`, `/deactivate`, `/credentials`, `/routing`, POST `/health-check`

**AiHealthReadService** : Provider health + queue stats + document processing stats → overall healthy assessment

**PlatformAiGovernanceReadService** : Lists DB modules + registry manifests, masks credentials

### Frontend

**Platform AI Page** : Providers list, health status, usage stats, configuration. Fully functional.

**Company Documents** : AI chip showing ai_status lifecycle (pending → processing → completed/failed), timeout UX 30s, retry button, confidence display. Smart merge via SSE.

## 3. Problèmes identifiés

### P0 — CRITIQUE

*Aucun P0* — Le système AI fonctionne correctement pour Documents.

### P1 — URGENT

**P1-1 : Extension verrouillée par le PolicyResolver**
Le `match($moduleKey)` dans AiPolicyResolver a un `default => disabled`. Pour brancher l'AI sur un nouveau module, il faut :
1. Ajouter un case dans le match
2. Créer un settings model pour le module (comme CompanyDocumentSetting)
3. Définir les policy fields pour ce module
4. Créer le job de processing spécifique

Ce n'est pas un "plugin" — c'est du code custom par module.

**P1-2 : AiCapability limité à 3 types**
L'enum ne couvre que Vision, Completion, TextExtraction. Des use cases comme Classification, Summarization, Translation, Anomaly Detection ne sont pas modélisés.

**P1-3 : Pas de quota AI par company**
Aucun mécanisme de rate limiting ou quota par company. Une company peut consommer toutes les ressources AI.

### P2 — AMÉLIORATIONS

**P2-1 : Prompts hardcodés**
Les prompts AI sont dans le code PHP (DocumentAiAnalysisService). Pas de templating, pas d'admin UI pour modifier les prompts.

**P2-2 : Pas de feedback loop**
Quand un admin corrige le résultat AI (reject → approve, change type), cette correction n'est pas utilisée pour améliorer les futurs résultats.

**P2-3 : Pas de batch processing**
Le pipeline traite les documents un par un. Pas de mode batch pour traiter N documents en parallèle.

**P2-4 : Pas de cost tracking**
Les tokens sont loggués mais pas de calcul de coût ($ par request, budget mensuel par company).

## 4. Risques

### Risques techniques
- **Single point of failure** : Si Anthropic est down et pas d'Ollama configuré, toute l'AI est bloquée
- **Coût incontrôlé** : Sans quota, une company avec beaucoup de documents peut générer des coûts API importants
- **Prompt injection** : Les documents uploadés pourraient contenir du texte malveillant dans le prompt

### Risques produit
- **Feature parity** : Seul Documents bénéficie de l'AI. Les autres modules (billing, shipments, support) sont "dumb"
- **Compétitivité** : Les concurrents proposent de l'AI transverse (anomaly detection, predictive analytics)
- **ROI** : L'infrastructure AI est prête mais sous-utilisée — coût de maintenance sans ROI proportionnel

## 5. Gaps architecturels

| Gap | Gravité | Existant | Cible |
|-----|---------|----------|-------|
| Modules branchés sur l'AI | ÉLEVÉE | 1/10+ | Extensible via pattern |
| AiCapability extensible | MOYENNE | 3 types | Enum extensible |
| Quota par company | MOYENNE | Aucun | Quota + billing |
| Prompt templating | BASSE | Hardcodé | Admin-configurable |
| Cost tracking | BASSE | Tokens seulement | $/request + budget |
| Feedback loop | BASSE | Aucun | Corrections → fine-tuning data |

## 6. Contrats manquants

### Backend
- **AiModuleContract** : Interface que chaque module doit implémenter pour brancher l'AI
  - `moduleKey(): string`
  - `policyFields(): array` (config UI)
  - `resolvePolicy(companyId): AiPolicy`
  - `buildPrompt(context): string`
  - `processResult(AiResponse): mixed`
- **AiQuotaManager** : Quota par company, par module, par période
- **AiCostCalculator** : Coût par provider/model/tokens → budget tracking

### Frontend
- **AiStatusChip** généralisé (pas spécifique à Documents)
- **AiInsightPanel** réutilisable (affiche les insights AI de n'importe quel module)
- **AiConfigPanel** par module dans les settings company

## 7. UX Impact

### Modules candidats à l'AI

| Module | Use Case AI | Type | Priorité |
|--------|------------|------|----------|
| **Support** | Auto-triage tickets par catégorie/priorité | Completion | P1 |
| **Support** | Suggestion de réponse (draft) | Completion | P2 |
| **Billing** | Anomaly detection (invoices inhabituelles) | Completion | P2 |
| **Shipments** | ETA prediction (si données historiques) | Completion | P3 |
| **Members** | Auto-fill profil depuis CV uploadé | Vision | P2 |
| **Documents** | Multi-langue analysis (GB documents) | Vision | P1 |

### UX Standards AI
- **Loading** : Skeleton + "Analyse en cours..." avec barre de progression estimée
- **Résultat** : Confidence chip (vert >80%, orange 50-80%, rouge <50%)
- **Actions** : "Accepter la suggestion", "Corriger", "Ignorer"
- **Erreur** : "L'analyse a échoué. Réessayer ?" avec bouton retry

## 8. Proposition V2 — Architecture cible

### AiModuleContract (Extension Pattern)

```php
interface AiModuleContract
{
    public function moduleKey(): string;
    public function policyFields(): array;
    public function resolvePolicy(int $companyId): AiPolicy;
    public function dispatchAnalysis(Model $entity): void;
}

// Exemple : SupportAiModule
class SupportAiModule implements AiModuleContract
{
    public function moduleKey(): string { return 'support'; }

    public function policyFields(): array {
        return [
            'auto_triage_enabled' => ['type' => 'boolean', 'default' => true],
            'suggest_response_enabled' => ['type' => 'boolean', 'default' => false],
        ];
    }

    public function resolvePolicy(int $companyId): AiPolicy { /* ... */ }

    public function dispatchAnalysis(Model $ticket): void {
        ProcessSupportTicketAiJob::dispatch($ticket);
    }
}
```

### PolicyResolver V2

```php
// AiPolicyResolver
public function resolve(string $moduleKey, int $companyId): AiPolicy
{
    $module = AiModuleContractRegistry::get($moduleKey);
    if (! $module) return AiPolicy::disabled();
    return $module->resolvePolicy($companyId);
}
```

### Quota System

```php
class AiQuotaManager
{
    public function canProcess(Company $company, string $moduleKey): bool
    {
        $used = AiRequestLog::where('company_id', $company->id)
            ->where('module_key', $moduleKey)
            ->where('created_at', '>=', now()->startOfMonth())
            ->count();

        $limit = $company->plan->ai_quota[$moduleKey] ?? 100;
        return $used < $limit;
    }
}
```

## 9. Règles non négociables

1. **L'AI est un service optionnel** — tout module DOIT fonctionner sans AI (graceful degradation)
2. **Les décisions AI sont des intentions** — JAMAIS des mutations directes. Le code métier décide d'appliquer ou non
3. **Le PolicyResolver est le gate unique** — aucun module ne bypass le policy check
4. **Les quotas sont par company + par module** — pas de quota global
5. **Les prompts sont versionnés** — chaque changement de prompt est tracé (pour reproduire les résultats)
6. **Le provider est abstrait** — le code métier ne sait pas si c'est Anthropic, Ollama ou OpenAI

## 10. Plan d'implémentation

| Phase | Scope | Effort | Dépendance |
|-------|-------|--------|------------|
| Phase 1 | AiModuleContract + Registry | 1j | Aucune |
| Phase 2 | Quota system + billing integration | 1.5j | Phase 1 |
| Phase 3 | Support module AI (auto-triage) | 2j | Phase 1 |
| Phase 4 | AiCapability enum extension | 0.5j | Phase 1 |
| Phase 5 | Cost tracking + admin dashboard | 1j | Phase 2 |
| Phase 6 | Frontend AI components réutilisables | 1j | Phase 3 |
| **Total** | | **7j** | |

## 11. Impacts sur autres modules

- **Documents** : Aucun changement — reste le module de référence. Migrer vers AiModuleContract quand il sera prêt
- **Support** : Premier candidat pour l'extension AI. Auto-triage des tickets par catégorie
- **Billing** : Anomaly detection sur les invoices (montants inhabituels, patterns de consommation)
- **Members** : Auto-fill profil depuis documents uploadés (déjà partiellement implémenté via ai_suggestions)
- **Platform** : Dashboard AI enrichi avec usage par module, quotas, coûts

## 12. Dépendances avec autres audits

- **V2-AUDIT-TENANCY** : AiRequestLog a company_id nullable. Le trait BelongsToCompany doit gérer le cas nullable (logs platform AI)
- **V2-AUDIT-RBAC** : Les actions AI (retry, configure) doivent être gatées par permission. Nouvelles permissions à ajouter par module AI
- **V2-AUDIT-REALTIME** : Le topic `document.updated` avec `ai.completed`/`ai.failed` est le modèle. Chaque nouveau module AI aura besoin d'un topic SSE équivalent
- **V2-AUDIT-MULTI-MARKET** : L'AI n'est pas market-aware. Les prompts sont en français. L'extension multi-market nécessite des prompts localisés (GB documents en anglais)
- **V2-AUDIT-AUTOMATION** : Les tâches AI scheduled (batch processing) pourraient être gérées par l'Automation Center

---

> **Verdict** : Le moteur AI est **production-grade et bien architecturé**. Le gap est l'extension aux autres modules — l'architecture actuelle le permet (match case + policy resolver) mais nécessite un AiModuleContract formel pour être scalable. L'effort est de 7 jours pour l'infrastructure + premier module étendu (Support).
