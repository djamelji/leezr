# V2-AUDIT-MULTI-MARKET — Système Multi-Marché & Internationalisation Frontend

> Mode : Product Architect | Audit du market-aware frontend et du bug critique applyMarket

## 1. Contexte actuel

Leezr est conçu pour opérer sur plusieurs marchés européens (FR, GB, et extensible). Le backend multi-market est complet (ADR-103, ADR-104) : Market model avec 11 colonnes, MarketRegistry, MarketResolver, TranslationRepository (4 couches de fallback), TaxContextResolver (5 cas TVA), FxRateFetchJob, et PlatformPaymentMethodRule (market-aware).

Le frontend dispose d'un `worldStore` qui stocke les paramètres du market actif (currency, locale, timezone, dialCode) et de fonctions utilitaires `formatMoney()` et `formatDateTime()` qui s'en servent. **Mais le worldStore n'est jamais mis à jour lors du switch de company**, ce qui constitue un bug critique : un utilisateur multi-company affiche les montants dans la mauvaise devise.

## 2. État existant

### Backend — Market Model

**Market** (`app/Core/Markets/Market.php`) — 11 colonnes :
`key` (PK, ex: 'FR'), `name`, `currency` (ISO 4217), `vat_rate_bps` (basis points, 2000=20%), `locale` (BCP 47), `timezone` (IANA), `dial_code`, `flag_code` (ISO 3166-1 alpha-2), `flag_svg` (SVG sanitisé), `is_active`, `is_default`, `is_eu`, `sort_order`

**Relations** : `hasMany(LegalStatus)`, `belongsToMany(Language)`, `hasMany(Company)`, `hasMany(TranslationOverride)`

**Markets actuels** :
| Market | Currency | Locale | Timezone | VAT | Legal Statuses |
|--------|----------|--------|----------|-----|----------------|
| FR | EUR | fr-FR | Europe/Paris | 20% | SAS, SASU, SARL, EURL, SA, SNC, SCI, Auto-entrepreneur |
| GB | GBP | en-GB | Europe/London | 20% | Ltd, PLC, LLP, Sole Trader |

### Backend — Market Resolution

**MarketResolver** (`app/Core/Markets/MarketResolver.php`) :
- `resolveForCompany(Company)` → lit `company.market_key`, charge Market, fallback `resolveDefault()`
- `resolveDefault()` → Market avec `is_default=true`, sinon premier actif, fallback US in-memory

### Backend — Translations (4 couches)

**TranslationRepository** (`app/Core/Markets/TranslationRepository.php`) :
1. Static JSON anglais (`resources/js/plugins/i18n/locales/en.json`)
2. Static JSON locale demandé
3. DB bundles (`translation_bundles` par locale/namespace)
4. Market overrides (`translation_overrides` filtrées par `market_key`)

**TranslationMatrixService** : Grid editor admin pour translations side-by-side, bulk upsert.

### Backend — Tax Resolution (ADR-310)

**TaxContextResolver** (`app/Modules/Core/Billing/Services/TaxContextResolver.php`) — 5 cas :
1. Même pays (seller=buyer market) → taux standard
2. B2B intra-EU avec VAT valide → 0%, reverse_charge_intra_eu
3. B2C intra-EU ou VAT invalide → taux standard buyer
4. Extra-EU → 0%, export_extra_eu
5. VIES unavailable → fallback reverse charge

### Backend — FX Rates

**FxRateFetchJob** : Stub rates hardcodés (EUR/USD 1.0850, EUR/GBP 0.8580, etc.). Planifié toutes les 6h. Production nécessite une vraie API.

### Backend — Payment Rules (Market-aware)

**PlatformPaymentMethodRule** : Table `platform_payment_method_rules` avec colonnes `method_key`, `provider_key`, `market_key` (nullable=any), `plan_key`, `interval`, `priority`, `is_active`, `constraints`. Matching par spécificité.

### Backend — APIs

**Public** : `GET /api/public/markets`, `GET /api/public/markets/{key}`, `GET /api/public/i18n/{locale}/{namespace?}?market={marketKey}`, `GET /api/public/world`

**Company** : `GET /api/my-companies` — **NE retourne PAS `market_key`** ! `GET /api/company/legal-structure` — retourne `market_name`, `legal_status_key`, `legal_statuses[]` mais PAS `currency`, `locale`, `timezone`.

### Frontend — World Store

**worldStore** (`resources/js/core/stores/world.js`) :
```
state: { _country: 'US', _currency: 'USD', _locale: 'en-US', _timezone: 'America/New_York', _dialCode: '+1', _loaded: false }
methods: fetch() → GET /api/public/world, apply(data), applyMarket(market)
```

