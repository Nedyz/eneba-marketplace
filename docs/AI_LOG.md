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

## Session 5 — 9 November 2026 (evening): theme tokens and layout shell (day 2)

**Done**
- Measured eneba.com in a Playwright browser (computed styles at 1440 px and 390 px): Metropolis font, purple palette, yellow accent, square corners, header and category bar sizes, 1200 px content width.
- Tailwind v4 `@theme` tokens with semantic names; Metropolis self-hosted via `@fontsource`.
- Layout shell: sticky header (Intersheba wordmark, search box (visual until day 3), cart, sign in), category bar fed by `/products/filters`, footer with the "not affiliated with Eneba" note, mobile header with a search toggle, server waking banner. `/` is a placeholder until the day 4 homepage; health page at `/health`. Page title and favicon changed to Intersheba.
- Checked with screenshots against Eneba at both widths, a simulated 6 s cold start, and keyboard tab order.

**Decisions**
- D27: measured tokens, Metropolis (public domain), deliberate differences from Eneba.

**Problems found and fixed**
- Page content started 20 px further in than Eneba's (the 1200 px width included the padding); fixed.
- oxlint flagged `setState` inside an effect in the banner; the rewrite also fixed the timer restarting whenever the number of requests changed during a cold start.
- The search input had no visible keyboard focus (`outline-none`); the box now shows the focus ring via `focus-within`.

**Open issues**
- Day 3: search (trigram search, suggestions, `q` in the store), store page with URL state, header autocomplete.

## Session 6 — 10 November 2026: search backend (day 3)

**Done**
- Plan agreed first, based on measurements: trigram scores on the real catalog for `similarity`, `word_similarity` and `strict_word_similarity`; threshold candidates scored on 30 typo queries; EXPLAIN ANALYZE on a 50k-product database.
- `GET /api/products?q=` (combines with filters; `sort=relevance` is the default when `q` is set and falls back to `newest` without it) and `GET /api/search/suggestions?q=` (up to 8 editions, store product card shape).
- `Services/Catalog/ProductSearch`: match (`ILIKE` substring OR `<%` at 0.4, LIKE wildcards escaped), relevance order (prefix, `word_similarity`, `similarity`, name, id), and `withThreshold()` (transaction + `set_config(..., true)` around the count and page queries).
- 27 new tests (73 total), including `SearchThresholdIsolationTest` without `RefreshDatabase`, and a "pokemon" guard (0.45, only found at 0.4) on both endpoints.
- Mutation checks: removing the threshold, the escaping, `SET LOCAL` (made session-wide), or each ranking step fails at least one test; the suggestions endpoint without `withThreshold()` fails its guard test.
- `backend/benchmarks/catalog/` (`build.sh`, `data.sql`, `run.php`): rebuilds the 50k-product / 1M-key benchmark database and EXPLAIN ANALYZEs the D23 and D28 request shapes through the app. Excluded from the Docker image. Throwaway databases dropped afterwards.

