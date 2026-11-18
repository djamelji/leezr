# Realtime SSE — observations (ADR-826)

DEV log (read only, storage/logs/laravel.log, 2026-11-18): connects=29541 disconnects=259 max-execution-fatal=22993
PHP-FPM max_execution_time=30 ; CLI=0 ; valet pool pm.max_children=5 reached (php-fpm.log)

Clone, php -S -d max_execution_time=30, stream_timeout=40, heartbeat=5, tab kept open 60 s then closed:
- baseline (HEAD before fix): connect=2 disconnect=0 fatal=1
- fixed: connect=2 disconnect=2 (durations 40 = configured lifetime, 26 = closed tab detected) fatal=0, tracker count back to 0

Tracker overlap (fictive ids 999001/999002): before fix the first ending stream unregistered the live one (tracked=0 visible=0 while a stream ran); after fix tracked=1 until the last stream ends.

php -S (no execution limit), heartbeat 5: closed tab released after ~11 s, baseline and fix identical ; heartbeat 30: not released within 200 s (built-in server specific, not production infrastructure).
