<?php

/**
 * RETIRED (ADR-833, 2026-11-19) — historical GitHub webhook endpoint.
 *
 * It used to start deploy.sh (git clone + migrate + SystemSeeder + DevSeeder on dev) in parallel with GitHub
 * Actions. Deployments now run ONLY through .github/workflows/deploy.yml → deploy/deploy_release.sh. This endpoint
 * executes nothing and answers 410 so that GitHub shows the hook as obsolete until it is removed from the
 * repository settings.
 */
http_response_code(410);
header('Content-Type: application/json');
echo json_encode(['status' => 'retired', 'adr' => 'ADR-833', 'deploy' => 'GitHub Actions only']);