**Decisions**
- D28: `word_similarity` (partial names, half-typed words), threshold 0.4 (30/30 typos, noise always ranked below), operator form + `SET LOCAL` (the function form cannot use the index: 51 ms against 2.5 ms), ranking, sort fallback, suggestions = one row per edition (Nedas's choice; the frontend adds a "see all results" row), performance table and remaining limits.
- D23: the original benchmark script was not committed; the new script reproduces it, with today's numbers added.

**Problems found and fixed**
- Two test expectations were wrong before running ("eld" also substring-matches "Zelda"); checked against real scores first.
- Mutation checks showed three weak ranking tests (name order agreed with the rule under test, so removing the rule changed nothing); rewritten with data where every later tie-breaker disagrees.
- `RefreshDatabase` turns the search transaction into a savepoint, so a leak test inside it could not fail (Nedas's catch); moved to its own class.
- "dragon" was assumed to be the worst case; measuring 18 words showed "king" (6,622 matches) is heavier, and 1-character queries are the true worst case (~100 ms at 50k).
- D28's first numbers came from a hand-built database; replaced with the committed script's output, so every number has a source.

**Open issues**
- For very common terms Postgres sometimes picks a sequential scan over the 2x faster trigram index plan (pg_trgm functions declared with cost 1). Irrelevant at 105 products; documented in D28.
- Not handled: abbreviations ("gta"), `unaccent`.
- Next session (frontend): store page with URL state, header autocomplete with a "see all results" row.

## Session 7 — 10 November 2026: store page and header autocomplete (day 3)

**Done**
- Plan agreed first, with explanations of URL state with TanStack Query and of the autocomplete debounce.
- Store page `/store`: platform/region checkboxes, whole-euro price inputs, sort ("Best match" only while searching), removable chips, pagination with real hrefs, loading/error/empty/past-the-end states, collapsible filters on mobile. All state in the URL (`storeParams.ts`, `useStoreParams`).
- `ProductCard`, `ProductCover`, `Price` are store-agnostic, for the day 4 homepage blocks (Nedas's request).
- Header autocomplete: WAI-ARIA combobox, 250 ms debounce, up to 8 editions, "See all results" row, fresh `?q=` search on submit, input follows the URL on `/store`.
- Vitest added (56 tests: URL parsing/serializing, pagination window, API query) and run in CI. The 9 parsing rules were mutation-checked: removing any one fails a test.
- Checked in a real browser (Playwright): request counts, Back/Forward from cache (0 requests), bad URLs canonicalized without a 422, keyboard and mouse use of the dropdown, an artificially slow request aborted and never shown, desktop and mobile layouts, no console errors.

**Decisions**
- D29: URL as the single source of truth, lenient parsing, canonical form as cache identity, autocomplete layers (debounce, cache, keyed results + abort), dropdown visibility from the live input, fresh search from the header, Vitest for pure logic only.

**Problems found and fixed**
- Dropdown could show old rows under an almost empty input, through `keepPreviousData` on a disabled query and the debounce delay (Nedas's catch, before implementation); visibility now uses the live input, verified by deleting quickly from "witcher" to "w".
- Price inputs applied the minimum when tabbing to the maximum, and the `key` remount stole focus; now applied when focus leaves the group, synced without remounting.
- React Router pushes a history entry even for an unchanged URL (checked in its source): unchanged updates are skipped.
- Removing a filter chip dropped keyboard focus to `<body>`; focus now moves to the result count.
- Pagination highlighted the page from the previous response while the next one loaded; now uses the URL's page.
- Suggestion thumbnails rendered full width: a caller's `w-9` lost to the built-in `w-full` (Tailwind resolves conflicting classes by stylesheet order). `ProductCover` no longer takes a `className`; its `size` sets the width.
- CI failed on the first PR run: `api/client.ts` throws at import without `VITE_API_URL` (on purpose), and Vitest had silently read the developer's `.env.local`. Tests now get a fixed placeholder URL in `vite.config.ts` (`test.env`), so they no longer depend on local files; verified by running them with the variable emptied and without `.env.local`.

**Open issues**
- Checkbox ticks appear ~27 ms after the click (React Router updates as a transition); not noticeable, `flushSync` would remove it.
- Day 6 polish: the generated cover repeats the card title; long names are truncated next to discounted prices in mobile suggestions; optional price slider.
- Product links lead to the 404 page until the product page exists (day 4).

## Session 8 — 10 November 2026: product page (day 4, session 1)

**Done**
- Branch `day4` created; sessions 2 (cart) and 3 (homepage) continue on it. The PR is opened now so CI runs on every push, and is merged by the homepage session (Nedas's choice).
- Plan agreed first, with the offer order and sold-out handling explained before any code.
- Backend: `GET /products/{slug}` (`ProductDetails` service, `ProductDetailResource` extending the card resource, `OfferResource`). Discount rounding moved to `App\Support\Discount`, shared by card and offers. 17 feature tests (backend suite: 90); each rule mutation-checked.
- Frontend: `/product/:slug` with cover, facts, from price, description and `OfferList` ("Best price" on the first in-stock row, sold-out rows dimmed, "Only N left" at 5 or fewer). Loading skeleton, "Game not found" page (404 not retried), retry on other errors, tab title via React 19 `<title>`.
- Vitest: stock labels and date formatting (65 tests).
- Checked in a real browser (Playwright) on the seed: a cheaper sold-out offer below an in-stock one (Forza Horizon 5), a same-price tie ordered by id and matching the store card (GTA V), the 404 page with a single request, desktop and mobile, new text colors contrast-checked (lowest 4.79:1).

**Decisions**
- D30: in stock first, then price, then offer id (matches the store's tie-break); sold-out offers kept and dimmed, inactive offers excluded.

**Problems found and fixed**
- `formatDate("2022-02-25")` showed 24 February west of UTC (date-only strings parse as UTC midnight). Formatter fixed to UTC. The test could not catch the bug, because CI runs in UTC and this Mac east of it, so Vitest now runs in `America/New_York`; the test was confirmed to fail without the fix.
- While mutation-testing the date fix, `git checkout` on the not-yet-committed `format.ts` reset it to `main` (removing `formatDate`); restored from a saved copy before committing, all checks rerun.

**Open issues**
- No add to cart yet (session 2); the offer row leaves room on the right for the button.
- The seed has no product whose offers are all sold out; that case is covered by a backend test only.
