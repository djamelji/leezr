#!/usr/bin/env python3
"""ADR-833 — row-level diff between two LOCAL clones of the same schema (read-only).

  python3 -I table-diff.py <mysql-option-file> <expected_db> <actual_db> <out_dir>

expected = DEV backup before the deploy, migrated + system:sync (what the CI pipeline alone produces);
actual   = DEV dump after the incident. For every table whose CHECKSUM differs, rows are compared by primary key and
written as TSV: <table>.added / .removed / .changed (changed = key + column + before + after). Columns that hold
secrets (password, remember_token, token, secret, api_key…) are compared through sha256 only, never written.
"""
import os, subprocess, sys, hashlib, json

CNF, EXP, ACT, OUT = sys.argv[1:5]
SECRET_COLS = ('password', 'remember_token', 'token', 'secret', 'api_key', 'two_factor', 'otp', 'webhook_secret')
os.makedirs(OUT, exist_ok=True)


def q(sql, db=None):
    cmd = ['mysql', f'--defaults-extra-file={CNF}', '-N', '-B', '--raw']
    if db:
        cmd.append(db)
    r = subprocess.run(cmd + ['-e', sql], capture_output=True, text=True, check=True)
    return [line.split('\t') for line in r.stdout.splitlines()]


def tables(db):
    return {t[0] for t in q(f"SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA='{db}' AND TABLE_TYPE='BASE TABLE'")}


def checksum(db, t):
    return q(f"CHECKSUM TABLE `{db}`.`{t}`")[0][1]


def is_secret(col):
    c = col.lower()
    return any(s in c for s in SECRET_COLS)


def same_json(x, y):
    # MariaDB keeps JSON as written (longtext), MySQL normalises it: compare semantically
    try:
        return json.loads(x) == json.loads(y)
    except (ValueError, TypeError):
        return False


summary = {}
common = sorted((tables(EXP) & tables(ACT)) - {'_recipe_clone_identity'})
only = {'only_expected': sorted(tables(EXP) - tables(ACT)), 'only_actual': sorted(tables(ACT) - tables(EXP))}
for t in common:
    if checksum(EXP, t) == checksum(ACT, t):
        continue
    colq = "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='{}' AND TABLE_NAME='{}' ORDER BY ORDINAL_POSITION"
    act_cols = {c[0] for c in q(colq.format(ACT, t))}
    cols = [c[0] for c in q(colq.format(EXP, t)) if c[0] in act_cols]
    if len(cols) != len(act_cols):
        summary.setdefault('_column_mismatch', []).append(t)
    pk = [c[0] for c in q(f"SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA='{EXP}' AND TABLE_NAME='{t}' AND CONSTRAINT_NAME='PRIMARY' ORDER BY ORDINAL_POSITION")] or cols
    # one JSON array per row: values containing tabs/newlines stay on one line
    sel = 'JSON_ARRAY(' + ', '.join(f"SHA2(COALESCE(CAST(`{c}` AS CHAR),'∅'),256)" if is_secret(c) else f"COALESCE(CAST(`{c}` AS CHAR),'∅')" for c in cols) + ')'

    def rows(db):
        out = {}
        for r in q(f"SELECT {sel} FROM `{t}`", db):
            rec = dict(zip(cols, json.loads(r[0])))
            out[tuple(rec[k] for k in pk)] = rec
        return out

    a, b = rows(EXP), rows(ACT)
    added = [k for k in b if k not in a]
    removed = [k for k in a if k not in b]
    changed = []
    for k in a.keys() & b.keys():
        for c in cols:
            if a[k][c] != b[k][c] and not same_json(a[k][c], b[k][c]):
                changed.append((k, c, a[k][c], b[k][c]))
    with open(f"{OUT}/{t}.diff.tsv", 'w') as f:
        for k in added:
            f.write('ADDED\t' + '|'.join(k) + '\t' + json.dumps(b[k], ensure_ascii=False)[:2000] + '\n')
        for k in removed:
            f.write('REMOVED\t' + '|'.join(k) + '\t' + json.dumps(a[k], ensure_ascii=False)[:2000] + '\n')
        for k, c, x, y in sorted(changed):
            f.write(f"CHANGED\t{'|'.join(k)}\t{c}\t{x[:500]}\t{y[:500]}\n")
    summary[t] = {'added': len(added), 'removed': len(removed), 'changed_cells': len(changed),
                  'changed_columns': sorted({c for _, c, _, _ in changed})}

with open(f"{OUT}/summary.json", 'w') as f:
    json.dump({'expected': EXP, 'actual': ACT, **only, 'tables': summary}, f, indent=1, ensure_ascii=False)
for t, s in summary.items():
    print(f"{t}\t+{s['added']}\t-{s['removed']}\t~{s['changed_cells']}\t{','.join(s['changed_columns'])[:150]}")
