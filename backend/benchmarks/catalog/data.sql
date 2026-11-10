-- Benchmark catalog for DECISIONS.md D23 (store listing) and D28 (search).
-- Loaded by build.sh into a freshly migrated eneba_bench database. Deterministic
-- (setseed), so every run builds the same rows.
--
--   50,000 products   synthetic 2-4 word titles from a 70-word vocabulary
--                     ("Dragon Ring Legacy 4"), 5 platforms, 4 regions, 5% undated
--  200,000 offers     4 per product, 2 to 80 EUR, 30% deals, 5% inactive
-- 1,000,000 keys      5 per offer: offers with id % 10 = 0 are sold out (5 sold);
--                     the others have 0-2 sold keys, and every 7th offer has one
--                     reservation that expired (counts as sellable, D10)
--
-- Sold and reserved keys must point to an order item (CHECK constraint), so there
-- is one bench user with one paid order holding one item per offer.

\set ON_ERROR_STOP on
SELECT setseed(0.42);

INSERT INTO platforms (name, slug, created_at, updated_at)
SELECT n, lower(n), now(), now() FROM unnest(ARRAY['Steam', 'PlayStation', 'Xbox', 'Nintendo', 'EA']) AS n;
INSERT INTO regions (name, slug, created_at, updated_at)
SELECT n, replace(lower(n), ' ', '-'), now(), now() FROM unnest(ARRAY['Global', 'Europe', 'North America', 'Asia']) AS n;
INSERT INTO sellers (name, rating, created_at, updated_at)
SELECT 'Seller ' || g, 4.5, now(), now() FROM generate_series(1, 20) AS g;

WITH vocab AS (SELECT ARRAY[
  'shadow','dark','legend','star','war','dragon','knight','city','racing','galaxy',
  'souls','hunter','empire','kingdom','tales','ring','storm','iron','blood','fire',
  'wild','space','dead','night','crown','quest','frontier','titan','rogue','ghost',
  'alien','zombie','ninja','pirate','mech','sky','ocean','forest','desert','winter',
  'legacy','origins','chronicles','saga','rising','fallen','eternal','lost','hidden','broken',
  'silent','crimson','golden','cyber','neon','pixel','farm','craft','builder','simulator',
  'harbor','ember','vortex','spectre','orbit','raven','summit','thunder','venom','glacier'
]::text[] AS w)
INSERT INTO products (slug, name, description, platform_id, region_id, release_date, created_at, updated_at)
SELECT 'bench-' || g,
       initcap(w[1 + floor(random() * 70)::int] || ' ' || w[1 + floor(random() * 70)::int]
               || CASE WHEN random() < 0.4 THEN ' ' || w[1 + floor(random() * 70)::int] ELSE '' END
               || ' ' || (1 + floor(random() * 9)::int)),
       '', 1 + g % 5, 1 + g % 4,
       CASE WHEN g % 20 = 0 THEN NULL ELSE date '2005-01-01' + (g % 7000) END,
       now(), now()
FROM vocab, generate_series(1, 50000) AS g;

INSERT INTO offers (product_id, seller_id, price_cents, original_price_cents, is_active, created_at, updated_at)
SELECT p.id, s, x.price, CASE WHEN random() < 0.3 THEN x.price + 500 END, random() > 0.05, now(), now()
FROM products AS p
CROSS JOIN generate_series(1, 4) AS s
CROSS JOIN LATERAL (SELECT 200 + floor(random() * 7800)::int + 0 * p.id + 0 * s AS price) AS x;

INSERT INTO users (name, email, password, created_at, updated_at)
VALUES ('Bench', 'bench@example.com', 'not-a-real-hash', now(), now());
INSERT INTO orders (user_id, status, total_cents, currency, expires_at, paid_at, created_at, updated_at)
SELECT id, 'paid', 0, 'EUR', now(), now(), now(), now() FROM users;
INSERT INTO order_items (order_id, offer_id, quantity, unit_price_cents, product_name, seller_name, created_at, updated_at)
SELECT (SELECT id FROM orders), o.id, 1, o.price_cents, 'bench', 'bench', now(), now() FROM offers AS o;

INSERT INTO product_keys (offer_id, code, status, reserved_until, order_item_id, sold_at, created_at, updated_at)
SELECT o.id, 'bench', st.status,
       CASE WHEN st.status = 'reserved' THEN now() - interval '1 hour' END,
       CASE WHEN st.status <> 'available' THEN oi.id END,
       CASE WHEN st.status = 'sold' THEN now() END,
       now(), now()
FROM offers AS o
JOIN order_items AS oi ON oi.offer_id = o.id
CROSS JOIN generate_series(1, 5) AS k
CROSS JOIN LATERAL (SELECT CASE
    WHEN o.id % 10 = 0 THEN 'sold'
    WHEN k <= o.id % 3 THEN 'sold'
    WHEN k = 5 AND o.id % 7 = 0 THEN 'reserved'
    ELSE 'available' END AS status) AS st;
