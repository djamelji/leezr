# Tenancy Audit — LOT 5

Date: 2026-02-11
Scope: All company-scoped controllers, models, middleware, routes

---

## 1. Audit Commands Used

```bash
# 1. Check if company_id is ever read from request payload (VIOLATION)
rg '\$request->input\(.company_id' app/
rg '\$request->get\(.company_id' app/
rg '\$request->company_id' app/
# Result: 0 matches — CLEAN

# 2. Check how company context is sourced
rg "attributes->get\('company'\)" app/
# Result: 10 matches — all controllers use middleware-injected company

# 3. Check all findOrFail usage is scoped by company
rg 'findOrFail' app/ --context=2
# Result: 4 occurrences, ALL preceded by where('company_id') or relation scope

# 4. Check no route model binding bypasses tenant scope
rg 'function (show|update|destroy|changeStatus).*\(.*\\\\(Shipment|Company|Membership|CompanyModule)' app/
# Result: 0 matches — all methods use int $id, never type-hinted models

# 5. Check all where('company_id') queries
rg "where\('company_id'" app/
# Result: 8 occurrences — all correctly use $company->id

# 6. Check validated request rules never include company_id
rg 'company_id' app/Company/Http/Requests/
# Result: 0 matches — CLEAN (company_id never in validation rules)
```

---

## 2. Checklist: company_id NEVER from request payload

| Rule | Status | Evidence |
|------|--------|----------|
| `$request->input('company_id')` never used | PASS | 0 rg matches |
| `$request->get('company_id')` never used | PASS | 0 rg matches |
| `$request->company_id` never used | PASS | 0 rg matches |
| `$request->validated()` never contains company_id | PASS | 0 matches in FormRequest files |
| Company comes from `$request->attributes->get('company')` | PASS | 10 usages, all via middleware |
| Middleware reads from header `X-Company-Id` only | PASS | SetCompanyContext.php:14 |
| Membership verified in middleware | PASS | SetCompanyContext.php:30 |

---

## 3. Company-Scoped Models Verified

| Model | company_id FK | Scoping Method | Verified |
|-------|--------------|----------------|----------|
| Shipment | Yes | `where('company_id', $company->id)` in ShipmentReadModel + ChangeShipmentStatus | PASS |
| CompanyModule | Yes | `where('company_id', $company->id)` in ModuleGate, ModuleCatalogReadModel, CompanyModuleController | PASS |
| Membership | Yes | `$company->memberships()` relation (auto-scoped by FK) | PASS |
| company_jobdomain (pivot) | Yes | `$company->jobdomains()->sync()` relation (auto-scoped) | PASS |

---

## 4. Endpoints Verified (all company-scoped routes)

| Method | Route | Controller | Scoping | Status |
|--------|-------|------------|---------|--------|
| GET | /api/company | CompanyController@show | Middleware (company from attributes) | PASS |
| PUT | /api/company | CompanyController@update | Middleware + role:admin | PASS |
| GET | /api/company/members | MembershipController@index | `$company->memberships()` | PASS |
| POST | /api/company/members | MembershipController@store | `$company->memberships()->create()` | PASS |
| PUT | /api/company/members/{id} | MembershipController@update | `$company->memberships()->findOrFail($id)` | PASS |
| DELETE | /api/company/members/{id} | MembershipController@destroy | `$company->memberships()->findOrFail($id)` | PASS |
| GET | /api/company/jobdomain | CompanyJobdomainController@show | `JobdomainCatalogReadModel::forCompany($company)` | PASS |
| PUT | /api/company/jobdomain | CompanyJobdomainController@update | `JobdomainGate::assignToCompany($company, ...)` | PASS |
| GET | /api/modules | CompanyModuleController@index | `ModuleCatalogReadModel::forCompany($company)` | PASS |
| PUT | /api/modules/{key}/enable | CompanyModuleController@enable | `CompanyModule::updateOrCreate(['company_id' => $company->id, ...])` | PASS |
| PUT | /api/modules/{key}/disable | CompanyModuleController@disable | `CompanyModule::updateOrCreate(['company_id' => $company->id, ...])` | PASS |
| GET | /api/shipments | ShipmentController@index | `ShipmentReadModel::list($company, ...)` → `where('company_id')` | PASS |
| POST | /api/shipments | ShipmentController@store | `CreateShipment::handle($company, ...)` | PASS |
| GET | /api/shipments/{id} | ShipmentController@show | `ShipmentReadModel::find($company, $id)` → `where('company_id')->findOrFail()` | PASS |
| PUT | /api/shipments/{id}/status | ShipmentController@changeStatus | `ChangeShipmentStatus::handle($company, $id, ...)` → `where('company_id')->findOrFail()` | PASS |

---

## 5. Defense Layers

```
Request → auth:sanctum → SetCompanyContext (header + membership) → EnsureRole → EnsureModuleActive → Controller → ReadModel/UseCase (where company_id)
```

1. **auth:sanctum** — token validation
2. **SetCompanyContext** — X-Company-Id header extraction + `isMemberOf()` check
3. **EnsureRole** — role hierarchy (user < admin < owner)
4. **EnsureModuleActive** — module activation check (per company)
5. **ReadModel/UseCase** — explicit `where('company_id', $company->id)` on every query

---

## 6. Verdict

**ALL 15 company-scoped endpoints pass tenancy isolation audit.**
- Zero payload injection vectors
- All queries scoped by company_id (relation or explicit where)
- No route model binding (manual int $id resolution with tenant scope)
- Middleware enforces membership before any controller code executes
