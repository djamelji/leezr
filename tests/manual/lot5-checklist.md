# LOT 5 — Manual Test Checklist

## Prerequisites

```bash
php artisan migrate:fresh --seed
# Ensure dev servers running: pnpm dev:all
```

Seed users:
- `platform@leezr.test` / password — Platform admin, NO company
- `owner@leezr.test` / password — Company owner (Leezr Logistics)
- `admin@leezr.test` / password — Company admin
- `bob@leezr.test` / password — Simple user

---

## 1. Rate Limiting

### 1.1 Login rate limit — invalid credentials (5 requests/minute)

```bash
# Get CSRF cookie
curl -sk -c /tmp/lot5-cookies.txt \
  -H "Origin: https://leezr.test" \
  https://leezr.test/sanctum/csrf-cookie

XSRF=$(grep XSRF-TOKEN /tmp/lot5-cookies.txt | awk '{print $NF}' | python3 -c "import sys,urllib.parse; print(urllib.parse.unquote(sys.stdin.read().strip()))")

# Send 6 rapid login attempts with WRONG credentials (6th should be throttled)
for i in {1..6}; do
  echo "--- Attempt $i ---"
  curl -sk -X POST https://leezr.test/api/login \
    -H "Origin: https://leezr.test" \
    -H "Accept: application/json" \
    -H "Content-Type: application/json" \
    -H "X-XSRF-TOKEN: $XSRF" \
    -b /tmp/lot5-cookies.txt \
    -d '{"email":"wrong@test.com","password":"wrong"}' \
    -w "\nHTTP %{http_code}\n"
done
```

**Expected**: Attempts 1-5 return 401, attempt 6 returns **429 Too Many Requests**.

### 1.2 Login rate limit — VALID payload proves throttle (not auth rejection)

This test proves that throttle fires on the 6th call regardless of payload validity.
The key insight: 401 = bad credentials, 429 = throttle. We need to see 429 after valid logins.

```bash
# Fresh CSRF cookie (wait 60s or use fresh IP/session if needed)
curl -sk -c /tmp/lot5-valid.txt \
  -H "Origin: https://leezr.test" \
  https://leezr.test/sanctum/csrf-cookie

XSRF=$(grep XSRF-TOKEN /tmp/lot5-valid.txt | awk '{print $NF}' | python3 -c "import sys,urllib.parse; print(urllib.parse.unquote(sys.stdin.read().strip()))")

# Send 6 rapid login attempts with VALID credentials
# Attempts 1-5 should return 200 (success), attempt 6 should return 429
for i in {1..6}; do
  echo "--- Attempt $i ---"
  curl -sk -X POST https://leezr.test/api/login \
    -H "Origin: https://leezr.test" \
    -H "Accept: application/json" \
    -H "Content-Type: application/json" \
    -H "X-XSRF-TOKEN: $XSRF" \
    -b /tmp/lot5-valid.txt \
    -c /tmp/lot5-valid.txt \
    -d '{"email":"owner@leezr.test","password":"password"}' \
    -w "\nHTTP %{http_code}\n"
  # Re-read XSRF after each call (cookie may rotate)
  XSRF=$(grep XSRF-TOKEN /tmp/lot5-valid.txt | awk '{print $NF}' | python3 -c "import sys,urllib.parse; print(urllib.parse.unquote(sys.stdin.read().strip()))")
done
```

**Expected**: Attempts 1-5 return **200** (login succeeds), attempt 6 returns **429 Too Many Requests**.
This proves the throttle middleware fires before authentication logic, regardless of credential validity.

> **Important**: Do NOT confuse 401 (invalid credentials) with 429 (rate limit).
> If you only get 401s, the throttle is not being tested — use valid credentials.

---

## 2. CORS Hardening

### 2.1 Verify CORS headers restrict origins

```bash
# Request from allowed origin
curl -sk -I -X OPTIONS https://leezr.test/api/me \
  -H "Origin: https://leezr.test" \
  -H "Access-Control-Request-Method: GET" \
  | grep -i access-control

# Request from unauthorized origin
curl -sk -I -X OPTIONS https://leezr.test/api/me \
  -H "Origin: https://evil.com" \
  -H "Access-Control-Request-Method: GET" \
  | grep -i access-control
```

