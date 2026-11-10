#!/usr/bin/env bash
# Builds the throwaway benchmark database eneba_bench: drops it if it exists,
# runs the app's migrations (so the indexes are exactly the committed ones),
# loads data.sql, then VACUUM ANALYZE (fresh rows are not marked all-visible yet,
# which would add heap fetches no live database has).
#
# Usage (from backend/): benchmarks/catalog/build.sh
# Needs a local PostgreSQL where the current user can create databases.
# Takes about a minute. Drop it afterwards with: dropdb eneba_bench
set -euo pipefail
cd "$(dirname "$0")/../.."

DB=eneba_bench
dropdb --if-exists "$DB"
createdb "$DB"
DB_CONNECTION=pgsql DB_DATABASE="$DB" DB_URL= php artisan migrate --force --no-interaction
psql -q -d "$DB" -f benchmarks/catalog/data.sql
psql -q -d "$DB" -c 'VACUUM ANALYZE'
psql -d "$DB" -Atc "SELECT format('%s products, %s offers, %s keys (%s sold)',
  (SELECT count(*) FROM products), (SELECT count(*) FROM offers),
  (SELECT count(*) FROM product_keys), (SELECT count(*) FROM product_keys WHERE status = 'sold'))"