**BUG CRITIQUE** : `applyMarket(market)` existe mais **n'est JAMAIS appelé** dans le code. Lors du switch de company, le market du worldStore reste celui du précédent contexte.

### Frontend — Formatting Utilities

**formatMoney** (`resources/js/utils/money.js`) : `Intl.NumberFormat(world.locale, { style: 'currency', currency: world.currency })`. Utilise le worldStore comme fallback — si le worldStore n'est pas à jour, formatage incorrect.

**formatDateTime** (`resources/js/utils/datetime.js`) : `Intl.DateTimeFormat(world.locale, { timeZone: world.timezone })`. Même problème.

### Frontend — Company Switch Flow (INCOMPLET)

```
1. User clique "Changer de company"
2. authStore.switchCompany(newCompanyId)
3. postBroadcast('company-switch', { companyId })
4. runtime.switchCompany() — déconnecte SSE, force reboot
5. Ressources rechargées : auth:me, auth:companies, tenant:jobdomain, features:nav
6. Templates re-render avec nouvelles données
7. BUG: world.currency, world.locale, world.timezone JAMAIS MIS À JOUR
   → Facturation affichée en ancienne devise
   → Dates en ancien timezone
   → Suggestions dial_code en ancien code
```

## 3. Problèmes identifiés

### P0 — CRITIQUE

**P0-1 : applyMarket() jamais appelé**
La méthode existe dans le worldStore mais aucun code ne l'appelle. Lors du switch de company FR→GB, les montants restent en EUR au lieu de passer en GBP. Les dates restent en timezone Paris au lieu de London.

**P0-2 : /my-companies ne retourne pas market_key**
L'API `/api/my-companies` ne retourne pas le market_key dans la réponse. Le frontend ne peut pas déterminer le market de la company active sans un appel API supplémentaire.

### P1 — URGENT

**P1-1 : marketInfo incomplet dans settingsStore**
Le endpoint `/api/company/legal-structure` retourne `market_name` mais pas `currency`, `locale`, `timezone`. Le frontend ne peut pas extraire les paramètres de formatage du market.

**P1-2 : Formatage inconsistant des montants billing**
Certaines pages billing passent `{ currency: preview.currency }` explicitement (correct), d'autres utilisent le worldStore fallback (incorrect si worldStore stale).

**P1-3 : FX rates hardcodés**
Les taux de change sont des stubs. En production multi-market, les conversions seront fausses.

### P2 — AMÉLIORATIONS

**P2-1 : Pas de test multi-market frontend**
Aucun test E2E vérifiant le formatage correct après un switch de company cross-market.

**P2-2 : Pas d'onboarding market selection**
Le tunnel d'inscription ne propose pas de sélection de market visuellement riche (flags, preview devise).

## 4. Risques

### Risques techniques
- **Montants incorrects** : Un utilisateur FR voyant des montants GB formatés en EUR → confusion financière
- **Dates erronées** : Timezone Paris appliqué à des horaires London → 1h de décalage
- **TVA incorrecte** : Le frontend pourrait afficher le mauvais taux si le market n'est pas résolu

### Risques produit
- **Multi-company impossible** : Un utilisateur avec une company FR et une company GB ne peut pas switcher correctement
- **Expansion bloquée** : Ajouter DE, ES, IT markets ne sert à rien si le frontend ne les supporte pas
- **Facturation** : Afficher un montant dans la mauvaise devise est un problème légal (directive européenne sur les prix)

## 5. Gaps architecturels

| Gap | Gravité | Existant | Cible |
|-----|---------|----------|-------|
| applyMarket() appelé au switch | CRITIQUE | Jamais appelé | Appelé dans runtime.switchCompany() |
| market_key dans /my-companies | CRITIQUE | Absent | Inclus dans la réponse |
| Market data dans /me ou /company | ÉLEVÉE | Absent | currency, locale, timezone inclus |
| Tests multi-market frontend | ÉLEVÉE | 0 | Minimum 5 tests |
| FX rates réels | MOYENNE | Stubs | API réelle (ECB ou fixer.io) |
| Onboarding market selection | BASSE | Basique | Flags + preview devise |

## 6. Contrats manquants

### Backend
- `/api/my-companies` doit retourner `market_key` pour chaque company
- `/api/me` (ou `/api/company`) doit retourner `market: { key, currency, locale, timezone, dial_code }` pour la company active
- FxRateFetchJob doit utiliser une vraie API (ECB, fixer.io, etc.)

### Frontend
- `worldStore.applyMarket(market)` doit être appelé dans le flow de boot et de company switch
- `formatMoney()` doit TOUJOURS recevoir `{ currency }` explicitement — le fallback worldStore est dangereux
- Un composant `<MoneyDisplay :amount="cents" :currency="currency" />` qui force le passage de currency
- Un composable `useMarketFormatting()` qui expose les fonctions formatées pour le market de la company active