**Expected**: First request returns `Access-Control-Allow-Origin: https://leezr.test`. Second request does NOT return `Access-Control-Allow-Origin`.

---

## 3. Tenancy Isolation

### 3.1 Cross-company access blocked

```bash
# Login as owner
curl -sk -c /tmp/lot5-cookies.txt \
  -H "Origin: https://leezr.test" \
  https://leezr.test/sanctum/csrf-cookie

XSRF=$(grep XSRF-TOKEN /tmp/lot5-cookies.txt | awk '{print $NF}' | python3 -c "import sys,urllib.parse; print(urllib.parse.unquote(sys.stdin.read().strip()))")

curl -sk -X POST https://leezr.test/api/login \
  -H "Origin: https://leezr.test" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "X-XSRF-TOKEN: $XSRF" \
  -b /tmp/lot5-cookies.txt \
  -c /tmp/lot5-cookies.txt \
  -d '{"email":"owner@leezr.test","password":"password"}'

# Re-read XSRF after login
XSRF=$(grep XSRF-TOKEN /tmp/lot5-cookies.txt | awk '{print $NF}' | python3 -c "import sys,urllib.parse; print(urllib.parse.unquote(sys.stdin.read().strip()))")

# Try accessing company ID 999 (non-existent)
curl -sk https://leezr.test/api/company \
  -H "Origin: https://leezr.test" \
  -H "Accept: application/json" \
  -H "X-XSRF-TOKEN: $XSRF" \
  -H "X-Company-Id: 999" \
  -b /tmp/lot5-cookies.txt
```

**Expected**: 404 "Company not found."

### 3.2 Cross-company resource 404 (no data leak)

Proves that a **real** resource ID belonging to Company A returns 404 (not 403) when accessed from Company B.
This guarantees no information leakage (attacker cannot distinguish "exists in another company" from "does not exist").

**Prerequisite**: Two companies in DB. Seed creates Company 1 (Leezr Logistics).
Create a second company manually or via register (which auto-creates one).

```bash
# Step 1: Register a second user (creates Company 2)
curl -sk -c /tmp/lot5-c2.txt \
  -H "Origin: https://leezr.test" \
  https://leezr.test/sanctum/csrf-cookie

XSRF2=$(grep XSRF-TOKEN /tmp/lot5-c2.txt | awk '{print $NF}' | python3 -c "import sys,urllib.parse; print(urllib.parse.unquote(sys.stdin.read().strip()))")

curl -sk -X POST https://leezr.test/api/register \
  -H "Origin: https://leezr.test" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "X-XSRF-TOKEN: $XSRF2" \
  -b /tmp/lot5-c2.txt \
  -c /tmp/lot5-c2.txt \
  -d '{"name":"Tenant2","email":"tenant2@test.com","password":"password","password_confirmation":"password","company_name":"Other Corp"}'

XSRF2=$(grep XSRF-TOKEN /tmp/lot5-c2.txt | awk '{print $NF}' | python3 -c "import sys,urllib.parse; print(urllib.parse.unquote(sys.stdin.read().strip()))")

# Step 2: Get Company 2's ID
COMPANY2_ID=$(curl -sk https://leezr.test/api/my-companies \
  -H "Origin: https://leezr.test" \
  -H "Accept: application/json" \
  -H "X-XSRF-TOKEN: $XSRF2" \
  -b /tmp/lot5-c2.txt | python3 -c "import sys,json; print(json.load(sys.stdin)['companies'][0]['id'])")

echo "Company 2 ID: $COMPANY2_ID"

# Step 3: As tenant2, try to access a SHIPMENT that belongs to Company 1
# Shipment ID 1 exists in Company 1 (from seeder)
# First, tenant2 must try with Company 1's ID in header — blocked by membership check (403)
curl -sk https://leezr.test/api/shipments/1 \
  -H "Origin: https://leezr.test" \
  -H "Accept: application/json" \
  -H "X-XSRF-TOKEN: $XSRF2" \
  -H "X-Company-Id: 1" \
  -b /tmp/lot5-c2.txt \
  -w "\nHTTP %{http_code}\n"
# Expected: 403 "You are not a member of this company."

# Step 4: As tenant2, try with OWN company ID but Company 1's shipment ID
# This is the critical test — scoped query must return 404
curl -sk https://leezr.test/api/shipments/1 \
  -H "Origin: https://leezr.test" \
  -H "Accept: application/json" \
  -H "X-XSRF-TOKEN: $XSRF2" \
  -H "X-Company-Id: $COMPANY2_ID" \
  -b /tmp/lot5-c2.txt \
  -w "\nHTTP %{http_code}\n"
# Expected: 404 (shipment 1 belongs to company 1, query scoped to company 2 finds nothing)
# NOT 403 — that would leak the existence of the resource

# Step 5: Same test with membership endpoint
# Membership ID 1 belongs to Company 1
curl -sk -X PUT https://leezr.test/api/company/members/1 \
  -H "Origin: https://leezr.test" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "X-XSRF-TOKEN: $XSRF2" \
  -H "X-Company-Id: $COMPANY2_ID" \
  -b /tmp/lot5-c2.txt \
  -d '{"role":"admin"}' \
  -w "\nHTTP %{http_code}\n"
# Expected: 404 (membership 1 belongs to company 1, relation scoped to company 2)
```

