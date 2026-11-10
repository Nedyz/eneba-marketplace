/**
 * The cart's data and the rules for changing it, as pure functions (no React,
 * no localStorage), so they are unit-tested like storeParams.ts.
 *
 * What the browser keeps (D8, D31): which offer and how many. The `seen`
 * snapshot is for display only (before the quote arrives, or when the offer no
 * longer exists) and for noticing price changes. It is never sent to the API
 * and never summed: every price and total on the cart page comes from
 * POST /cart/quote.
 */

/** Same limits as the backend's CartQuoteRequest. */
export const MAX_QTY = 10
export const MAX_LINES = 50

/** Versioned, so a future change of shape can start fresh instead of misreading old data. */
export const CART_STORAGE_KEY = 'intersheba.cart.v1'

/** What the buyer last saw for this offer. */
export interface CartSnapshot {
  productName: string
  productSlug: string
  sellerName: string
  /** The price last shown to the buyer, used only to notice a change. */
  priceCents: number
}

export interface CartLine {
  offerId: number
  qty: number
  seen: CartSnapshot
}

/** Lines in the order they were added; that is the order the cart page shows. */
export type Cart = readonly CartLine[]

/** One line of the POST /cart/quote body. */
export interface QuoteRequestLine {
  offer_id: number
  qty: number
}

// ------------------------------------------------------------------ reading and writing

/**
 * localStorage -> Cart. The stored value is untrusted (old versions, hand
 * edits, another app on the same origin), so, like the store URL (D29), it is
 * repaired instead of rejected: bad lines are dropped, quantities clamped,
 * duplicate offers merged, at most MAX_LINES kept. Never throws.
 */
export function parseCart(raw: string | null): Cart {
  if (raw === null) return []

  let data: unknown
  try {
    data = JSON.parse(raw)
  } catch {
    return []
  }
  if (!Array.isArray(data)) return []

  let cart: Cart = []
  for (const item of data) {
    const line = parseLine(item)
    if (line === null) continue
    const existing = cart.find((l) => l.offerId === line.offerId)
    if (existing) {
      cart = setQty(cart, line.offerId, existing.qty + line.qty)
    } else if (cart.length < MAX_LINES) {
      cart = [...cart, line]
    }
  }
  return cart
}

export function serializeCart(cart: Cart): string {
  return JSON.stringify(cart)
}

function parseLine(item: unknown): CartLine | null {
  if (!isRecord(item) || !isRecord(item.seen)) return null
  const { offerId, qty, seen } = item
  if (!isPositiveInt(offerId) || !isPositiveInt(qty)) return null
  if (typeof seen.productName !== 'string' || typeof seen.productSlug !== 'string') return null
  if (typeof seen.sellerName !== 'string' || !isPositiveInt(seen.priceCents)) return null

  return {
    offerId,
    qty: clampQty(qty),
    seen: {
      productName: seen.productName,
      productSlug: seen.productSlug,
      sellerName: seen.sellerName,
      priceCents: seen.priceCents,
    },
  }
}

// ------------------------------------------------------------------ changes
// Each returns the SAME cart object when nothing changes, so callers (the
// store) can skip writing to localStorage and re-rendering.

export type AddCheck = 'ok' | 'sold-out' | 'all-in-cart' | 'cart-full'

/**
 * Can one more key of this offer go into the cart? `stock` is the offer's
 * sellable keys as last shown on the product page. The cap is
 * min(MAX_QTY, stock): adding more than can be bought would only create a
 * problem line on the cart page.
 */
export function checkAdd(cart: Cart, offerId: number, stock: number): AddCheck {
  if (stock <= 0) return 'sold-out'
  const existing = cart.find((l) => l.offerId === offerId)
  if (existing) return existing.qty >= Math.min(MAX_QTY, stock) ? 'all-in-cart' : 'ok'
  return cart.length >= MAX_LINES ? 'cart-full' : 'ok'
}

/**
 * Adds one key of the offer: a new line at the end, or one more on the
 * existing line. The snapshot is replaced with what the product page shows
 * now, because the buyer has just seen that price.
 */
export function addToCart(cart: Cart, offerId: number, seen: CartSnapshot, stock: number): Cart {
  if (checkAdd(cart, offerId, stock) !== 'ok') return cart
  const existing = cart.find((l) => l.offerId === offerId)
  if (!existing) return [...cart, { offerId, qty: 1, seen }]
  return cart.map((l) => (l.offerId === offerId ? { ...l, qty: l.qty + 1, seen } : l))
}

/**
 * Sets a line's quantity, clamped to 1..MAX_QTY. The stock limit is the cart
 * page's job (it knows the quoted stock); this only keeps the stored value
 * valid for the API.
 */
export function setQty(cart: Cart, offerId: number, qty: number): Cart {
  const next = clampQty(qty)
  const line = cart.find((l) => l.offerId === offerId)
  if (!line || line.qty === next) return cart
  return cart.map((l) => (l.offerId === offerId ? { ...l, qty: next } : l))
}

export function removeLine(cart: Cart, offerId: number): Cart {
  return cart.some((l) => l.offerId === offerId) ? cart.filter((l) => l.offerId !== offerId) : cart
}

/**
 * Records the prices the buyer has now been shown (D31: a price change note
 * appears once per detection). Offers missing from `prices` are left alone.
 */
export function markPricesSeen(cart: Cart, prices: ReadonlyMap<number, number>): Cart {
  if (!cart.some((l) => prices.has(l.offerId) && prices.get(l.offerId) !== l.seen.priceCents)) return cart
  return cart.map((l) => {
    const price = prices.get(l.offerId)
    return price === undefined || price === l.seen.priceCents ? l : { ...l, seen: { ...l.seen, priceCents: price } }
  })
}

// ------------------------------------------------------------------ derived values

/** Number of keys in the cart (the header badge). */
export function cartCount(cart: Cart): number {
  return cart.reduce((sum, l) => sum + l.qty, 0)
}

/**
 * The quote request: only offer ids and quantities, sorted by offer id. It is
 * also the TanStack Query key, so the same contents in a different order (or
 * with a different snapshot) share one cache entry and do not refetch. The
 * cart page therefore matches quote lines by offer_id, never by position.
 */
export function quoteRequestLines(cart: Cart): QuoteRequestLine[] {
  return cart.map((l) => ({ offer_id: l.offerId, qty: l.qty })).sort((a, b) => a.offer_id - b.offer_id)
}

// ------------------------------------------------------------------ helpers

function clampQty(qty: number): number {
  return Math.min(MAX_QTY, Math.max(1, Math.trunc(qty)))
}

function isPositiveInt(value: unknown): value is number {
  return typeof value === 'number' && Number.isSafeInteger(value) && value > 0
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}