## 7. UX Impact

- **Montants** : Toujours affichés dans la devise de la company (EUR, GBP) avec le symbole local
- **Dates** : Toujours dans le timezone de la company (Europe/Paris, Europe/London)
- **Nombres** : Séparateur décimal et grouping selon la locale (1 234,56 vs 1,234.56)
- **Téléphones** : Indicatif pays pré-rempli selon le market (+33, +44)
- **Flags** : Drapeau SVG à côté du nom du market dans les sélecteurs

## 8. Proposition V2 — Architecture cible

### Fix P0 : applyMarket au switch

```javascript
// Dans runtime.js → _onCompanySwitch()
async _onCompanySwitch(companyId) {
  // ... existing logic
  const company = authStore.currentCompany
  if (company?.market) {
    useWorldStore().applyMarket(company.market)
  }
}
```

### Enrichir /my-companies

```php
// UserCompaniesReadModel
'market_key' => $membership->company->market_key,
'market' => [
    'currency' => $market->currency,
    'locale' => $market->locale,
    'timezone' => $market->timezone,
    'dial_code' => $market->dial_code,
],
```

### Composable useMarketFormatting

```javascript
export function useMarketFormatting() {
  const world = useWorldStore()

  const formatAmount = (cents, currency = null) =>
    formatMoney(cents, { currency: currency || world.currency, locale: world.locale })

  const formatDate = (iso) =>
    formatDateTime(iso, { locale: world.locale, timeZone: world.timezone })

  return { formatAmount, formatDate, currency: computed(() => world.currency) }
}
```

### Composant MoneyDisplay

```vue
<template>
  <span>{{ formatted }}</span>
</template>
<script setup>
const props = defineProps({ amount: Number, currency: { type: String, required: true } })
const formatted = computed(() => formatMoney(props.amount, { currency: props.currency }))
</script>
```

## 9. Règles non négociables

1. **Le worldStore DOIT être mis à jour à chaque switch de company** — applyMarket() est obligatoire
2. **formatMoney() ne doit JAMAIS être appelé sans currency explicite** dans les contextes billing/invoice
3. **Chaque page affichant des montants DOIT passer la currency de la company**, pas du worldStore
4. **Les tests multi-market sont OBLIGATOIRES** : au minimum FR→GB switch avec vérification formatage
5. **Le backend est la source de vérité** pour le market — le frontend ne résout jamais le market lui-même

## 10. Plan d'implémentation

| Phase | Scope | Effort | Dépendance |
|-------|-------|--------|------------|
| Phase 1 | Enrichir /my-companies + /me avec market data | 0.5j | Aucune |
| Phase 2 | Appeler applyMarket() au switch + boot | 0.5j | Phase 1 |
| Phase 3 | Créer useMarketFormatting + MoneyDisplay | 0.5j | Phase 2 |
| Phase 4 | Auditer toutes les pages billing : forcer currency explicite | 1j | Phase 3 |
| Phase 5 | FX rates réels (ECB API) | 1j | Aucune |
| Phase 6 | Tests multi-market E2E | 1j | Phase 4 |
| **Total** | | **4.5j** | |

## 11. Impacts sur autres modules

- **Billing** : Toutes les pages billing doivent passer `currency` explicitement (plans, invoices, checkout, widgets)
- **Dashboard** : Les widgets KPI financiers doivent utiliser la currency de la company
- **Members** : Le dial_code du market doit pré-remplir les champs téléphone
- **Documents** : Les dates d'expiration doivent être formatées dans le timezone du market
- **Onboarding** : Le tunnel d'inscription doit proposer le market et pré-configurer la devise
- **Platform admin** : Les vues cross-company doivent afficher la devise de chaque company, pas une devise globale

## 12. Dépendances avec autres audits

- **V2-AUDIT-TENANCY** : Le market est une propriété de la company. Le trait BelongsToCompany ne change rien au market resolution
- **V2-AUDIT-RBAC** : Aucune dépendance directe. Le market n'affecte pas les permissions
- **V2-AUDIT-REALTIME** : Aucun topic SSE pour les changements de market. Si un admin change le market d'une company, il faudrait un topic `market.changed`
- **V2-AUDIT-AI-ENGINE** : L'AI n'est pas market-aware. Les prompts sont en français. Si on ajoute des markets non-francophones, les prompts AI devront être localisés

---

> **Verdict** : Le backend multi-market est **complet et production-ready**. Le bug critique est le `applyMarket()` jamais appelé côté frontend — un fix de 0.5 jour qui débloque tout le multi-market. L'effort total est de 4.5 jours pour un frontend entièrement market-aware.
