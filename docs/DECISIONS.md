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
- **What happens on failure**: Render only switches traffic to a new instance after it passes the health check (`/api/health`), and cancels the deploy after 15 minutes otherwise, so the previous version keeps serving. Render's docs do not describe free-tier differences, so we will confirm this once on day 2 with a deliberately broken deploy. On PostgreSQL each migration runs in its own transaction (checked in Laravel's `Migrator`), so the failed migration leaves no half-applied change; earlier migrations from the same deploy stay applied.
- **Rule that follows**: migrations must be backward compatible (add columns or tables first, remove old ones in a later deploy), because the old code may run against the new schema after a partial failure.
- **Gave up**: every cold start (free tier spins down after 15 minutes idle) also runs the migration check, adding about a second to wake-up.

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

## D24. Store listing behavior (9 Nov)
- Out-of-stock products stay in the store with `price: null` (Nedas's choice): the catalog stays complete and search can still find sold-out games. They sort last in both price orders, and drop out when a price filter is set.
- Products without a release date sort last in `newest` (Nedas's request; PostgreSQL puts NULLs first on DESC by default).
- Every sort ends with `products.id`, so offset pagination (D13) never shows a product twice or skips one.
- Prices in the API are integer cents (D12); the frontend converts to euros.
- `/products/filters` returns the same options for every request (not narrowed by the active filters), only platforms and regions that have products, and the min/max from price for the slider.
