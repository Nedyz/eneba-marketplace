# Project plan

Agreed on 9 November 2026. Deadline 16 November, target submission 15 November.
Reasoning behind each choice is in `DECISIONS.md`.

## Architecture

```
Browser ──► Vercel (React SPA) ──HTTPS/JSON──► Render (Laravel API, Docker) ──► Neon (PostgreSQL + pg_trgm)
```

- **Frontend**: React + TypeScript (Vite), React Router, Tailwind CSS, TanStack Query for server state.
  Store filters, sorting, page and search query live in the URL (`useSearchParams`). The cart lives in `localStorage`, read through a small store with `useSyncExternalStore` (D31).
- **Backend**: Laravel, JSON API only. Layers: Controller (thin) → Service (business logic) → Eloquent model.
  Validation in `FormRequest` classes, response shape in `JsonResource` classes.
- **Auth**: Laravel Sanctum personal access tokens (bearer), because frontend and backend are on different sites. The token is kept in `localStorage`; a CSP on Vercel (day 6, after a preview check) will limit what an XSS bug could do with it (D33).
- **Database**: PostgreSQL on Neon. Local development and tests use a local PostgreSQL (Homebrew), because `pg_trgm` does not exist in SQLite.

## Folder structure

```
eneba-marketplace/
├── backend/
│   ├── app/
│   │   ├── Http/Controllers/Api/
│   │   ├── Http/Requests/
│   │   ├── Http/Resources/
│   │   ├── Models/
│   │   ├── Services/
│   │   │   ├── Catalog/        # ProductQuery, SearchService
│   │   │   ├── Home/           # HomeLayout, HomeBanners, BestSellers, Deals
│   │   │   └── Checkout/       # CheckoutService (reserve, pay, expire)
│   │   └── Payments/           # PaymentGateway interface, FakePaymentGateway
│   ├── database/{migrations,seeders,factories}/
│   ├── routes/api.php
│   ├── tests/{Feature,Unit}/
│   ├── Dockerfile
│   └── .env.example
├── frontend/
│   └── src/
│       ├── api/                # typed fetch client, one module per resource
│       ├── components/{ui,layout}/
│       ├── features/           # home, store, search, product, cart, checkout, auth, orders
│       ├── routes/             # router, RequireAuth guard
│       └── types/
├── docs/                       # ASSIGNMENT, PLAN, DECISIONS, AI_LOG
├── ai-logs/                    # exported full transcripts
└── .github/workflows/ci.yml    # day 2
```

## Data model

Money is stored as integer cents. All tables have `created_at` / `updated_at`.
Status columns are `varchar` + CHECK constraint (Laravel `enum()` on PostgreSQL), and business rules are also CHECK constraints (prices > 0, a deal is cheaper than its original price, a sold or reserved key always has an order item). Refinements made on 9 Nov (day 2 start) are in `DECISIONS.md` D22.

| Table | Key fields | Notes |
|---|---|---|
| `users` | id, name, email (unique), password | Laravel default |
| `personal_access_tokens` | | Sanctum |
| `platforms` | id, name, slug | Steam, PlayStation, Xbox, Nintendo, EA app... |
| `regions` | id, slug (unique), name | global, europe, north-america... Same shape as platforms, so both are URL filters by slug. |
| `products` | id, slug (unique), name, description, image_url (nullable), platform_id, region_id, release_date | One product = one edition (game + platform + region). `name` is the game title only. GIN trigram index on `name` (pg_trgm is case-insensitive). |
| `sellers` | id, name, rating | Seeded, no seller accounts |
| `offers` | id, product_id, seller_id, price_cents, original_price_cents (nullable), is_active | unique(product_id, seller_id). A deal = `original_price_cents > price_cents`. |
| `product_keys` | id, offer_id, code (encrypted), status (`available`/`reserved`/`sold`), reserved_until, order_item_id (nullable), sold_at | Index (offer_id, status). Stock is derived by counting available keys (including reservations past `reserved_until`). Migrated after `order_items`, which it references. |
| `orders` | id, user_id, status (`pending`/`paying`/`paid`/`failed`/`expired`/`cancelled`, D34), total_cents, currency, expires_at, payment_reference, paid_at | `expires_at` = creation + 15 minutes, extended by the payment hold; always equal to the keys' `reserved_until` |
| `order_items` | id, order_id, offer_id, quantity, unit_price_cents, product_name, seller_name | Price and names are copied at purchase time |
| `home_blocks` | id, type, title, endpoint, position, is_active | Homepage layout, see below |
| `banners` | id, title, subtitle, image_url, cta_label, link_url, position, is_active, starts_at, ends_at | Content of the banner block |

