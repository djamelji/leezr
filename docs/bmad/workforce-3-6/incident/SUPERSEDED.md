# Superseded — do not execute `restore-dev-company1-april2026.sql`

Kept unchanged as incident evidence (ADR-826). Its rows are proven byte-identical to the DEV binary-log
before-images (ADR-827), but the script itself is unsafe : positional INSERTs, no precondition, deletes
sheet 143 without backup nor fingerprint check, no rollback.

Use instead : `docs/bmad/leezr-3-7/incident/restore-company1-april2026.php` (simulation by default,
preconditions, backup, hash verification, rollback) — and only after explicit authorisation (D-13).
