# Project plan

Agreed on 9 November 2026. Deadline 16 November, target submission 15 November.
Reasoning behind each choice is in `DECISIONS.md`.

## Architecture

```
Browser ──► Vercel (React SPA) ──HTTPS/JSON──► Render (Laravel API, Docker) ──► Neon (PostgreSQL + pg_trgm)
```

- **Frontend**: React + TypeScript (Vite), React Router, Tailwind CSS, TanStack Query for server state.
  Store filters, sorting, page and search query live in the URL (`useSearchParams`). The cart lives in React context, persisted to `localStorage`.
- **Backend**: Laravel, JSON API only. Layers: Controller (thin) → Service (business logic) → Eloquent model.
  Validation in `FormRequest` classes, response shape in `JsonResource` classes.
- **Auth**: Laravel Sanctum personal access tokens (bearer), because frontend and backend are on different sites.
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
│   │   │   ├── Home/           # HomeLayoutService, BannerService, BestSellersService, DealsService
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
| `orders` | id, user_id, status (`pending`/`paid`/`failed`/`expired`), total_cents, currency, expires_at, payment_reference, paid_at | `expires_at` = creation + 15 minutes |
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

## Checkout and key assignment

1. **`POST /api/orders` (reserve)**, one transaction:
   re-read prices from the database, create a `pending` order (`expires_at = now + 15 min`) and its items, then for each item:
   ```sql
   SELECT id FROM product_keys
   WHERE offer_id = :offer
     AND (status = 'available' OR (status = 'reserved' AND reserved_until < now()))
   ORDER BY id LIMIT :qty
   FOR UPDATE SKIP LOCKED
   ```
   Fewer rows than requested → roll back, `409 Out of stock`. Otherwise mark them `reserved`, set `reserved_until`, link them to the order item.
2. **`POST /api/orders/{id}/pay`**:
   1. Transaction A: lock the order row (`FOR UPDATE`). If already `paid`, return the existing result (idempotent). If `now > expires_at`, release the keys, mark the order `expired`, return `409 Reservation expired`. Otherwise check that all the order's keys are still reserved for it, and extend `reserved_until` by 2 minutes (payment hold) so they cannot be taken while the payment is processed.
   2. Charge via `PaymentGateway` (outside any transaction: never hold row locks during a network call).
   3. Transaction B: success → keys `sold`, order `paid`. Decline → keys released, order `failed`. If the keys are somehow no longer ours, refund and fail (safety net, should not happen thanks to the hold).
3. **Expiry of old pending orders** (no cron on Render free tier):
   - Keys never need cleaning up to stay correct: the reserve query already treats expired reservations as available.
   - Order status is expired lazily: by the pay endpoint (step 2.1), and by a bulk `UPDATE ... SET status = 'expired' WHERE status = 'pending' AND expires_at < now()` (with key release) run before the order history and order detail queries.
   - The same logic is exposed as `php artisan orders:expire` and registered in the Laravel scheduler, so a paid deployment with cron would expire orders proactively.
   - The cart is cleared only after a successful payment, so after an expiry the buyer can check out again in one click.

## REST endpoints (`/api`)

| Method | Path | Auth | Purpose |
|---|---|---|---|
| GET | `/health` | | Health check (also checks the DB connection) |
| POST | `/auth/register`, `/auth/login` | | Returns token + user |
| POST | `/auth/logout` | yes | Revoke token |
| GET | `/auth/me` | yes | Current user |
| GET | `/home` | | Homepage layout (list of blocks) |
| GET | `/home/banners` | | Banner block content |
| GET | `/home/best-sellers` | | Top products by units sold in the last 30 days |
| GET | `/home/deals` | | Top offers by discount % |
| GET | `/products` | | Store and search: `q, platform[], region[], price_min, price_max` (cents), `sort` (`newest`, `price_asc`, `price_desc`, `name`; `relevance` on day 3), `page, per_page` (24, max 60). From price: see D23. |
| GET | `/products/filters` | | Available platforms, regions, price range |
| GET | `/products/{slug}` | | Product with offers, cheapest first |
| GET | `/search/suggestions?q=` | | Autocomplete, 6 to 8 matches |
| POST | `/cart/quote` | | Current prices and stock for `[{offer_id, qty}]` |
| POST | `/orders` | yes | Create pending order, reserve keys |
| POST | `/orders/{id}/pay` | yes | Simulated payment |
| GET | `/orders` | yes | Order history, paginated |
| GET | `/orders/{id}` | yes, owner | Order detail, keys if paid |

Search: a product matches if `name ILIKE '%term%'` (partial) or `term <% name` (trigram word similarity, typos).
Ranking: prefix match first, then similarity score. Both conditions can use the GIN trigram index.

## Day-by-day plan

| Day | Date | Goal |
|---|---|---|
| 1 | Mon 9 Nov | Planning. Local tooling, scaffold backend and frontend, Neon DB, Dockerfile, deploy to Render and Vercel, frontend shows `/api/health` from production. |
| 2 | Tue 10 Nov | CI (GitHub Actions + Postgres service). All migrations and seeders. Catalog API (filters, sorting, pagination) with tests. Tailwind theme tokens from eneba.com, layout shell. |
| 3 | Wed 11 Nov | Search (trigram search, suggestions, tests). Store page with URL state. Header autocomplete. |
| 4 | Thu 12 Nov | Product page with offer comparison. Server-driven homepage (`/home` + 3 blocks). Cart with `/cart/quote`. |
| 5 | Fri 13 Nov | Auth. Checkout: reserve, pay, expire, with tests. |
| 6 | Sat 14 Nov | Order confirmation with key reveal, order history. Styling polish, responsive, error states. Full flow tested on production. |
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
