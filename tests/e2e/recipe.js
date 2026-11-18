// ADR-828 — the only way a spec runs PHP fixtures : `php artisan e2e:php <file>` (guarded recipe command).
// Refused (non-zero exit, nothing written) unless the live database is a marked isolated clone.
// Replaces `php artisan tinker --execute`, which no guard covers.
import { execFileSync } from 'node:child_process'
import { mkdtempSync, rmSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'

const REPO = new URL('../..', import.meta.url).pathname

/** Run a full PHP script (starting with <?php) ; returns its output. */
export function recipePhp(php) {
  const dir = mkdtempSync(join(tmpdir(), 'leezr-e2e-'))
  const file = join(dir, 'fixture.php')
  try {
    writeFileSync(file, php)

    return execFileSync('php', ['artisan', 'e2e:php', file], { cwd: REPO, encoding: 'utf8' })
  }
  finally {
    rmSync(dir, { recursive: true, force: true })
  }
}

/** Run PHP statements (former `tinker --execute` code) ; returns the last output line. */
export function recipeEval(code) {
  return recipePhp(`<?php\n${code}\n`).trim().split('\n').pop()
}