**Verification matrix**:

| Scenario | Expected | Why |
|----------|----------|-----|
| Access company 1 resources with company 1 header (not member) | 403 | Middleware blocks non-members |
| Access company 1's shipment ID with company 2 header | **404** | `where('company_id', company2)->findOrFail(1)` → not found |
| Access company 1's membership ID with company 2 header | **404** | `$company2->memberships()->findOrFail(1)` → not found |

**Code guarantee** (all show/update use scoped queries):
- `ShipmentReadModel::find()` → `Shipment::where('company_id', $company->id)->findOrFail($id)` ✓
- `ChangeShipmentStatus::handle()` → `Shipment::where('company_id', $company->id)->findOrFail($id)` ✓
- `MembershipController@update` → `$company->memberships()->findOrFail($id)` ✓
- `MembershipController@destroy` → `$company->memberships()->findOrFail($id)` ✓

### 3.3 Missing X-Company-Id header

```bash
curl -sk https://leezr.test/api/company \
  -H "Origin: https://leezr.test" \
  -H "Accept: application/json" \
  -H "X-XSRF-TOKEN: $XSRF" \
  -b /tmp/lot5-cookies.txt \
  -w "\nHTTP %{http_code}\n"
```

**Expected**: 400 "X-Company-Id header is required."

---

## 4. Module Guard (Backend)

### 4.1 Module middleware blocks inactive module

```bash
# Disable logistics_shipments module first, then try accessing shipments
curl -sk -X PUT https://leezr.test/api/modules/logistics_shipments/disable \
  -H "Origin: https://leezr.test" \
  -H "Accept: application/json" \
  -H "X-XSRF-TOKEN: $XSRF" \
  -H "X-Company-Id: 1" \
  -b /tmp/lot5-cookies.txt \
  -c /tmp/lot5-cookies.txt

XSRF=$(grep XSRF-TOKEN /tmp/lot5-cookies.txt | awk '{print $NF}' | python3 -c "import sys,urllib.parse; print(urllib.parse.unquote(sys.stdin.read().strip()))")

curl -sk https://leezr.test/api/shipments \
  -H "Origin: https://leezr.test" \
  -H "Accept: application/json" \
  -H "X-XSRF-TOKEN: $XSRF" \
  -H "X-Company-Id: 1" \
  -b /tmp/lot5-cookies.txt
```

**Expected**: 403 "Module is not active for this company."

### 4.2 Re-enable module

