# Decisions and trade-offs

Each entry: the decision, why, and what we gave up. Used later to justify scope in the README.

## D1. Stack: React + TypeScript, Laravel, PostgreSQL (9 Nov)
- **Why**: Laravel gives authentication, migrations, validation and testing out of the box, which matters with a one-week deadline. PostgreSQL has `pg_trgm` for typo-tolerant search without an external search engine.
- **Gave up**: Go (faster runtime, but more to build by hand); Elasticsearch/Meilisearch (better search, but another service to host on a free tier).

## D2. Scope: go deep on the purchase flow, search, store filters and key delivery (9 Nov)
- **Why**: the brief says a few features done extensively beat many minimal ones, and the full purchase flow must work end to end.
- **Kept simple**: auth (email + password, no reset or verification), homepage blocks, order history, simulated payment.

## D3. Homepage blocks: one app, one service and endpoint per block (9 Nov)
- **Why**: "own backend service or database" is met by separate service classes, endpoints and data sources (`banners` table, aggregate over sold items, discounted offers). Real microservices would cost days and add nothing to the purchase flow.

## D4. Homepage layout is server-driven: `GET /home` returns the list of blocks (9 Nov)
- **Why** (Nedas's change to the first proposal): if the frontend called three fixed endpoints, it would still hardcode which blocks exist and their order. Now the database (`home_blocks`) decides blocks, order and titles; the frontend only maps a `type` to a component.
- **Details**: unknown types are skipped; endpoints are relative paths fetched only under the API base URL.
- **Gave up**: one extra request on page load (layout first, then blocks in parallel). Acceptable.

## D5. Product = edition (game + platform + region); offers = sellers' prices (9 Nov)
- **Why**: matches Eneba's model and makes platform/region filters simple columns. The product page compares sellers like for like.
- Store price filter and sort use the lowest active, in-stock offer price. Sellers are seeded data, no seller accounts.

## D6. Search lives in the Store page (9 Nov)
- **Why**: the header search (on every page) shows suggestions; pressing Enter opens the Store with `?q=`, so search results get filters, sorting and pagination for free.

## D7. Auth with Sanctum bearer tokens, not cookies (9 Nov)
- **Why**: frontend (`*.vercel.app`) and backend (`*.onrender.com`) are different sites; browsers block third-party cookies, so cookie sessions would need `SameSite=None`, CSRF handling and still break in some browsers.
- **Gave up**: a token in `localStorage` can be read by XSS. Mitigated by React's default escaping and no `dangerouslySetInnerHTML`.

## D8. Cart is client-side (localStorage); the server recalculates at checkout (9 Nov)
- **Why**: guests can fill a cart before signing in (the flow puts sign-in at checkout), no carts table needed. Prices and stock are never trusted from the client.
- **Gave up**: the cart does not follow the user across devices.

## D9. Keys: encrypted at rest, two-phase assignment (reserve, then pay) (9 Nov)
- **Why**: encrypted so a database leak does not expose sellable codes. Reservation with `FOR UPDATE SKIP LOCKED` lets concurrent buyers get different keys without waiting or double selling. The payment call is outside any locking transaction, which is how it must work with a real provider.
- **Gave up**: encrypted codes cannot have a unique index; `APP_KEY` must never change in production.

## D10. Reservation expiry: 15 minutes, hard expiry, lazy status updates (9 Nov)
- **Question raised by Nedas**: what if someone pays after the reservation expired and another buyer took the keys, and what marks old pending orders as expired?
- **Decision**: paying after `expires_at` always fails with "reservation expired" and releases the keys; the buyer checks out again (the cart is kept until payment succeeds). This avoids charging someone for keys that may be gone. Before charging, the pay endpoint extends the reservation by a 2-minute payment hold, so keys cannot be taken mid-payment.
- **Expiry mechanism**: Render's free tier has no cron jobs, so status is updated lazily (pay endpoint, and a bulk update before order history/detail queries). Key availability never depends on cleanup, because the reserve query treats expired reservations as available. An `orders:expire` command is also registered in the scheduler for environments with cron.
- **Gave up**: a pending order stays `pending` in the database until someone reads it. Nothing depends on that.

## D11. Simulated payment behind a `PaymentGateway` interface (9 Nov)
- **Why**: no real money; test card `4242 4242 4242 4242` succeeds, `4000 0000 0000 0002` is declined, so the failure path can be demonstrated. Stripe test mode could be added later as a second implementation.

## D12. Money as integer cents, EUR only, English only (9 Nov)
- **Why**: floats cannot represent prices exactly; one currency avoids exchange logic.

## D13. Offset pagination (9 Nov)
- **Why**: the catalog is small and the UI needs page numbers in the URL. Keyset pagination would be overkill.

## D14. Design reference: eneba.com (9 Nov)
- Eneba provided only the purchase flow diagram, no Figma. Colors, typography and spacing are sampled by hand from eneba.com into Tailwind theme tokens. No Eneba assets are copied.

## D15. Placeholder product images (9 Nov)
- **Why**: no licensing risk from hotlinking or scraping publisher or Eneba images. Real game names, generated placeholder covers.

## D16. Local PostgreSQL via Homebrew, no local Docker (9 Nov)
- **Why**: tests need real PostgreSQL (`pg_trgm`); Homebrew is the lightest install on macOS. Render builds the Docker image in the cloud, so Docker is not required locally.
- **Gave up**: Dockerfile errors are found only in Render builds. Fallback: install OrbStack.

## D17. Day 1 is setup and deployment only; CI moves to day 2 (9 Nov)
- **Why**: the session started in the afternoon; a working production deploy is the priority.

## D18. Sanctum tokens expire after 7 days and are deleted on logout (9 Nov, built on day 5)
- **Context**: checked in Sanctum's source: the `personal_access_tokens` table stores only a SHA-256 hash of each token (the plain token is returned once as `{id}|{random}`, compared with `hash_equals`). A copied table therefore cannot be used to log in; a fast hash is enough because the token is 40 random characters, unlike a guessable password. But `config/sanctum.php` defaults to `'expiration' => null`, so a token stolen from the browser (see D7) would work forever.
- **Decision**: `expiration` = 7 days (10080 minutes); logout deletes the current token row, so it stops working immediately. Both covered by tests.
- **Gave up**: users sign in again after a week. No refresh tokens (not worth the complexity here).

## D19. Migrations run at container start; a failed migration keeps the old version live (9 Nov)
- **Context**: Render's pre-deploy command (the usual place for migrations) is paid-only. Nedas asked what happens on Render if a migration fails during startup.
- **Decision**: the Docker image (serversideup/php) runs `migrate --force` and `optimize` on every start. If a step fails, the container exits before nginx starts.
- **What happens on failure**: Render only switches traffic to a new instance after it passes the health check (`/api/health`), and cancels the deploy after 15 minutes otherwise, so the previous version keeps serving. On PostgreSQL each migration runs in its own transaction (checked in Laravel's `Migrator`), so the failed migration leaves no half-applied change; earlier migrations from the same deploy stay applied.
- **Rule that follows**: migrations must be backward compatible (add columns or tables first, remove old ones in a later deploy), because the old code may run against the new schema after a partial failure.
- **Gave up**: every cold start (free tier spins down after 15 minutes idle) also runs the migration check, adding about a second to wake-up.
- **Verified on the free tier (9 Nov, commits `9b117e4` and revert `3cfa357`)**: a migration running `SELECT 1/0` was pushed on purpose.
  - CI's `docker` job caught it first: the container stopped during startup with `SQLSTATE[22012]: Division by zero`.
  - Render did not auto-deploy that commit (cause unknown, see below), so it was deployed manually. The deploy failed about 1 minute after it started ("Exited with status 1 while running your code"). The error was in the Render logs, and Render did not retry.
  - The previous version kept serving: an external check every 10 seconds (141 checks of `/api/health` and `/api/products` over 25 minutes, covering the push, the failed deploy and the revert) never saw anything but 200.
  - Neon afterwards: no row for the failed migration in `migrations`, still 16 migrations. The transaction rolled back as expected.
- **Unexplained, one-off**: Render did not auto-deploy `9b117e4` (Auto-Deploy "On Commit", no build filters, not CI gating: the previous deploy started before its CI finished). The revert `3cfa357` auto-deployed normally. Lesson: after a push, confirm in Render's Events that a deploy actually started.

## D20. Demo data is seeded inside the production container, once (9 Nov)
- **Problem found while planning the seed**: product keys are encrypted with the `APP_KEY` of whoever runs the seeder. Seeding Neon from a laptop would encrypt them with the local key, and production could not decrypt them at key reveal.
- **Decision**: `AUTORUN_LARAVEL_MIGRATION_SEED=true` in the Dockerfile makes the image run `migrate --force --seed` on every start. `DatabaseSeeder` skips quietly when products exist (it must not throw: a startup error stops the container, D19) and runs everything in one transaction (a failed seed leaves nothing behind and retries on the next start).
- **Seed design**: hand-written catalog (`database/seeders/data/catalog.php`) plus seeded random generators, so every environment gets identical data. No Faker (production installs `--no-dev`). Bulk inserts because Neon is remote; bulk inserts skip Eloquent casts, so the key seeder calls `Crypt::encryptString()` itself, the same format the `encrypted` cast uses (checked in Laravel's source and by a test).
- **Seeded order history**: about 60 paid orders over 45 days, so Best sellers has data and the 30-day window matters. A demo account (`demo@example.com` / `password`) is public on purpose.
- **Gave up**: one extra `SELECT` on every cold start. Changing the demo catalog later needs a manual reset of the production database.

## D21. Product covers are generated by the frontend (9 Nov, refines D15)
- **Decision**: `image_url` is nullable and `null` in the seed. The frontend draws a cover (gradient + title, colored by platform). Banners work the same way.
- **Why**: no third-party image service at runtime, no licensing risk. A real image URL can be set later without schema changes.

## D22. Data model refinements before the first migration (9 Nov)
- `products.name` is the game title only ("Elden Ring"); platform and region come from their columns. Otherwise suggestions would rank near-identical "Elden Ring (PC) Steam Key GLOBAL" strings.
- Trigram index on `name`, not `lower(name)`: pg_trgm lowercases when building trigrams, and a plain-column `gin_trgm_ops` index serves both `ILIKE '%term%'` and `term <% name` without repeating an exact expression in every query (verified with `EXPLAIN`).
- `regions` uses `slug` instead of `code`, like `platforms`.
- Status columns are `varchar` + CHECK (Laravel `enum()`), not native PostgreSQL enums, so adding a value stays a simple, backward-compatible change (D19). Business rules (positive prices, deal cheaper than original, sold/reserved key always linked to an order item) are CHECK constraints, so even a bug in a service cannot store inconsistent rows.
- Order data restricts deletes (an ordered offer or a user with orders cannot be deleted); catalog children cascade.

## D23. The store's "from" price is computed on read, with indexes chosen by benchmark (9 Nov)
- **Definition**: cheapest active offer with at least one sellable key (`available`, or `reserved` with `reserved_until` in the past). Computed with a `LEFT JOIN LATERAL` per product, which also returns that same offer's original price for the discount badge. The sellable-key rule is one scope (`ProductKey::scopeSellable`), reused by checkout on day 5 so store and checkout never disagree about stock. The current time is passed from PHP so tests can move the clock.
- **Why not store it on `products`**: stock changes over time without any write (an expired reservation becomes sellable again, D10), so a stored price would be stale until something touched the product. Keeping it correct would need a cron job, which the free tier does not have.
- **Benchmark** (local PostgreSQL 17, 50k products / 200k offers / 1M keys, throwaway database, best of 5 runs):

  | Request | Before | After |
  |---|---|---|
  | `newest` page + count | 153 + 144 ms | 0.4 + 3 ms |
  | `price_asc` page + count | 155 + 145 ms | 71 + 1.3 ms |
  | platform + price range page + count | 39 + 37 ms | 13 + 11 ms |

  What changed, each found with `EXPLAIN ANALYZE`: an index matching the `newest` and `name` sorts (Postgres reads 24 products in order and prices only those, instead of pricing all 50k and keeping 24); `id` added to the offers price index (removed a sort per product); a partial index on unsold keys (sold keys pile up forever); and a separate count that skips the price join unless a price filter is set.
- **Remaining limit**: sorting by price cannot use an index, because the price is computed. Every product matching the other filters is priced on each request: about 70 ms at 50k products, growing linearly. Fine for this catalog (105 products). Scaling path: eager reservation expiry (cron), then a stored, indexed from price updated in the same transactions that change offers and keys.
- **Detail**: `is_active` is written as a literal condition, not a bound parameter, so Postgres can always match the partial index.
- **Reproducing (added 10 Nov)**: the day 2 benchmark script was not committed, and its data generator is lost. `backend/benchmarks/catalog/` (D28) now rebuilds the same scale (50k products, 200k offers, 1M keys of which 280k sold) from the committed migrations and measures the same request shapes through the app. With the current code, best of 5: `newest` page + count 0.04 + 3.5 ms, `price_asc` 71.8 + 2.1 ms, platform + price range 0.1 + 15.5 ms. The first two match the table above; the third differs because the data differs (in the new data, 24 matching products are found almost at once walking the `newest` index).

## D24. Store listing behavior (9 Nov)
- Out-of-stock products stay in the store with `price: null` (Nedas's choice): the catalog stays complete and search can still find sold-out games. They sort last in both price orders, and drop out when a price filter is set.
- Products without a release date sort last in `newest` (Nedas's request; PostgreSQL puts NULLs first on DESC by default).
- Every sort ends with `products.id`, so offset pagination (D13) never shows a product twice or skips one.
- Prices in the API are integer cents (D12); the frontend converts to euros.
- `/products/filters` returns the same options for every request (not narrowed by the active filters), only platforms and regions that have products, and the min/max from price for the slider.

## D25. CI on GitHub Actions, including a production image start (9 Nov)
- **Jobs** (parallel, on every push to `main` and every pull request):
  - `backend`: Pint formatting check, tests on a PostgreSQL 17 service container (real `pg_trgm`), and a migration round trip (`migrate:fresh`, `migrate:reset`, `migrate`) so a broken `down()` is caught.
  - `frontend`: lint, type check and production build on Node 26, pinned in `.nvmrc` and `engines` to match Vercel.
  - `docker`: builds the production image and starts it against an empty database, checking that the first start migrates and seeds 105 products and that a restart skips the seed (D20). The full container log is always printed.
- **Why the docker job**: there is no local Docker (D16), so before this, Dockerfile and startup problems only showed up in Render builds. It costs a few minutes per run.
- **Deploy gating**: Vercel deploys on every push regardless of CI. Render's auto-deploy is switched to "After CI Checks Pass" after the D19 check (which needed an ungated deploy to observe Render's behavior), so a commit with failing tests never reaches the production API.

## D26. The app is called "Intersheba", not "Eneba" (9 Nov)
- **Decision (Nedas)**: the app copies Eneba's look and has sign-in and checkout, so a public site branded "Eneba" could be mistaken for the real store. The visible name is "Intersheba" (a text wordmark, no Eneba logo or assets), and the footer says it is an Eneba internship assignment, not affiliated with Eneba.
- **Scope**: user-visible branding (wordmark, page title, footer). Internal names (repository, service URLs, database names) stay as they are.

## D27. Theme tokens measured from eneba.com; layout shell (9 Nov)
- **Method**: eneba.com opened in a Playwright browser; colors, font, sizes and spacing read with `getComputedStyle` (desktop 1440 px and mobile 390 px). The values are Tailwind v4 `@theme` tokens with semantic names (`bg-surface`, `text-muted`, `bg-accent`...), each commented with the element it was measured from. No Eneba logo, icons or images are used (D14, D26).
- **Font**: Eneba uses Metropolis, which is public domain (Unlicense), so the app uses the same font, self-hosted via `@fontsource/metropolis` (no font CDN request).
- **Accessibility**: every measured text/background pair passes WCAG AA (lowest: muted `#B3AAC9` on page purple, 4.79:1), so no color had to be changed. Keyboard focus is a 2 px yellow outline; the search box shows it around the whole box.
- **Layout**: sticky header (wordmark, outlined search, cart, sign in), a category bar listing platforms from `GET /products/filters` (only platforms that have products), footer stating the project is an Eneba internship assignment, not affiliated with Eneba. Mobile follows Eneba: one 56 px icon row, search opens below it.
- **Server waking banner**: shown when API requests have been running continuously for more than 3 seconds (Render free tier cold start), built once in the shell. Verified by delaying API responses by 6 s in the browser: hidden at 1 s, shown at 4 s, gone after the response.
- **Deliberate differences from Eneba**: the search box fills the free space (Eneba's sits next to language and wishlist controls the app does not have); no language/currency selector or wishlist (EUR only, D12).

## D28. Search: pg_trgm word similarity at 0.4, operator form, relevance ranking (10 Nov)
- **Match**: `name ILIKE '%term%'` (exact substring, LIKE wildcards in the input escaped) OR `term <% name` (fuzzy). Same rule for `GET /products?q=` and `GET /search/suggestions`.
- **`word_similarity`, not `similarity`**: `similarity` divides by the trigrams of the whole name, so long titles score low even when they contain the exact word typed ("zelda" vs "The Legend of Zelda: Tears of the Kingdom" = 0.18, "sims" vs "The Sims 4: Cottage Living" = 0.19). `word_similarity` scores the best-matching part of the name (1.0 for both). `strict_word_similarity` was rejected because it penalizes half-typed words ("eld" vs "Elden Ring": 0.43, against 0.75), which autocomplete depends on.
- **Threshold 0.4** (pg_trgm default 0.6), measured on the real catalog with 30 typo queries ("witcer", "wticher", "hogwards", "balders gate", "stardw valey", "pokemon"...):

  | Threshold | Intended game found | Unrelated games also matched |
  |---|---|---|
  | 0.6 (default) | 21 / 30 | 1 |
  | 0.5 | 28 / 30 | 6 |
  | **0.4** | **30 / 30** | **6** |
  | 0.3 | 30 / 30 | 10 |

  At 0.4 every unrelated match scores below the intended game, so it only appears after it ("stardew" also matches Starfield at 0.5; "forza" matches "Need for Speed" through "for").
- **Operator form plus `SET LOCAL`**: only the operator (`<%`) can use the GIN trigram index, and it reads its threshold from the `pg_trgm.word_similarity_threshold` setting, not from the query. Writing `word_similarity(term, name) >= 0.4` instead gives the same rows but a sequential scan (50k products, "witcer": 51.1 ms against 2.5 ms). So `ProductSearch::withThreshold()` opens a transaction, calls `set_config(..., true)` (= `SET LOCAL`, ends with the transaction) and runs both the count and the page query inside it. Without a search term no transaction is opened.
- **Testing the setting**: `RefreshDatabase` wraps every test in a transaction, so the search transaction becomes a savepoint, and a transaction-local setting outlives a savepoint (it lasts until the outer transaction ends). The "does not leak" check therefore lives in its own class without `RefreshDatabase` (`SearchThresholdIsolationTest`, Nedas's catch), which checks the setting is back to 0.6 after a request. A separate test searches "pokemon" (0.45 against "Pokémon Scarlet"), which fails if the threshold is not applied.
- **Ranking** (`sort=relevance`, the default when `q` is set):
  1. Names starting with the term ("mario": Mario Kart before Super Mario Odyssey).
  2. `word_similarity`, highest first (the intended game above fuzzy noise).
  3. `similarity`, highest first (tie-breaker: prefers names closer in length to the term).
  4. Name, then `id`, so offset pages stay stable (D24).

  Each step has a test whose data makes every later step disagree with it, so removing any step fails a test. This was checked by removing each step (and the escaping and `set_config`) one at a time. Two tests first passed without their rule, because name order happened to agree with it; they were rewritten.
- **Sort and query rules**: an explicit `sort` overrides relevance. `sort=relevance` without `q` falls back to `newest` (a shared URL can keep it after the search box is cleared; same leniency as unknown slugs, D24). `q` is trimmed (middleware) and inner whitespace collapsed, 1 to 100 characters on `/products`; an empty `q` means no search.
- **Suggestions**: at most 8 rows, 2 to 100 characters, one row per edition (game + platform + region) with the store's product card shape and from price (Nedas's choice, as on Eneba). The frontend dropdown adds a "see all results" row linking to the store with `?q=`.
- **Performance** (throwaway local database, PostgreSQL 17: 50k products, 200k offers, 1M keys of which 280k sold; vacuumed; best of 5). Produced by `backend/benchmarks/catalog/` (`build.sh`, then `php run.php`); first measured by hand during the session, with the same plans and times within 6% except the dragon count (5.2 ms by hand). Store request `q=… price_min=1000 price_max=5000` (the price filter makes the count use the from-price lateral join too), exact SQL captured from Laravel's query log:

  | Term | Names matched / after price filter | Count | Page | Products scan |
  |---|---|---|---|---|
  | dragon | 1,720 / 1,096 | 3.3 ms | 6.0 ms | trigram index (BitmapOr) |
  | ring | 4,973 / 3,313 | 14.8 ms | 64.8 ms | sequential (page), index (count) |
  | king (the most common of 18 vocabulary words tried) | 6,622 / 4,376 | 20.3 ms | 68.0 ms | sequential (page), index (count) |
  | dr (2 characters, only "dragon" contains it) | 1,720 / 1,096 | 46.3 ms | 48.9 ms | sequential |
  | s (1 character) | 28,516 / 18,663 | 62.2 ms | 99.5 ms | sequential |

  In the index plans, the from price costs one index probe on offers and one on keys per matched product (~2 µs each). Without a price filter the count skips that join ("king": 9.9 ms).
- **Remaining limits**:
  - For very common terms Postgres sometimes picks a sequential scan although the index plan is twice as fast ("king": 67 ms against 29 ms with `enable_seqscan=off`, on the benchmark database). Cause: pg_trgm declares its functions with cost 1 (the same as an integer comparison), but they cost about 1 µs per row, so both plans come out within 0.5% of each other and the planner treats them as equal. Not fixed: changing an extension's function costs is invasive, and the real catalog has 105 products (every query is under 1 ms; Postgres rightly reads the 4-page table sequentially).
  - Terms of 1 or 2 characters have no complete trigram, so they always scan the table (~100 ms at 50k). Suggestions require 2 characters; the store accepts 1.
  - Not handled: abbreviations ("gta", "rdr" find nothing; that would need an alias table) and accents beyond fuzzy matching ("pokemon" finds "Pokémon" at 0.45; `unaccent` was not added).
- **Gave up**: one transaction (3 extra small statements) per search request.

## D29. Store state in the URL; header autocomplete (10 Nov)
- **The URL is the only copy of the store state** (search, platforms, regions, price, sort, page). No `useState` mirror: Back/Forward, reload and shared links all show the same page. Filter changes push a history entry, so Back undoes the last filter.
- **Three forms, converted by pure functions**: browser URL (`platform=steam&price_max=30`, whole euros, readable) ↔ `StoreParams` (typed, always valid) → API query (`platform[]=steam&price_max=3000`, Laravel's brackets and cents, only in `api/products.ts`).
- **Lenient parsing**: the URL is untrusted input and the API answers 422 to malformed values, so `parseStoreParams` repairs each bad value on its own (`page=abc` → 1, swapped prices swapped back, `relevance` without `q` → `newest` as in D28, limits copied from `ProductIndexRequest`) instead of showing an error page. Unknown slugs are kept: the API treats them as matching nothing (D24). A messy URL is rewritten to its canonical form with `replace`.
- **Canonical form = cache identity**: defaults omitted, fixed order, sorted slugs. The TanStack Query key is the parsed object, so URLs that differ only in order or defaults share one cache entry; Back/Forward within the 30 s stale time make no request (checked: 0 requests for Back, Back, Forward). `keepPreviousData` keeps the old grid visible (dimmed) while the next one loads. Changes go through `applyStoreChange`, which resets to page 1 and round-trips through the URL form, so a click and a hand-typed URL are cleaned by the same rules.
- **Details found while testing**: React Router pushes even when the URL is unchanged (checked in its source), so a change that leads to the current URL is skipped; price inputs apply on Enter/Go or when focus leaves the price group, not per keystroke or on Tab from min to max; removing a chip moves focus to the result count (otherwise it fell to `<body>`).
- **Autocomplete**: input value is local state; `useDebouncedValue` (250 ms) feeds the query key; each term is its own cache entry; superseded requests are aborted via `AbortSignal`, and the dropdown only reads the current key, so an old slow response cannot replace newer results (checked with an artificially slow request: aborted when the next one started, never shown). WAI-ARIA combobox (arrow keys, Enter, Escape, `aria-activedescendant`), "See all results" as the last option.
- **Dropdown visibility follows the live input** (Nedas's catch): `keepPreviousData` returns the previous rows even when the query is disabled (term under 2 characters), and during the debounce the previous term's rows are still current. So the list is shown only while the input is focused and its normalized value has at least 2 characters. Checked by deleting quickly from "witcher" to "w": the list closes immediately and stays closed.
- **Header search is a fresh search** (Nedas's choice): submitting goes to `/store?q=…` only, dropping the store's other filters, as D6 describes. On `/store` the box shows the URL's `q` and follows Back/Forward; typing never writes to the URL.
- **Tests**: Vitest for the pure logic (URL parsing and serializing, page window, API query), run in CI. Components are checked in a real browser with Playwright, not with unit tests.
- **Gave up**: price is two whole-euro inputs, not a slider (maybe day 6); `per_page` is fixed at 24 and not in the URL; no prefetching of the next page.

## D30. Product page: offer order and sold-out offers (10 Nov)
- **Endpoint**: `GET /products/{slug}` returns the store card's fields (same `price` block, produced by the same `ProductQuery::withFromPrice()`), the description, and every active offer with seller, price, discount and `stock` (exact number of sellable keys, `ProductKey::scopeSellable`, so stock means the same thing on the store, the product page and, on day 5, at checkout). One `$now` per request, so the header price and the per-offer stock agree. Unknown slug: 404 JSON.
- **Order** (Nedas's choice of tie-break):
  1. Offers with stock before sold-out ones (a sold-out offer never sits above one you can buy, even when it is cheaper).
  2. Price, cheapest first.
  3. `offers.id`, the same tie-break as the store's from price. So the first offer is exactly the store card's offer, including its original price: two sellers can both charge €19.99 with only one showing "-20%", and a rating tie-break would make the card's badge disagree with the top row. Seller rating is shown on every row instead.
  Sorted in PHP after one query: Postgres cannot use the `stock` alias inside an `ORDER BY` expression, so SQL would repeat the count subquery, and a product has at most one offer per seller. The frontend never re-sorts.
- **Sold-out offers** (Nedas's choice): kept, last, `stock: 0`, shown dimmed (muted text, no card background rather than opacity, so contrast stays AA). Same reasoning as sold-out products in the store (D24), and their stock can return without any write when a reservation expires (D10). **Inactive offers** (`is_active = false`) are left out: the seller withdrew them. A product with no offers returns `offers: []` and `price: null`.
- **Stock display**: "Only N left" at 5 or fewer, otherwise "In stock". The exact count is in the API for the cart's quantity limit.
- **Tests**: 17 feature tests; each ordering and stock rule, the `is_active` filter, the seller eager load (query count) and the route order were removed one at a time to confirm a test fails.
- **Gave up**: a higher-rated seller does not win a price tie.

## D31. Cart: the browser keeps the wish, the server quotes reality (10 Nov)
- **Endpoint**: `POST /cart/quote` takes `lines: [{offer_id, qty}]` (1 to 50 lines, qty 1 to 10, distinct offers) and returns, per line in request order, a `status`, the current `stock` and price, the line total, and the product and seller; plus `total_cents` and `can_checkout`. Any other field the client sends (a price, a total) is never read. An offer id that does not exist is not a 422: a cart can outlive an offer, so that line is `unavailable`. Public (guests fill a cart before signing in, D8), read-only: nothing is reserved; keys are reserved only by `POST /orders` (D9). `CartQuoter` is the one implementation, and `POST /orders` (day 5) will reuse it.
- **Statuses**: `available` (stock ≥ qty, counted in the total), `insufficient_stock` (0 < stock < qty), `sold_out` (active offer, no sellable key), `unavailable` (withdrawn with `is_active = false`, or deleted; no price is quoted, because there is nothing to pay for). Stock is `ProductKey::sellable`, the same rule as the store, the product page and checkout (D23, D30).
- **What the browser stores** (`intersheba.cart.v1`): offer id, quantity, and a display snapshot (product name and slug, seller name, the price last shown). The snapshot is never sent and never summed; it names a line before the quote arrives or after its offer is deleted, and it is how a price change is noticed. Parsed leniently like the store URL (D29): bad lines dropped, quantities clamped, duplicates merged, at most 50 lines.
- **When an offer changes while it sits in a cart** (Nedas's question):
  - *Price changes*: the line and the total use the new price, and the line says "Price went up/dropped from X to Y since you last saw it". The snapshot is then updated, so the note shows once per detection (Nedas's choice) and is gone after a reload; within one visit, several changes read from the first price ("from 20 to 25", not "from 22 to 25").
  - *Partly or fully sold out, or withdrawn*: the line stays, is left out of the total, says what happened ("Only 1 available right now" with "Change to 1"; "See other sellers" otherwise), and checkout is disabled until every line is `available`.
  - **Problem lines block checkout; the cart is never changed automatically** (Nedas's choice). Stock comes back without any write when a reservation expires (D10), so clamping quantities or dropping sold-out lines would lose the buyer's choice over something temporary. Verified in the browser: a line sold out by another buyer's reservation became buyable again, unchanged, once the reservation expired. A side effect is that the total shown when checkout is enabled is exactly what checkout charges.
  - *Freshness*: the cart page treats the quote as always stale: it refetches on page load, when the tab becomes visible again, and on every quantity change (previous quote kept on screen, dimmed, superseded requests aborted, D29). Checkout is only enabled on a current quote (not the previous one kept during a change, not one kept after a failed refetch).
  - *Change between the last quote and checkout* (decided now, built on day 5): `POST /orders` also receives `expected_total_cents`, the total the buyer saw. If the server's recalculated total differs, it answers `409 price_changed` with a fresh quote and creates nothing. With the planned `409 Out of stock`, a buyer is never charged an amount they were not shown.
- **Limits**: 10 keys per line (Nedas's choice), 50 lines. Add to cart is capped at min(10, stock) per offer, so the product page cannot create a problem line.
- **Quote request = cache key** (Nedas's note): the request is the cart's `[offer_id, qty]` pairs sorted by offer id, so the same contents in another order, or with another snapshot, share one TanStack Query entry, and updating the snapshot never triggers a refetch. The cart page therefore renders lines in the cart's own order (order added) and matches quote lines by `offer_id`, never by position.
- **State module instead of a React context** (changed from PLAN.md during the session): the cart is one module-level store read with `useSyncExternalStore` (like a singleton service with a change event). Each change writes localStorage once, synchronously, rather than in an effect that StrictMode runs twice; another tab's change arrives through the `storage` event as one more update (verified: adding in one tab updated the other tab's open cart page). The per-visit price notes live in the same store, so detecting a change and recording the new price as seen happen in one step, without a `setState` inside an effect (oxlint `set-state-in-effect`).
- **Tests**: 28 backend feature tests (each status and its boundary, the sellable rule, expiry, ignored client prices, request order, no reservation, constant query count, 13 validation cases); 12 rules broken one at a time to confirm a test fails. 40 Vitest tests for the storage and price-note rules; 7 of them mutation-checked.
- **Gave up**: the cart does not follow the user across devices (D8); the header count comes from localStorage and can include lines that are no longer buyable until the cart page is opened.
