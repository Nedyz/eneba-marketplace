# AI session log

Short summary per session. Full transcripts are in `ai-logs/`.

## Session 1 — 9 November 2026: planning

**Done**
- Read the brief and working rules. Listed ambiguities in the brief and proposed interpretations.
- Proposed architecture, folder structure, data model, REST endpoints, day-by-day plan and risks.
- Checked the local machine: PHP 8.4.1, Composer 2.8.12 and the Laravel installer come from Herd Lite (`pdo_pgsql` present); Node 26, Homebrew and `gh` installed. Missing: local PostgreSQL. No Docker (not needed).
- Wrote `docs/PLAN.md` and `docs/DECISIONS.md`.

**Decisions** (details in `DECISIONS.md`)
- Eneba.com is the design reference (no Figma was provided). Placeholder images.
- Nedas changed the homepage design: `GET /home` returns the list of blocks (type, title, endpoint), so the frontend hardcodes nothing about which blocks exist or their order (D4).
- Checkout expiry, answering Nedas's question: hard 15-minute expiry, a 2-minute payment hold before charging, lazy status updates because Render's free tier has no cron (D10).
- Day 1 is setup and deploy only; CI moves to day 2.

**Open issues**
- Exact Eneba colors and fonts still to be sampled (day 2).

## Session 1 (continued) — 9 November 2026: setup and deployment (day 1)

**Done**
- Installed PostgreSQL 17 (Homebrew), created `eneba` and `eneba_test`.
- Root `.gitignore` committed before any `.env` existed.
- Backend: Laravel 13 API with Sanctum; tests forced onto PostgreSQL (`eneba_test`) because `pg_trgm` does not exist in SQLite; `pg_trgm` enabled by a migration; `GET /api/health` (DB + `pg_trgm` check, 200/503) and CORS restricted to `FRONTEND_URL`; 8 tests.
- Removed installer extras that could not be justified: generated agent files (`backend/CLAUDE.md`, `AGENTS.md`), `laravel/pao` (changes test output when an AI agent runs tests), unused Vite/Blade files.
- Frontend: Vite + React 19 + TypeScript (strict), React Router 8, TanStack Query, Tailwind v4; typed fetch client; temporary health page; `vercel.json` for deep links.
- Dockerfile (serversideup/php 8.4 fpm-nginx) with migrations and caching on start.
- Deployed: https://eneba-marketplace.vercel.app → https://eneba-marketplace-api.onrender.com → Neon. Verified in a browser: health ok, deep links, CORS.
- Filled in the Commands and Deployment sections of `CLAUDE.md`.