## Homepage: server-driven layout

`GET /api/home` returns the ordered list of active blocks:

```json
{ "blocks": [
  { "id": 1, "type": "banner",           "title": null,           "endpoint": "/home/banners" },
  { "id": 2, "type": "product_carousel", "title": "Best sellers", "endpoint": "/home/best-sellers" },
  { "id": 3, "type": "product_carousel", "title": "Deals",        "endpoint": "/home/deals" }
]}
```

- The frontend has a registry `type → component` (`banner → BannerBlock`, `product_carousel → ProductCarouselBlock`). Each block component fetches its own `endpoint` and renders what it receives.
- Unknown block types are skipped, so an old frontend never crashes on a new block type.
- Which blocks appear, their order and their titles are all decided in the database. A new block ("New releases") with an existing type needs only a `home_blocks` row and a backend service, with no frontend change.
- Endpoints are stored as paths relative to the API base, and the frontend only requests them under the API base URL, so a bad database row cannot make the browser call another host.
- Contract per type: a `banner` endpoint returns `{data: Banner[]}`, a `product_carousel` endpoint returns `{data: ProductSummary[]}` (the store card, `ProductListResource`). Banner links are frontend routes in the store's URL format (`/store?platform=steam`); a banner whose link is not a same-site path is not shown. Details: D32.

## Checkout and key assignment

1. **`POST /api/orders` (reserve)**, one transaction:
   lock the buyer's `users` row (one checkout at a time per user), cancel their previous `pending` order and release its keys (one pending order per user, D34), re-price the cart with `CartQuoter` (`409 out_of_stock` if a line is not available, `409 price_changed` if the total differs from `expected_total_cents`), create a `pending` order (`expires_at = now + 15 min`) and its items, then for each item:
   ```sql
   SELECT id FROM product_keys
   WHERE offer_id = :offer
     AND (status = 'available' OR (status = 'reserved' AND reserved_until < now()))
   ORDER BY id LIMIT :qty
   FOR UPDATE SKIP LOCKED
   ```
   Fewer rows than requested → roll back, `409 out_of_stock` with a fresh quote. Otherwise mark them `reserved` (the `UPDATE` re-checks the sellable condition and its row count), set `reserved_until`, link them to the order item. Any 409 changes nothing.
2. **`POST /api/orders/{id}/pay`**:
   1. Transaction A: lock the order row (`FOR UPDATE`, waiting). `paid` → return it again (idempotent, no charge). `paying` → `409 payment_in_progress`. `failed`/`expired`/`cancelled` → `409 order_not_payable`. `now > expires_at` → release the keys, mark it `expired`, `409 reservation_expired`. Otherwise check that all the order's keys are still reserved for it, set the order to **`paying`** and extend the hold to `max(expires_at, now + 2 min)` on the order and its keys (D34).
   2. Charge via `PaymentGateway` with the order as idempotency key (outside any transaction: never hold row locks during a network call). A gateway exception puts the order back to `pending`; a retry reuses the key.
   3. Transaction B: success → keys `sold`, order `paid`. Decline → keys released, order `failed`, `402 card_declined`. Keys no longer ours, or the order no longer `paying` (failed by lazy expiry during a very slow charge) → refund and fail.
3. **Expiry of old pending orders** (no cron on Render free tier):
   - Keys never need cleaning up to stay correct: the reserve query already treats expired reservations as available.
   - Order status is expired lazily (`OrderExpirer`): by the pay endpoint (step 2.1), by `GET /orders/{id}`, and before the order history is listed (`GET /orders`, skipping an order a pay request has locked, D35). `pending` past `expires_at` → `expired`; `paying` past its hold → `failed` (an interrupted payment). Keys still linked to the order are released.
   - The same logic is exposed as `php artisan orders:expire` and registered in the Laravel scheduler, so a paid deployment with cron would expire orders proactively.
   - The cart is changed only after a successful payment, and then only the ordered offers are removed, so after a decline or an expiry the buyer can check out again in one click (D34).

## REST endpoints (`/api`)

