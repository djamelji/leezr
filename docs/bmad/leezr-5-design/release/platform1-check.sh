#!/usr/bin/env bash
# ADR-862 — read-only state of DEV Platform account #1 (the owner's account), before and after the owner changes its
# password. Prints NO password, hash, e-mail or session id : only yes/no and counts.
#   ssh leezr-dev 'bash -s' < docs/bmad/leezr-5-design/release/platform1-check.sh
set -euo pipefail
cd /var/www/clients/client1/web3/current
php8.4 -r '
require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
$db = DB::connection()->getDatabaseName(); if ($db !== "c1leezrdev") { fwrite(STDERR, "REFUSED: $db\n"); exit(2); }
$u = DB::table("platform_users")->where("id", 1)->first(["password", "remember_token"]);
$public = $u && password_verify("password", (string) $u->password); // the PUBLIC demo password (ADR-833), never a real one
$twofa = DB::table("two_factor_credentials")->where("authenticatable_type", "like", "%PlatformUser")->where("authenticatable_id", 1)->whereNotNull("confirmed_at")->exists();
$last = DB::table("platform_audit_logs")->where("action", "platform_user.password_changed")->where("target_id", "1")->max("created_at");
printf("platform_users #1 : public demo password accepted=%s · 2fa=%s · sessions=%d · sanctum_tokens=%d · last password change logged=%s · other platform accounts=%d\n",
  $public ? "YES (to change)" : "no", $twofa ? "on" : "off",
  DB::table("sessions")->where("platform_user_id", 1)->count(),
  DB::table("personal_access_tokens")->where("tokenable_type", "like", "%PlatformUser")->where("tokenable_id", 1)->count(),
  $last ?: "never", DB::table("platform_users")->where("id", "!=", 1)->count());
'
