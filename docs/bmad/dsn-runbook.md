# DSN Production Runbook

> **Audience** : Support technique / Ops — Sprint 6.9, ADR-527
> **Dernière mise à jour** : 2026-05-06

## Table des matières

1. [Lifecycle DSN](#1-lifecycle-dsn)
2. [Générer une déclaration](#2-générer-une-déclaration)
3. [Valider une déclaration](#3-valider-une-déclaration)
4. [Inspecter une déclaration](#4-inspecter-une-déclaration)
5. [Régénérer une déclaration](#5-régénérer-une-déclaration)
6. [Soumettre une déclaration](#6-soumettre-une-déclaration)
7. [Traiter un rejet](#7-traiter-un-rejet)
8. [Diagnostics courants](#8-diagnostics-courants)
9. [Configuration](#9-configuration)

---

## 1. Lifecycle DSN

```
draft → validated → exported → submitted → accepted
                                        ↘ rejected → (regenerate) → validated → ...
```

| Statut | Description | Modifiable | Régénérable |
|--------|-------------|------------|-------------|
| `draft` | Créée avec erreurs de validation | Oui | Oui |
| `validated` | Validée, prête pour export | Oui | Oui |
| `exported` | Fichier DSN généré sur disque | Non (payload figé) | Oui |
| `submitted` | Envoyée au gateway (Net-Entreprises) | Non | Non |
| `accepted` | Acceptée par le destinataire | Non | Non |
| `rejected` | Rejetée — nécessite correction | Non | Oui |

**Invariants critiques** :
- Aucune soumission sans preflight OK (9 checks)
- Aucune double soumission (lock anti-concurrence, TTL 5 min)
- Payload et hash immuables après export
- PayrollRun/PayrollCalculation jamais modifiés par le pipeline DSN

---

## 2. Générer une déclaration

### Via code (UseCase)

```php
$useCase = new ExportPayrollDsnUseCase();
$declaration = $useCase->execute($payrollRun, $actorId);
```

### Prérequis

- PayrollRun en status `validated` ou `exported`
- Toutes les PayrollCalculation en status `validated`
- Company avec SIRET valide (Luhn 14 chiffres) et NAF code
- Employees avec NIR valide (modulo 97), adresse, genre, date naissance

### Ce qui se passe

1. Résolution données company + employees (batch, accès EAV brut — pas masqué)
2. Construction des blocs DSN (S21.G00.06, .22, .30, .40, .50, .51, .78, .81)
3. Validation NEODES (SIRET, NIR, CTP, totals, encoding ISO-8859-1)
4. Sérialisation fichier texte DSN
5. Hash SHA-256 du fichier → `payload_hash`
6. Persistance fichier sur disque (`workforce/dsn/{company_id}/`)
7. Création `DsnDeclaration` avec `payload_snapshot` JSON
8. Si erreurs validation bloquantes → status `draft`, exception
9. Si OK → status `validated`
10. Audit log : `dsn_declaration.generated`

### Idempotence

- Si déclaration existe déjà pour ce PayrollRun :
  - Status `exported` → retourne la déclaration existante (noop)
  - Status `submitted`/`accepted` → exception (impossible de régénérer)
  - Status `draft`/`validated`/`rejected` → supprime l'ancienne, crée une nouvelle

---

## 3. Valider une déclaration

### CLI

```bash
php artisan dsn:validate {declaration_id}
```

### Output

```
📋 DSN Validation Summary — Declaration #42
────────────────────────────────────────────
Status: validated
Errors: 0 | Warnings: 2

⚠️  WARNINGS:
  [encoding] S21.G00.30.002 — Non-ISO-8859-1 character in field 'name'

Exit code: 0 (clean) or 1 (errors)
```

### 5 catégories de validation

| Catégorie | Sévérité | Détail |
|-----------|----------|--------|
| SIRET | Error | Luhn checksum 14 digits |
| NIR | Error | Modulo 97 (Corse: 2A→19, 2B→18) |
| CTP Mapping | Error | Chaque code cotisation a un mapping URSSAF |
| Totals | Error/Warning | Sommes individuelles = agrégats (tolérance 1 cent/employé) |
| Encoding | Warning | Compatibilité ISO-8859-1 |

---

## 4. Inspecter une déclaration

### CLI

```bash
# Inspection de base
php artisan dsn:inspect {declaration_id}

# Avec audit trail
php artisan dsn:inspect {declaration_id} --audit

# Avec payload snapshot
php artisan dsn:inspect {declaration_id} --payload
```

### Output

Affiche : identifiants, company, période, statut, timestamps lifecycle, validation summary, intégrité (hash, fichier, régénérable), informations PayrollRun.

Avec `--audit` : timeline complète des actions (generated, submitted, accepted/rejected) avec timestamps et acteurs.

Avec `--payload` : dump JSON du payload snapshot complet.

---

## 5. Régénérer une déclaration

### CLI

```bash
# Preview (dry-run)
php artisan dsn:regenerate {declaration_id} --dry-run

# Exécution réelle
php artisan dsn:regenerate {declaration_id}
```

### Conditions

| Status actuel | Régénérable ? | Comportement |
|---------------|---------------|--------------|
| `draft` | ✅ Oui | Supprime ancienne, recrée |
| `validated` | ✅ Oui | Supprime ancienne, recrée |
| `exported` | ✅ Oui | Supprime ancienne, recrée |
| `rejected` | ✅ Oui | Supprime ancienne, recrée |
| `submitted` | ❌ Non | Exception — en attente de réponse |
| `accepted` | ❌ Non | Exception — déclaration finale |

### Attention

- La régénération **supprime** l'ancienne déclaration et en crée une nouvelle (nouvel ID)
- L'audit trail de l'ancienne déclaration reste dans `company_audit_logs`
- Le fichier DSN est re-généré à partir des données PayrollRun actuelles

---

## 6. Soumettre une déclaration

### Via code (UseCase)

```php
$submitUC = new SubmitDsnDeclarationUseCase($gateway);
$declaration = $submitUC->execute($declaration, $actorId);
```

### Séquence de soumission (6 guards + retry)

1. **Idempotence** — si `submitted`/`accepted`, retourne directement
2. **Status guard** — doit être `exported`
3. **File guard** — `file_path` non vide
4. **Preflight** — 9 checks (SIRET, NIR, CTP, hash, fichier, erreurs...)
5. **Hash integrity** — relecture fichier DSN, recompute SHA-256 vs stored hash
6. **Submission lock** — acquiert le verrou anti-double-submit
7. **Gateway resolution** — `submit_enabled=false` → NullDsnGateway (dry-run)
8. **Retry loop** — max 3 tentatives, backoff exponentiel (1s, 2s, 4s)
9. **Audit** — log par tentative (attempt_number, duration_ms, result, error)
10. **Lock release** — toujours dans `finally` (même sur exception)

### Types d'erreurs

| Type | Retryable | Exemples |
|------|-----------|----------|
| `RuntimeException` | ✅ Oui | Timeout réseau, connection refused |
| `DomainException` | ❌ Non | Preflight KO, hash tampered, business rejection |
| Business error | ❌ Non | rawResponse.status ∈ [rejected, invalid, refused] ou error_code BIZ_* |

---

## 7. Traiter un rejet

### Étapes

1. **Inspecter** la déclaration rejetée :
   ```bash
   php artisan dsn:inspect {id} --audit
   ```

2. **Identifier la cause** dans l'audit trail (metadata `reason`)

3. **Corriger les données source** (PayrollCalculation, données employé, etc.)

4. **Régénérer** la déclaration :
   ```bash
   php artisan dsn:regenerate {id}
   ```

5. **Valider** la nouvelle déclaration :
   ```bash
   php artisan dsn:validate {new_id}
   ```

6. **Transition** vers exported puis re-soumettre

### Flow complet rejected → correction

```
rejected → (correction données) → dsn:regenerate → validated → exported → submitted → accepted
```

---

## 8. Diagnostics courants

### Preflight failure

**Symptôme** : `dsn_preflight_failed: ...`

| Message | Cause | Action |
|---------|-------|--------|
| `Status is not 'exported'` | Mauvais workflow | Transitionner vers exported avant submit |
| `DSN file not found` | Fichier supprimé | Régénérer la déclaration |
| `No payload hash` | Export incomplet | Régénérer |
| `blocking validation errors` | Données invalides | `dsn:validate` pour détails |
| `No payload snapshot` | Export ancien | Régénérer |
| `Invalid SIRET (Luhn)` | SIRET incorrect | Corriger dans Company |
| `Invalid NIR for employee` | NIR incorrect | Corriger dans Fields |
| `No employees in snapshot` | Aucun employé résolu | Vérifier PayrollLines + FieldValues |
| `Missing CTP for code` | Code cotisation sans mapping | Vérifier DsnCtpMapping |

### Hash mismatch (tampered)

**Symptôme** : `dsn_payload_tampered: payload hash does not match stored file.`

**Cause** : Le fichier DSN sur disque a été modifié après l'export.

**Diagnostic** :
```bash
# Vérifier le fichier
php artisan dsn:inspect {id}
# Comparer hash stocké vs hash fichier
```

**Action** : Régénérer la déclaration (`dsn:regenerate {id}`). Le fichier sera recréé avec un nouveau hash.

### Locked (double submit)

**Symptôme** : `DSN submission already in progress for this declaration (locked).`

**Cause** : Une soumission est déjà en cours ou le lock n'a pas expiré.

**Diagnostic** :
```sql
SELECT * FROM workforce_dsn_submission_locks WHERE declaration_id = ?;
```

**Action** : Attendre expiration du lock (5 min) ou vérifier qu'aucun process ne tourne. Le lock est automatiquement nettoyé au prochain `acquire()`.

### Gateway failure après retries

**Symptôme** : `DSN submission failed after 3 attempts: ...`

**Cause** : 3 tentatives échouées (timeout réseau, serveur indisponible).

**Action** : Vérifier la connectivité réseau, l'état du serveur Net-Entreprises, puis retenter manuellement.

---

## 9. Configuration

### Variables d'environnement

| Variable | Défaut | Description |
|----------|--------|-------------|
| `DSN_GATEWAY_DRIVER` | `null` | Driver gateway : `null`, `file`, (futur: `net-entreprises`) |
| `DSN_SUBMIT_ENABLED` | `false` | Kill-switch global — `false` = toujours NullDsnGateway |
| `DSN_MAX_ATTEMPTS` | `3` | Nombre max de tentatives de soumission |
| `DSN_BACKOFF_BASE_SECONDS` | `1` | Base du backoff exponentiel (1s, 2s, 4s) |
| `DSN_FILE_DISK` | `local` | Disque Storage pour les fichiers DSN |
| `DSN_FILE_TARGET_DIR` | `workforce/dsn/transmitted` | Répertoire cible pour FileDsnGateway |

### Activation production

Pour activer la soumission réelle vers Net-Entreprises :

```env
DSN_GATEWAY_DRIVER=net-entreprises
DSN_SUBMIT_ENABLED=true
```

**⚠️ ATTENTION** : Ne JAMAIS activer `DSN_SUBMIT_ENABLED=true` sans avoir :
1. Configuré et testé le gateway Net-Entreprises
2. Validé les credentials d'accès
3. Vérifié une déclaration de test en bac à sable
4. Obtenu le feu vert métier

### Dry-run (défaut)

En mode dry-run (`DSN_SUBMIT_ENABLED=false`), toute soumission utilise `NullDsnGateway` qui :
- Retourne toujours succès
- Génère une référence `NULL-{company}-{period}-{timestamp}`
- Ne transmet rien vers l'extérieur
- Permet de valider tout le pipeline sans risque
