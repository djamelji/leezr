-- ADR-806 — PROD pre-deployment checks. READ-ONLY : SELECT only, no write, no DDL.
-- Run on a read replica or inside `START TRANSACTION READ ONLY; ... ROLLBACK;`.
-- Validated on DEV (MySQL 8, ONLY_FULL_GROUP_BY) — figures in ADR-806 §4.

-- ─── C1. HR documents after the ADR-805 scope restriction ───────────────────────────────────────────────
-- Roles holding workforce_documents.view WITHOUT the full Workforce view (workforce.view + workforce.manage) :
-- since ADR-805 their members see only their own documents and their direct reports' (before : the whole registry).
SELECT r.company_id, r.id AS role_id, r.`key` AS role_key, r.name,
       (SELECT COUNT(*) FROM memberships m WHERE m.company_role_id = r.id) AS members,
       (SELECT COUNT(*) FROM generated_documents g WHERE g.company_id = r.company_id
          AND g.subject_type IN ('employee','contract','leave_request'))   AS person_documents_in_company
FROM company_roles r
WHERE r.`key` <> 'owner'
  AND EXISTS (SELECT 1 FROM company_role_permission rp JOIN company_permissions p ON p.id = rp.company_permission_id
              WHERE rp.company_role_id = r.id AND p.`key` = 'workforce_documents.view')
  AND (NOT EXISTS (SELECT 1 FROM company_role_permission rp JOIN company_permissions p ON p.id = rp.company_permission_id
                   WHERE rp.company_role_id = r.id AND p.`key` = 'workforce.view')
    OR NOT EXISTS (SELECT 1 FROM company_role_permission rp JOIN company_permissions p ON p.id = rp.company_permission_id
                   WHERE rp.company_role_id = r.id AND p.`key` = 'workforce.manage'))
ORDER BY members DESC;

-- Same roles that can GENERATE : since ADR-805 they generate only for people they can see (else 403).
SELECT r.company_id, r.id AS role_id, r.name
FROM company_roles r
JOIN company_role_permission rp ON rp.company_role_id = r.id
JOIN company_permissions p ON p.id = rp.company_permission_id AND p.`key` = 'workforce_documents.generate'
WHERE r.`key` <> 'owner'
  AND NOT EXISTS (SELECT 1 FROM company_role_permission rp2 JOIN company_permissions p2 ON p2.id = rp2.company_permission_id
                  WHERE rp2.company_role_id = r.id AND p2.`key` = 'workforce.manage');

-- ─── C2. Companies with leave data and without workforce_leave active (ADR-804 residual A) ───────────────
SELECT c.id, c.slug,
       (SELECT COUNT(*) FROM workforce_leave_requests lr WHERE lr.company_id = c.id)                          AS requests,
       (SELECT COUNT(*) FROM workforce_leave_requests lr WHERE lr.company_id = c.id AND lr.status = 'pending') AS pending,
       (SELECT COUNT(*) FROM workforce_leave_types t     WHERE t.company_id = c.id)                           AS types,
       (SELECT COUNT(*) FROM workforce_leave_balances b  WHERE b.company_id = c.id)                           AS balances
FROM companies c
WHERE EXISTS     (SELECT 1 FROM company_module_activation_reasons a WHERE a.company_id = c.id AND a.module_key = 'workforce')
  AND NOT EXISTS (SELECT 1 FROM company_module_activation_reasons a WHERE a.company_id = c.id AND a.module_key = 'workforce_leave')
ORDER BY requests DESC;

-- ─── C3. Signatures without a real provider ─────────────────────────────────────────────────────────────
-- Provider configured per company (NULL = platform default). In PROD the dev-only `internal` provider resolves
-- to NO adapter (requests cannot be created) ; an external provider without webhook secret is dev-only too
-- (check the secret in the PROD env, not in SQL).
SELECT a.company_id, COALESCE(s.provider, '(default)') AS provider,
       (SELECT COUNT(*) FROM workforce_signature_requests q WHERE q.company_id = a.company_id
          AND q.status NOT IN ('completed','declined','cancelled','expired','failed')) AS open_requests,
       (SELECT COUNT(*) FROM workforce_signature_requests q WHERE q.company_id = a.company_id
          AND q.provider = 'internal')                                                  AS internal_requests
FROM company_module_activation_reasons a
LEFT JOIN workforce_signature_settings s ON s.company_id = a.company_id
WHERE a.module_key = 'workforce_signature'
ORDER BY open_requests DESC;

-- Open requests per provider, all companies (a request created by `internal` can never be completed in PROD).
SELECT q.provider, q.status, COUNT(*) AS n
FROM workforce_signature_requests q
WHERE q.status NOT IN ('completed','declined','cancelled','expired','failed')
GROUP BY q.provider, q.status;

-- ─── C4. Paid modules and their dependencies (manifest `requires`) ──────────────────────────────────────
-- (a) incoherence : a module active while one of its requirements is not.
SELECT a.company_id, a.module_key, d.req AS missing_requirement
FROM company_module_activation_reasons a
JOIN (SELECT 'workforce_documents' AS m, 'workforce' AS req UNION ALL
      SELECT 'workforce_contract', 'workforce' UNION ALL SELECT 'workforce_contract', 'workforce_documents' UNION ALL
      SELECT 'workforce_signature', 'workforce' UNION ALL SELECT 'workforce_signature', 'workforce_documents' UNION ALL
      SELECT 'workforce_leave', 'workforce' UNION ALL SELECT 'workforce_planning', 'workforce' UNION ALL
      SELECT 'workforce_payroll', 'workforce' UNION ALL SELECT 'workforce_payroll', 'workforce_leave' UNION ALL
      SELECT 'logistics_tracking', 'logistics_shipments' UNION ALL SELECT 'logistics_fleet', 'logistics_shipments' UNION ALL
      SELECT 'logistics_analytics', 'logistics_shipments') d ON d.m = a.module_key
WHERE NOT EXISTS (SELECT 1 FROM company_module_activation_reasons b WHERE b.company_id = a.company_id AND b.module_key = d.req);

-- (b) paid modules active ONLY as a requirement of another module (reason = 'required') : the company pays
--     for a module it did not pick directly — to review commercially, never to change silently.
SELECT a.company_id, a.module_key, a.source_module_key, pm.addon_pricing
FROM company_module_activation_reasons a
JOIN platform_modules pm ON pm.`key` = a.module_key AND pm.addon_pricing IS NOT NULL
WHERE a.reason = 'required'
  AND NOT EXISTS (SELECT 1 FROM company_module_activation_reasons b WHERE b.company_id = a.company_id
                  AND b.module_key = a.module_key AND b.reason = 'direct');

-- (c) « preview » modules (empty : tracking, fleet, analytics) active anywhere — not purchasable since ADR-732.
SELECT a.company_id, a.module_key, a.reason FROM company_module_activation_reasons a
WHERE a.module_key IN ('logistics_tracking','logistics_fleet','logistics_analytics');

-- ─── C5. Platform sessions migration (ADR-801.1, 2026_12_02_000001_sessions_platform_user_id) ───────────
SELECT migration, batch FROM migrations WHERE migration = '2026_12_02_000001_sessions_platform_user_id';
SELECT COLUMN_NAME, IS_NULLABLE FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sessions' AND COLUMN_NAME IN ('user_id','platform_user_id');
-- Distribution after the migration (a Platform login must never fill user_id) :
SELECT COUNT(*) AS sessions_total,
       SUM(user_id IS NOT NULL) AS company_bound,
       SUM(platform_user_id IS NOT NULL) AS platform_bound
FROM sessions;
