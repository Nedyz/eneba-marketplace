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
