#!/usr/bin/env bash
# Parallel checkout race against a real HTTP server (D34).
#
#   benchmarks/checkout/race.sh [rounds] [buyers] [keys]     (from backend/)
#
# Creates a throwaway database `eneba_race`, migrates it, starts PHP's built-in
# server with several worker processes (so requests really run in parallel,
# each with its own PostgreSQL session), runs race.php against it, then stops
# the server and drops the database. Never touches `eneba` or `eneba_test`.
set -euo pipefail
cd "$(dirname "$0")/../.."

PORT=8022
export DB_DATABASE=eneba_race
export PHP_CLI_SERVER_WORKERS=16
export LOG_STACK=single

dropdb --if-exists eneba_race
createdb eneba_race
php artisan migrate --force -q

# A port still in use would mean testing some other server.
if lsof -iTCP:$PORT -sTCP:LISTEN >/dev/null 2>&1; then echo "Port $PORT is already in use." >&2; exit 1; fi

# php -S directly (not artisan serve, which filters the environment of its
# child process): every worker inherits DB_DATABASE=eneba_race. Started from
# public/, because Laravel's router script loads index.php from the current
# directory (artisan serve does the same).
(cd public && exec php -S 127.0.0.1:$PORT ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php \
  >/dev/null 2>&1) &
disown # no "Terminated" job message when cleanup stops it
# The workers are forked children of the server: stop them all by command line.
cleanup() { pkill -f "php -S 127.0.0.1:$PORT" || true; sleep 0.5; dropdb --if-exists eneba_race; }
trap cleanup EXIT

for _ in $(seq 50); do curl -fs "http://127.0.0.1:$PORT/api/health" >/dev/null && break; sleep 0.1; done

php benchmarks/checkout/race.php "http://127.0.0.1:$PORT/api" "${1:-50}" "${2:-30}" "${3:-3}"
