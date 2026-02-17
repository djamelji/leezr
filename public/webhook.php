<?php

/**
 * GitHub Webhook Endpoint — Leezr Atomic Deployment
 *
 * Receives push events, validates HMAC-SHA256 signature,
 * dispatches deploy.sh in background for the target branch.
 *
 * GitHub webhook config:
 *   - Payload URL: https://leezr.com/webhook.php
 *   - Content type: application/json
 *   - Secret: must match GITHUB_WEBHOOK_SECRET in shared/.env
 *   - Events: Just the push event
 */

// ─── Branch → base path mapping ─────────────────────────────

$branches = [
    'refs/heads/dev'  => '/var/www/clients/client1/web3',
    'refs/heads/main' => '/var/www/clients/client1/web2',
];

// ─── Read secret ─────────────────────────────────────────────
// Try getenv() first (Apache SetEnv), fall back to shared/.env

$secret = getenv('GITHUB_WEBHOOK_SECRET') ?: null;

if (!$secret) {
    // Resolve: webhook.php is at releases/XXX/public/webhook.php
    // __DIR__ = .../releases/XXX/public → go up 3 levels = base_path
    $basePath = dirname(dirname(dirname(__DIR__)));
    $envPath = $basePath . '/shared/.env';

    if (file_exists($envPath)) {
        foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if ($line[0] === '#') continue;
            if (preg_match('/^GITHUB_WEBHOOK_SECRET=(.+)$/', $line, $m)) {
                $secret = trim($m[1], '"\'');
                break;
            }
        }
    }
}

if (!$secret) {
    http_response_code(500);
    die('GITHUB_WEBHOOK_SECRET not configured');
}

// ─── Validate signature ─────────────────────────────────────

$payload = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';

if (!$signature || !hash_equals('sha256=' . hash_hmac('sha256', $payload, $secret), $signature)) {
    http_response_code(403);
    die('Invalid signature');
}

// ─── Parse payload ──────────────────────────────────────────

$data = json_decode($payload, true);
if (!$data) {
    http_response_code(400);
    die('Invalid payload');
}

$ref = $data['ref'] ?? '';

if (!isset($branches[$ref])) {
    echo "Ignored: $ref";
    exit(0);
}

// ─── Dispatch deploy in background ──────────────────────────

$basePath = $branches[$ref];
$branch = basename($ref);
$deployScript = "$basePath/current/deploy.sh";
$logFile = "$basePath/shared/storage/logs/deploy.log";

// Ensure log directory exists
@mkdir(dirname($logFile), 0755, true);

// Log webhook trigger before dispatching deploy
$pusher = $data['pusher']['name'] ?? 'unknown';
$commitMsg = substr($data['head_commit']['message'] ?? '', 0, 80);
$commitSha = substr($data['head_commit']['id'] ?? '', 0, 7);
$triggerLog = sprintf(
    "[%s] WEBHOOK TRIGGER: branch=%s pusher=%s commit=%s (%s)\n",
    date('Y-m-d H:i:s'),
    $branch,
    $pusher,
    $commitSha,
    $commitMsg
);
@file_put_contents($logFile, $triggerLog, FILE_APPEND | LOCK_EX);

exec(sprintf(
    'nohup bash %s %s %s >> %s 2>&1 &',
    escapeshellarg($deployScript),
    escapeshellarg($branch),
    escapeshellarg($basePath),
    escapeshellarg($logFile)
));

header('Content-Type: application/json');
echo json_encode([
    'status' => 'deploy_triggered',
    'branch' => $branch,
    'pusher' => $pusher,
    'commit' => $commitMsg,
]);
