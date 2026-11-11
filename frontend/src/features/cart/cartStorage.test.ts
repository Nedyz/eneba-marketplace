import { describe, expect, it } from 'vitest'
import {
  addToCart,
  type Cart,
  type CartLine,
  cartCount,
  type CartSnapshot,
  checkAdd,
  MAX_LINES,
  markPricesSeen,
  parseCart,
  quoteRequestLines,
  removeLine,
  removeOffers,
  serializeCart,
  setQty,
} from './cartStorage'

const seen = (priceCents = 1999, productName = 'Elden Ring'): CartSnapshot => ({
  productName,
  productSlug: 'elden-ring-steam-global',
  sellerName: 'KeyHub',
  priceCents,
})
const line = (offerId: number, qty = 1, priceCents = 1999): CartLine => ({ offerId, qty, seen: seen(priceCents) })
const stored = (value: unknown) => JSON.stringify(value)

describe('parseCart', () => {
  it('reads what serializeCart wrote', () => {
    const cart: Cart = [line(7, 2), line(3, 1, 500)]
    expect(parseCart(serializeCart(cart))).toEqual(cart)
  })

  it.each([
    ['nothing stored', null],
    ['invalid JSON', '{not json'],
    ['an object', stored({ offerId: 1 })],
    ['a number', '42'],
    ['JSON null', 'null'],
  ])('starts empty for %s', (_, raw) => {
    expect(parseCart(raw)).toEqual([])
  })

  // Each bad line is dropped on its own; the good line next to it survives.
  it.each([
    ['not an object', 5],
    ['missing snapshot', { offerId: 1, qty: 1 }],
    ['offer id as a string', { offerId: '1', qty: 1, seen: seen() }],
    ['offer id zero', { offerId: 0, qty: 1, seen: seen() }],
    ['offer id not whole', { offerId: 1.5, qty: 1, seen: seen() }],
    ['qty zero', { offerId: 1, qty: 0, seen: seen() }],
    ['qty negative', { offerId: 1, qty: -3, seen: seen() }],
    ['qty as a string', { offerId: 1, qty: '2', seen: seen() }],
    ['snapshot price missing', { offerId: 1, qty: 1, seen: { ...seen(), priceCents: undefined } }],
    ['snapshot name not a string', { offerId: 1, qty: 1, seen: { ...seen(), productName: 5 } }],
  ])('drops a line with %s', (_, bad) => {
    expect(parseCart(stored([bad, line(9)]))).toEqual([line(9)])
  })

  it('clamps a quantity above the maximum', () => {
    expect(parseCart(stored([line(1, 50)]))).toEqual([line(1, 10)])
  })

  it('merges duplicate offers into the first line, capped', () => {
    expect(parseCart(stored([line(1, 3), line(2), line(1, 4)]))).toEqual([line(1, 7), line(2)])
    expect(parseCart(stored([line(1, 8), line(1, 8)]))).toEqual([line(1, 10)])
  })

  it(`keeps at most ${MAX_LINES} lines`, () => {
    const many = Array.from({ length: MAX_LINES + 5 }, (_, i) => line(i + 1))
    const cart = parseCart(stored(many))
    expect(cart).toHaveLength(MAX_LINES)
    expect(cart.at(-1)?.offerId).toBe(MAX_LINES)
  })

  it('drops unknown fields', () => {
    expect(parseCart(stored([{ ...line(1), price: 1, seen: { ...seen(), extra: true } }]))).toEqual([line(1)])
  })
})