**Decisions**
- D18: Sanctum tokens expire after 7 days and are deleted on logout (after checking in Sanctum's source that only SHA-256 hashes are stored).
- D19: a failed migration stops the container before nginx starts, so Render keeps the previous version live; migrations must be backward compatible.

**Problems found and fixed**
- A CORS test assumed a disallowed origin gets no header; the library sends the single allowed origin instead (the browser still blocks). Test rewritten to check the real property.
- Without local Docker, the image build and startup were replayed locally. This found two bugs that would have broken the first deploy: `optimize` failing on the missing views folder, and Laravel falling back to SQLite without `DB_CONNECTION`. Both fixed in the Dockerfile before the first Render build.

**Open issues**
- Day 2: CI (GitHub Actions + Postgres), migrations and seeders, catalog API, Eneba theme tokens, layout shell.
- Confirm on day 2 with a deliberately broken migration that Render's free tier keeps the old version live (D19).
- Render free tier cold start (about a minute) still to be handled in the UI and README.

## Session 2 — 9 November 2026 (evening): data model (day 2, started early)

**Done**
- Plan for tables and seed data agreed before code.
- 10 migrations: platforms, regions, sellers, products (GIN trigram index), offers, orders, order_items, product_keys, home_blocks, banners. CHECK constraints for business rules. Up, full rollback and re-run verified.
- Minimal Eloquent models (fillable, casts, relations; `ProductKey::code` uses the `encrypted` cast and is hidden from JSON).
- Deterministic demo seed: 7 platforms, 4 regions, 8 invented sellers, 50 games → 105 products, 371 offers (99 deals, 18 inactive, ~10% without stock), 2,446 encrypted keys, 11 users, 62 orders (60 paid over 45 days, plus one failed and one expired for the demo user).
- `DemoSeedTest` (11 tests): encryption round trip, sold keys linked to paid items of the same offer, key count = quantity, totals, every product buyable, 30-day window, demo login, idempotency, CHECK constraints, typo search smoke test. 19 tests pass.
- Verified with `EXPLAIN` that `ILIKE` and `<%` both use the trigram index. Simulated the production start twice (seeds, then skips).

**Decisions**
- D20: seed inside the production container (keys must be encrypted with production's `APP_KEY`), idempotent and transactional, via `AUTORUN_LARAVEL_MIGRATION_SEED`.
- D21: generated covers instead of image URLs.
- D22: game title only in `name`, trigram index on `name`, `regions.slug`, varchar + CHECK statuses.

**Problems found and fixed**
- Nedas asked whether bulk-inserted keys skip the `encrypted` cast: they do (insert passes straight to the query builder), so the seeder encrypts with `Crypt::encryptString()`, the same call the cast makes. Covered by a test.
- The day 1 health test dropped `pg_trgm`, which PostgreSQL now refuses because the products index depends on it; the test uses `CASCADE` (rolled back afterwards).

**Open issues**
- Not deployed yet: the first deploy with this change seeds Neon. Check the Render logs for the seeder output.
- Rest of day 2: CI, catalog API, theme tokens, layout shell, D19 broken-migration check.

## Session 3 — 9 November 2026 (evening): catalog API (day 2)

**Done**
- Plan agreed first, focused on how the "from" price is calculated and kept fast.
- `GET /api/products`: from price via `LEFT JOIN LATERAL` (cheapest active offer with a sellable key), platform/region/price filters, sorts `newest`/`price_asc`/`price_desc`/`name` with `NULLS LAST` and an `id` tie-breaker, paginated `JsonResource` output.
- `GET /api/products/filters`: platforms and regions that have products, min/max from price.
- Structure: `ProductIndexRequest` → `ProductController` → `Services/Catalog/ProductQuery` (+ `ProductFilters`, `ProductSort` enum, `CatalogFilterOptions`) → `ProductListResource`. `ProductKey::scopeSellable` as the single definition of stock.
- Factories for the catalog models (offers start without keys; `withKeys()`, `sold()`, `reserved($until)`).
- 27 new tests (46 total). Mutation checks: removing the `id` tie-breaker or `NULLS LAST` makes tests fail.
- Benchmark at 50k products / 1M keys: four indexes and a separate count query took the default listing from ~300 ms to ~3 ms, price sorts from ~300 ms to ~72 ms (D23).

**Decisions**
- D23: from price computed on read (stored price would go stale because reservations expire without writes); benchmark results and remaining limit.
- D24: out-of-stock products listed last; undated products last in `newest`; API prices in cents; non-faceted filter options.

**Problems found and fixed**
- `gte:price_min` rejected `price_max` sent alone (Laravel's `gte` fails when the other field is missing); caught by a test, rule now conditional.
- Factory states read `offer_id` before `->for($offer)` was resolved; moved to `afterCreating`.
- Benchmark showed `newest` and every count priced all 50k products; fixed with sort-matching indexes and a separate count.
- A migration renamed after being applied locally was never re-run (output hidden with `-q`); fixed by cleaning the local migration row. Not pushed, so production was unaffected.

**Open issues**
- Search (`q`, relevance sort, suggestions) on day 3 plugs into `ProductQuery`.
- Theme tokens, layout shell, CI and the D19 broken-migration check are still open for day 2 (plus checking whether serversideup hides artisan output in Render logs).

## Session 4 — 9 November 2026 (evening): CI and the D19 deploy check (day 2)

**Done**
- GitHub Actions CI with three parallel jobs: `backend` (Pint, 46 tests on PostgreSQL 17, migration round trip), `frontend` (lint, type check, build on Node 26, pinned to match Vercel), `docker` (builds the production image, starts it twice: first start seeds 105 products, second skips). Green on the first run.
- The CI container log answered an open question: the image does print `Seeding database.`; its absence in Render's log view is on Render's side.
- D19 check: pushed a migration running `SELECT 1/0`. CI's docker job caught it first. Render's manual deploy failed after about 1 minute with the error in the logs and no retries. An external check every 10 seconds saw only 200s during the whole check. Neon had no trace of the failed migration afterwards. Reverted.
- Fixed a stale index name in a `ProductQuery` comment.

**Decisions**
- D25: CI jobs; Render auto-deploy to "After CI Checks Pass" after the D19 check (Nedas's ordering, so the broken deploy could be observed).
- D19 updated with the verified free-tier behavior.
- D26: visible name "Intersheba" (Nedas: a public Eneba look-alike with checkout must not be mistaken for the real store).

**Problems found**
- Render skipped the auto-deploy of the broken commit for no visible reason (not CI gating, no build filters); the revert auto-deployed normally. Recorded as a one-off.

**Open issues**
- Turn on Render "After CI Checks Pass" (Nedas, in the dashboard).
- Theme tokens and layout shell next.
