# Intersheba: a game key marketplace

[![CI](https://github.com/Nedyz/eneba-marketplace/actions/workflows/ci.yml/badge.svg)](https://github.com/Nedyz/eneba-marketplace/actions/workflows/ci.yml)

This is my homework for the Eneba Software Engineer internship (brief: [`docs/ASSIGNMENT.md`](docs/ASSIGNMENT.md)). It is a marketplace for digital game keys styled after eneba.com. It is **not affiliated with Eneba**. The app calls itself "Intersheba" so that a public site with sign-in and checkout cannot be mistaken for the real store ([D26](docs/DECISIONS.md#d26-the-app-is-called-intersheba-not-eneba-9-nov)).

## Try it

| | |
|---|---|
| App | https://eneba-marketplace.vercel.app |
| API | https://eneba-marketplace-api.onrender.com/api/health |
| Demo account | `demo@example.com` / `password` (or register a new account) |
| Card that pays | `4242 4242 4242 4242` |
| Card that is declined | `4000 0000 0000 0002` |

- **The API runs on Render's free tier and sleeps after 15 minutes without traffic. The first request can take up to a minute.** The app shows a "waking up the server" banner while it waits.
- Payment is simulated. Only the two test cards above are accepted, and the payment page says so. Do not enter a real card. Any future expiry date (MM/YY) and any 3-digit CVC work; the "Use" buttons fill in everything.
- The demo account is shared, so its order history can include orders placed by other people.

A full purchase: open a game from the homepage or the search box, add an offer to the cart, check out (you are asked to sign in), pay with `4242…`, and the key is shown on the order page. Your orders are under the account menu.

## What I built, and where I went deeper

The brief says that "a few features done extensively beat many fitting minimal requirements", so I picked the parts that decide whether a marketplace works and spent most of the week on them ([D2](docs/DECISIONS.md#d2-scope-go-deep-on-the-purchase-flow-search-store-filters-and-key-delivery-9-nov)).

**Went deep**

- **Checkout and key delivery.** Selling the same key twice is the worst bug a key marketplace can have, so this got the most care ([D34](docs/DECISIONS.md#d34-checkout-reservation-payment-double-clicks-and-the-cart-11-nov-day-5)).
  - Placing an order reserves keys for 15 minutes in one transaction, with `SELECT … FOR UPDATE SKIP LOCKED`. Two buyers racing for the last key never both get it, and nobody waits for a lock.
  - Payment runs in two short transactions around the charge, so no row lock is held during the call to the payment provider. A `paying` status plus an idempotency key make a double click on Pay charge once.
  - The server re-prices the cart when the order is placed. If the total differs from what the buyer saw, nothing is created (`409 price_changed`), so a buyer is never charged an amount they were not shown.
  - Keys are encrypted in the database and shown only on the buyer's own paid order.
  - Reservations expire without a cron job (Render's free tier has none): stock counts an expired reservation as available, and order statuses are updated when an order is read or paid ([D10](docs/DECISIONS.md#d10-reservation-expiry-15-minutes-hard-expiry-lazy-status-updates-9-nov)).
- **Search.** Typo tolerant and partial names, using PostgreSQL's `pg_trgm` instead of a separate search service. "witcer", "hogwards", "stardw valey" and "balders gate" all find the intended game. The threshold (0.4) was chosen by measuring 30 typo queries against the real catalog, and the ranking puts the intended game above fuzzy matches. The header search has autocomplete; Enter opens the store with the term, so results get filters, sorting and pagination too ([D28](docs/DECISIONS.md#d28-search-pg_trgm-word-similarity-at-04-operator-form-relevance-ranking-10-nov), [D6](docs/DECISIONS.md#d6-search-lives-in-the-store-page-9-nov)).
- **Store.** Platform, region and price filters, five sort orders and pagination. All of it lives in the URL, so Back, reload and shared links show the same page; a malformed URL is repaired instead of showing an error ([D29](docs/DECISIONS.md#d29-store-state-in-the-url-header-autocomplete-10-nov)). The "from" price is the cheapest offer that still has a key to sell, computed on read so it is never stale ([D23](docs/DECISIONS.md#d23-the-stores-from-price-is-computed-on-read-with-indexes-chosen-by-benchmark-9-nov)).
- **Product page.** Every seller's offer with price, discount, rating and stock. Offers in stock come first, then by price; sold-out offers stay visible but dimmed ([D30](docs/DECISIONS.md#d30-product-page-offer-order-and-sold-out-offers-10-nov)).
- **Cart.** Kept in the browser so guests can fill it before signing in. The server quotes current prices and stock for it, the cart says when a price changed or an offer sold out, and checkout is blocked until every line can be bought. The cart is never changed automatically ([D31](docs/DECISIONS.md#d31-cart-the-browser-keeps-the-wish-the-server-quotes-reality-10-nov)).

**Kept simple**

- **Homepage**: a banner and two product carousels (best sellers, deals). The layout itself comes from the backend: `GET /api/home` returns the list of blocks, and each block fetches its own endpoint, backed by its own service class. Nothing about the blocks is hardcoded in the frontend ([D3](docs/DECISIONS.md#d3-homepage-blocks-one-app-one-service-and-endpoint-per-block-9-nov), [D4](docs/DECISIONS.md#d4-homepage-layout-is-server-driven-get-home-returns-the-list-of-blocks-9-nov), [D32](docs/DECISIONS.md#d32-homepage-blocks-content-rules-the-all-time-fill-safe-links-10-nov)).
- **Authentication**: email and password, bearer tokens that expire after 7 days, rate-limited login. No email verification or password reset ([D33](docs/DECISIONS.md#d33-auth-where-the-token-lives-and-the-rules-around-it-10-nov-day-5)).
- **Order history**: paginated list with statuses, each order linking to its keys ([D35](docs/DECISIONS.md#d35-order-history-what-is-listed-and-how-it-stays-current-11-nov-day-6)).
- **Payment**: simulated behind a `PaymentGateway` interface, so a real provider such as Stripe would be a second implementation ([D11](docs/DECISIONS.md#d11-simulated-payment-behind-a-paymentgateway-interface-9-nov)).
- **Styling**: colors, font and sizes measured from eneba.com in a browser and turned into Tailwind theme tokens. No Eneba logo, images or icons are copied; game covers are generated gradients ([D27](docs/DECISIONS.md#d27-theme-tokens-measured-from-enebacom-layout-shell-9-nov), [D21](docs/DECISIONS.md#d21-product-covers-are-generated-by-the-frontend-9-nov-refines-d15)).

The catalog is seeded demo data: 50 real game titles in 105 editions (game + platform + region), 7 platforms, 4 regions and 8 invented sellers.

## How it fits together

```
Browser ──► Vercel (React SPA) ──HTTPS/JSON──► Render (Laravel API, Docker) ──► Neon (PostgreSQL + pg_trgm)
```

- **Frontend** (`frontend/`): React 19, TypeScript, Vite, React Router, TanStack Query for server data, Tailwind CSS. Code is grouped by feature in `src/features/` (home, store, search, product, cart, checkout, auth, orders). Pure logic (URL parsing, cart rules, countdown, card checks) is kept in plain functions with unit tests.
- **Backend** (`backend/`): Laravel 13 on PHP 8.4, JSON only. Each request goes through a thin controller, a `FormRequest` for validation, a service class in `app/Services/` for the business logic, and a `JsonResource` for the response shape. Endpoints are listed in [`docs/PLAN.md`](docs/PLAN.md#rest-endpoints-api).
- **Database**: PostgreSQL. Money is stored as integer cents. Business rules that must never break (positive prices, a sold key always belongs to an order item) are also CHECK constraints ([D22](docs/DECISIONS.md#d22-data-model-refinements-before-the-first-migration-9-nov)).
- **Auth**: Laravel Sanctum bearer tokens. Frontend and API are on different sites, so cookies would be third-party cookies, which some browsers block ([D7](docs/DECISIONS.md#d7-auth-with-sanctum-bearer-tokens-not-cookies-9-nov)). The token is kept in `localStorage`; a Content Security Policy on Vercel limits what an injected script could do ([D33](docs/DECISIONS.md#d33-auth-where-the-token-lives-and-the-rules-around-it-10-nov-day-5)).
- **Deployment**: both sides redeploy on push to `main`. The Docker container runs migrations on every start and seeds the demo data once, inside production, because keys must be encrypted with production's `APP_KEY` ([D19](docs/DECISIONS.md#d19-migrations-run-at-container-start-a-failed-migration-keeps-the-old-version-live-9-nov), [D20](docs/DECISIONS.md#d20-demo-data-is-seeded-inside-the-production-container-once-9-nov)).

## Decisions

Every scope decision and trade-off is in [`docs/DECISIONS.md`](docs/DECISIONS.md) (D1 to D35), each with what it cost. The ones that shaped the project most:

| | Decision |
|---|---|
| [D2](docs/DECISIONS.md#d2-scope-go-deep-on-the-purchase-flow-search-store-filters-and-key-delivery-9-nov) | Scope: depth on the purchase flow, search and store; the rest kept simple. |
| [D4](docs/DECISIONS.md#d4-homepage-layout-is-server-driven-get-home-returns-the-list-of-blocks-9-nov) | The homepage layout is decided by the database, not the frontend. |
| [D9](docs/DECISIONS.md#d9-keys-encrypted-at-rest-two-phase-assignment-reserve-then-pay-9-nov), [D34](docs/DECISIONS.md#d34-checkout-reservation-payment-double-clicks-and-the-cart-11-nov-day-5) | Keys are reserved with `SKIP LOCKED`, then paid for outside any lock; a `paying` status stops double charges. |
| [D10](docs/DECISIONS.md#d10-reservation-expiry-15-minutes-hard-expiry-lazy-status-updates-9-nov) | 15-minute reservations that expire without cron. |
| [D23](docs/DECISIONS.md#d23-the-stores-from-price-is-computed-on-read-with-indexes-chosen-by-benchmark-9-nov) | The store price is computed on read, with indexes chosen by a 50,000-product benchmark. |
| [D28](docs/DECISIONS.md#d28-search-pg_trgm-word-similarity-at-04-operator-form-relevance-ranking-10-nov) | Search with `pg_trgm` word similarity at a measured threshold of 0.4. |
| [D29](docs/DECISIONS.md#d29-store-state-in-the-url-header-autocomplete-10-nov) | The URL is the only copy of the store's state. |
| [D31](docs/DECISIONS.md#d31-cart-the-browser-keeps-the-wish-the-server-quotes-reality-10-nov) | The browser keeps the cart, the server decides prices and stock. |
| [D33](docs/DECISIONS.md#d33-auth-where-the-token-lives-and-the-rules-around-it-10-nov-day-5) | Bearer token in `localStorage`, with what that costs and why a cookie would not remove the risk. |

## Testing and CI

- **Backend**: 287 PHPUnit tests, all against real PostgreSQL (search needs `pg_trgm`, which SQLite does not have). They cover each API's rules: search matching and ranking, filters and sorting, cart statuses, checkout conflicts, expiry, auth and rate limits.
- **Concurrency**: `ConcurrentCheckoutTest` opens a second database session that plays another buyer and holds its transaction open at the critical moment, so the interleaving is deterministic: the last key locked by someone else, a key skipped for the next one, two pays of one order.
- **Race test** (`backend/benchmarks/checkout/race.sh`): starts a real server with 16 workers and sends 30 orders at the same moment for an offer with 3 keys, then 5 simultaneous payments for one order. Re-run on the current code for this README: 50 rounds, every round 3 orders created and 27 refused, no key reserved twice, one charge per order. With the row lock and the reservation's re-check removed, it fails in the first round ([D34](docs/DECISIONS.md#d34-checkout-reservation-payment-double-clicks-and-the-cart-11-nov-day-5)).
- **Mutation checks**: for most features I removed or changed each rule in the code by hand, one at a time, and confirmed a test failed. This found several tests that passed for the wrong reason, which were rewritten. The results per feature are in DECISIONS.md (for example D28, D34, D35).
- **Frontend**: 189 Vitest tests for the pure logic (store URL state, cart rules, checkout errors, countdown, token store). Components and full flows were checked in a real browser with Playwright during each session and on production; those checks are not automated.
- **CI** (GitHub Actions, every push to `main` and every pull request): Pint and the backend tests on PostgreSQL 17, plus a migration round trip; frontend lint, Vitest, type check and build; and a `docker` job that builds the production image and starts it twice against an empty database (the first start must seed 105 products, the second must skip the seed) ([D25](docs/DECISIONS.md#d25-ci-on-github-actions-including-a-production-image-start-9-nov)).
- **Benchmarks** (`backend/benchmarks/catalog/`): rebuild a throwaway database with 50,000 products and 1,000,000 keys and run `EXPLAIN ANALYZE` on the store and search queries.

## Known limitations

- The first request after the API has slept can take up to a minute (free tier).
- Search does not understand abbreviations ("gta" finds nothing), and two typos in short words ("eldn rng") fall below the threshold. Queries of 1 or 2 characters cannot use the trigram index.
- Sorting by price, best sellers and deals compute the "from" price for every candidate product on each request. That is fast at 105 products and measured at about 70 to 140 ms at 50,000 ([D23](docs/DECISIONS.md#d23-the-stores-from-price-is-computed-on-read-with-indexes-chosen-by-benchmark-9-nov), [D32](docs/DECISIONS.md#d32-homepage-blocks-content-rules-the-all-time-fill-safe-links-10-nov)). The scaling path is written down but not built.
- Payment is simulated only; Stripe test mode was not added.
- The cart is per browser and does not follow the user to another device.
- A token stolen through an XSS bug stays usable until it expires or the user signs out. No email verification, password reset or refresh tokens.
- Without cron, expired orders change status only when someone reads or pays them (stock is never affected), and expired token rows are never deleted.
- There is no admin UI. Homepage blocks and banners are edited in the database, and their types and links are checked only by the frontend when it reads them.
- English and EUR only. Pagination is offset based.
- Vercel deploys the frontend on every push to `main`, whether CI passed or not.

## Running it locally

Prerequisites: PHP 8.4 with `pdo_pgsql` and Composer, PostgreSQL 17 (with the `pg_trgm` extension, included in Homebrew's and the official Docker builds), Node 26.

```bash
# Databases: eneba for development, eneba_test for the tests
createdb eneba
createdb eneba_test

# Backend: http://localhost:8000
cd backend
composer install
cp .env.example .env
php artisan key:generate
# Set DB_USERNAME (and DB_PASSWORD) in .env to your PostgreSQL user.
# With Homebrew this is your macOS user name, not "postgres".
php artisan migrate:fresh --seed
php artisan serve

# Frontend: http://localhost:5173 (in another terminal)
cd frontend
npm install
cp .env.example .env.local
npm run dev
```

Checks (the same ones CI runs):

```bash
cd backend && vendor/bin/pint --test && php artisan test     # always uses eneba_test
cd frontend && npm run lint && npm test && npm run build
backend/benchmarks/checkout/race.sh                           # optional: the race test (throwaway database)
```

## How I used AI

I built this with Claude Code, as the brief encourages. The rules I set for it are in [`CLAUDE.md`](CLAUDE.md). The main ones:

- **Plan before code.** For every feature the agent first proposed a plan and asked the questions that mattered; code was written only after I approved or changed it. Many decisions in DECISIONS.md are marked as my choice, question or catch, for example the server-driven homepage (D4), the expiry questions behind D10, the "Intersheba" name (D26), a leaking-setting test that could never fail (D28) and the double-click and last-key questions behind D34.
- **One feature per session, one branch per day**, merged through a pull request with green CI.
- **Explain everything.** After each step the agent explained what the code does and why, so I can answer for every part of it.
- **Check claims instead of trusting them.** Numbers in the docs come from measurements (benchmarks, `EXPLAIN ANALYZE`, the race test), and tests were mutation-checked, so I know which rule each test protects.

Where to read it:

- [`docs/AI_LOG.md`](docs/AI_LOG.md): a short summary of every session, including the problems found and the agent's own mistakes.
- [`ai-logs/`](ai-logs/): the full transcripts (prompts and responses), exported with `/export`, one file per session.
- [`docs/PLAN.md`](docs/PLAN.md) and [`docs/DECISIONS.md`](docs/DECISIONS.md): the plan agreed on day 1 and every decision since.