describe('checkAdd and addToCart', () => {
  it('adds a new offer at the end with quantity 1', () => {
    expect(addToCart([line(5)], 2, seen(), 3)).toEqual([line(5), line(2)])
  })

  it('adds one more to an existing line and refreshes its snapshot', () => {
    // The buyer is looking at the new price on the product page right now.
    expect(addToCart([line(2, 1, 1999)], 2, seen(2499), 5)).toEqual([line(2, 2, 2499)])
  })

  it('refuses a sold-out offer', () => {
    const cart = [line(5)]
    expect(checkAdd(cart, 2, 0)).toBe('sold-out')
    expect(addToCart(cart, 2, seen(), 0)).toBe(cart)
  })

  it('stops at the stock', () => {
    const cart = [line(2, 3)]
    expect(checkAdd(cart, 2, 3)).toBe('all-in-cart')
    expect(addToCart(cart, 2, seen(), 3)).toBe(cart)
    expect(checkAdd(cart, 2, 4)).toBe('ok')
  })

  it('stops at 10 even with more stock', () => {
    expect(checkAdd([line(2, 10)], 2, 500)).toBe('all-in-cart')
  })

  it(`refuses a new line when the cart has ${MAX_LINES} lines, but not more of an existing one`, () => {
    const full = Array.from({ length: MAX_LINES }, (_, i) => line(i + 1))
    expect(checkAdd(full, 999, 5)).toBe('cart-full')
    expect(checkAdd(full, 1, 5)).toBe('ok')
  })
})

describe('setQty and removeLine', () => {
  it('sets the quantity, clamped to 1..10', () => {
    expect(setQty([line(1, 2)], 1, 4)).toEqual([line(1, 4)])
    expect(setQty([line(1, 2)], 1, 0)).toEqual([line(1, 1)])
    expect(setQty([line(1, 2)], 1, 99)).toEqual([line(1, 10)])
  })

  it('returns the same cart when nothing changes', () => {
    const cart = [line(1, 2)]
    expect(setQty(cart, 1, 2)).toBe(cart)
    expect(setQty(cart, 404, 3)).toBe(cart)
    expect(removeLine(cart, 404)).toBe(cart)
  })

  it('removes a line and keeps the order of the others', () => {
    expect(removeLine([line(3), line(1), line(2)], 1)).toEqual([line(3), line(2)])
  })
})

describe('markPricesSeen', () => {
  it('updates only the snapshot prices that differ', () => {
    const cart = [line(1, 1, 1000), line(2, 1, 2000)]
    expect(markPricesSeen(cart, new Map([[1, 1200], [2, 2000]]))).toEqual([line(1, 1, 1200), line(2, 1, 2000)])
  })

  it('returns the same cart when every price is already seen or unknown', () => {
    const cart = [line(1, 1, 1000)]
    expect(markPricesSeen(cart, new Map([[1, 1000], [9, 5]]))).toBe(cart)
  })
})

describe('cartCount and quoteRequestLines', () => {
  it('counts keys, not lines', () => {
    expect(cartCount([line(1, 3), line(2, 2)])).toBe(5)
    expect(cartCount([])).toBe(0)
  })

  it('sends only offer ids and quantities, sorted by offer id', () => {
    expect(quoteRequestLines([line(9, 2), line(3, 1)])).toEqual([
      { offer_id: 3, qty: 1 },
      { offer_id: 9, qty: 2 },
    ])
  })

  it('gives the same request for the same contents in another order or with another snapshot', () => {
    expect(quoteRequestLines([line(1), line(2, 1, 5)])).toEqual(quoteRequestLines([line(2), line(1, 1, 7)]))
  })
})

describe('removeOffers', () => {
  it('removes the ordered offers whatever their quantity, and keeps the rest', () => {
    const cart: Cart = [line(1, 2), line(2), line(3, 4)]

    expect(removeOffers(cart, new Set([1, 3]))).toEqual([line(2)])
  })

  it('keeps a line added after the order was placed', () => {
    expect(removeOffers([line(1), line(9)], new Set([1]))).toEqual([line(9)])
  })

  it('returns the same cart when nothing matches (no storage write, no re-render)', () => {
    const cart: Cart = [line(1)]

    expect(removeOffers(cart, new Set([5]))).toBe(cart)
  })
})