| Method | Path | Auth | Purpose |
|---|---|---|---|
| GET | `/health` | | Health check (also checks the DB connection) |
| POST | `/auth/register`, `/auth/login` | | `{data: {token, user}}` (201 / 200). Rate limited (D33) |
| POST | `/auth/logout` | yes | Delete the token used for the request (204) |
| GET | `/auth/me` | yes | Current user |
| GET | `/home` | | Homepage layout (list of blocks) |
| GET | `/home/banners` | | Active banners inside their `[starts_at, ends_at)` window, by position |
| GET | `/home/best-sellers` | | 12 in-stock products by units sold in the last 30 days, remaining places filled by all-time units (D32) |
| GET | `/home/deals` | | 12 products whose card price (from-price offer) is discounted, largest discount first (D32) |
| GET | `/products` | | Store and search: `q, platform[], region[], price_min, price_max` (cents), `sort` (`newest`, `price_asc`, `price_desc`, `name`, `relevance`; default `relevance` when `q` is set, see D28), `page, per_page` (24, max 60). From price: see D23. |
| GET | `/products/filters` | | Available platforms, regions, price range |
| GET | `/products/{slug}` | | Product with its active offers: in stock first, then price, then offer id (D30) |
| GET | `/search/suggestions?q=` | | Autocomplete: up to 8 editions, same match and ranking as the store, `q` 2 to 100 characters |
| POST | `/cart/quote` | | Current prices, stock and per-line status (`available`, `insufficient_stock`, `sold_out`, `unavailable`) for `[{offer_id, qty}]` (1 to 50 lines, qty 1 to 10), the total of the available lines and `can_checkout` (D31) |
| POST | `/orders` | yes | Create pending order, reserve keys. Body includes `expected_total_cents`; a different recalculated total → `409 price_changed` (D31) |
| POST | `/orders/{id}/pay` | yes, owner | Simulated payment: test card fields; 200 paid (with keys), 402 `card_declined`, 409 conflicts (D34) |
| GET | `/orders` | yes | Order history: newest first (id breaks ties), 10 per page (`page` 1 to 10000), cancelled orders left out in the query, no keys. Lazy expiry first (D35) |
| GET | `/orders/{id}` | yes, owner | Order detail, keys if paid; 404 for anyone else; `Cache-Control: no-store` (built day 5) |

Search: a product matches if `name ILIKE '%term%'` (partial) or `term <% name` (trigram word similarity at threshold 0.4, typos).
Ranking: prefix match first, then `word_similarity`, then `similarity`, then name and id. Both conditions use the GIN trigram index. Details and measurements: D28.

## Day-by-day plan

| Day | Date | Goal |
|---|---|---|
| 1 | Mon 9 Nov | Planning. Local tooling, scaffold backend and frontend, Neon DB, Dockerfile, deploy to Render and Vercel, frontend shows `/api/health` from production. |
| 2 | Tue 10 Nov | CI (GitHub Actions + Postgres service). All migrations and seeders. Catalog API (filters, sorting, pagination) with tests. Tailwind theme tokens from eneba.com, layout shell. |
| 3 | Wed 11 Nov | Search (trigram search, suggestions, tests). Store page with URL state. Header autocomplete. |
| 4 | Thu 12 Nov | Product page with offer comparison. Server-driven homepage (`/home` + 3 blocks). Cart with `/cart/quote`. |
| 5 | Fri 13 Nov | Auth. Checkout: reserve, pay, expire, with tests. Payment page with timer, success page with the keys. |
| 6 | Sat 14 Nov | Order history (order detail and key reveal done on day 5). Content Security Policy back in, checked on a Vercel preview (D33). Styling polish, responsive, error states. Full flow tested on production. |
| 7 | Sun 15 Nov | Buffer. README, export AI logs, final checks, submit. |
| | Mon 16 Nov | Reserve only. |

## Risks

1. Deployment surprises: deploy on day 1, redeploy after every feature. Use Neon's direct (non-pooled) connection string with `sslmode=require`.
2. Render free tier sleeps after 15 minutes idle (30 to 60 s cold start): frontend "waking up the server" state, README note, optional uptime ping on `/health`.
3. `pg_trgm` tests need real PostgreSQL: local Homebrew PostgreSQL, Postgres service container in CI.
4. Selling the same key twice: transactions, `SKIP LOCKED`, payment hold, derived stock, dedicated tests.
5. Styling as a time sink: theme tokens on day 2, polish timeboxed to day 6.
6. No local Docker: Dockerfile errors only show up in Render builds (slower feedback). If more than two builds fail, install OrbStack to build locally.
7. Explainability: small commits, explanation after each step, decisions logged.
8. Secrets: only in `.env` and the Render/Vercel dashboards. `APP_KEY` must never change in production, because product keys are encrypted with it.