```bash
curl -sk -X PUT https://leezr.test/api/modules/logistics_shipments/enable \
  -H "Origin: https://leezr.test" \
  -H "Accept: application/json" \
  -H "X-XSRF-TOKEN: $XSRF" \
  -H "X-Company-Id: 1" \
  -b /tmp/lot5-cookies.txt \
  -c /tmp/lot5-cookies.txt

XSRF=$(grep XSRF-TOKEN /tmp/lot5-cookies.txt | awk '{print $NF}' | python3 -c "import sys,urllib.parse; print(urllib.parse.unquote(sys.stdin.read().strip()))")

curl -sk https://leezr.test/api/shipments \
  -H "Origin: https://leezr.test" \
  -H "Accept: application/json" \
  -H "X-XSRF-TOKEN: $XSRF" \
  -H "X-Company-Id: 1" \
  -b /tmp/lot5-cookies.txt
```

**Expected**: 200 with shipments list.

---

## 5. Role Enforcement

### 5.1 Simple user cannot create shipment

```bash
# Login as bob (simple user)
curl -sk -c /tmp/lot5-bob.txt \
  -H "Origin: https://leezr.test" \
  https://leezr.test/sanctum/csrf-cookie

XSRF_BOB=$(grep XSRF-TOKEN /tmp/lot5-bob.txt | awk '{print $NF}' | python3 -c "import sys,urllib.parse; print(urllib.parse.unquote(sys.stdin.read().strip()))")

curl -sk -X POST https://leezr.test/api/login \
  -H "Origin: https://leezr.test" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "X-XSRF-TOKEN: $XSRF_BOB" \
  -b /tmp/lot5-bob.txt \
  -c /tmp/lot5-bob.txt \
  -d '{"email":"bob@leezr.test","password":"password"}'

XSRF_BOB=$(grep XSRF-TOKEN /tmp/lot5-bob.txt | awk '{print $NF}' | python3 -c "import sys,urllib.parse; print(urllib.parse.unquote(sys.stdin.read().strip()))")

curl -sk -X POST https://leezr.test/api/shipments \
  -H "Origin: https://leezr.test" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "X-XSRF-TOKEN: $XSRF_BOB" \
  -H "X-Company-Id: 1" \
  -b /tmp/lot5-bob.txt \
  -d '{"origin_address":"Test"}'
```

**Expected**: 403 "Insufficient role. Required: admin"

---

## 6. Structured Logging

### 6.1 Check laravel.log after operations

After running the module enable/disable tests above:

```bash
grep "module.enabled\|module.disabled\|shipment.status.changed\|jobdomain.changed" storage/logs/laravel.log
```

**Expected**: Structured log entries with `module_key`, `company_id`, `user_id` context.

---

## 7. Frontend Checks (Browser)

### 7.1 Module guard on direct URL access
1. Login as owner
2. Disable `logistics_shipments` module via `/company/modules`
3. Navigate directly to `/company/shipments` in the URL bar
4. **Expected**: Redirect to `/`, warning toast "Module not available for your company."

### 7.2 Company switch re-fetches modules
1. Login as a user with 2 companies (requires manual setup)
2. Switch company via company switcher
3. **Expected**: Navigation updates to reflect new company's active modules

### 7.3 Page refresh on module page
1. Login as owner
2. Navigate to `/company/shipments`
3. Hard refresh (Ctrl+F5)
4. **Expected**: Page loads correctly (guard awaits module fetch)

### 7.4 API error handling
1. Open browser dev tools, Network tab
2. Navigate the app normally
3. **Expected**: No unhandled 401/403/500 errors in console

---

## Summary Checklist

| # | Test | Status |
|---|------|--------|
| 1.1 | Rate limiting on login (invalid creds) | [ ] |
| 1.2 | Rate limiting on login (VALID payload → 200 then 429) | [ ] |
| 2.1 | CORS restricts origins | [ ] |
| 3.1 | Cross-company access blocked (non-existent company) | [ ] |
| 3.2 | Cross-company resource 404 (real ID, no leak) | [ ] |
| 3.3 | Missing X-Company-Id rejected | [ ] |
| 4.1 | Module middleware blocks inactive | [ ] |
| 4.2 | Module re-enable works | [ ] |
| 5.1 | Role enforcement on create | [ ] |
| 6.1 | Structured logging present | [ ] |
| 7.1 | Frontend module guard | [ ] |
| 7.2 | Company switch re-fetch | [ ] |
| 7.3 | Page refresh on module page | [ ] |
| 7.4 | API error handling | [ ] |
