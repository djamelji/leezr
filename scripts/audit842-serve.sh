#!/usr/bin/env bash
# Leezr 5.0 (ADR-862) — THE browser-recipe server : the marked audit clone only, on http://127.0.0.1:8842.
# The simulated antivirus (DOCUMENT_SCANNER=simulated) is set HERE and nowhere else : it is a DEV/test driver that
# records `simulated` on every scan, and FileScannerManager refuses it when APP_ENV=production. Never copy this
# variable into a .env of DEV or PROD.
set -euo pipefail
cd "$(dirname "$0")/.."
DB=${E2E_DB_DATABASE:-leezr_audit842}
case "$DB" in leezr_audit*) ;; *) echo "refused: $DB is not a recipe clone" >&2; exit 2 ;; esac
export APP_ENV=local DB_DATABASE=$DB QUEUE_CONNECTION=sync MAIL_MAILER=smtp MAIL_HOST=127.0.0.1 MAIL_PORT=1025 MAIL_ENCRYPTION= \
  MAIL_USERNAME= MAIL_PASSWORD= REALTIME_DRIVER=null DOCUMENT_SCANNER=simulated SANCTUM_STATEFUL_DOMAINS=127.0.0.1:8842 \
  SESSION_DOMAIN= SESSION_SECURE_COOKIE=false APP_URL=http://127.0.0.1:8842 REDIS_PREFIX=$DB- CACHE_PREFIX=$DB- PHP_CLI_SERVER_WORKERS=6
php -r 'require "vendor/autoload.php"; $a=require "bootstrap/app.php"; $a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
  $db=Illuminate\Support\Facades\DB::connection()->getDatabaseName();
  exit(Illuminate\Support\Facades\DB::table("_recipe_clone_identity")->value("database_name")===$db ? 0 : 2);' \
  || { echo "refused: $DB is not a MARKED recipe clone" >&2; exit 2; }
cd public && exec php -S 127.0.0.1:8842 ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php
