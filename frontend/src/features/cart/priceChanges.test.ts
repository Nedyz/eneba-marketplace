import { describe, expect, it } from 'vitest'
import type { CartLine } from './cartStorage'
import { detectPriceChanges, mergePriceChanges, type PriceChanges } from './priceChanges'

const line = (offerId: number, priceCents: number): CartLine => ({
  offerId,
  qty: 1,
  seen: { productName: 'Game', productSlug: 'game', sellerName: 'Seller', priceCents },
})
const quoted = (offer_id: number, unit_price_cents: number | null) => ({ offer_id, unit_price_cents })

describe('detectPriceChanges', () => {
  it('finds rises and drops, matched by offer id whatever the order', () => {
    const cart = [line(5, 1000), line(2, 3000), line(8, 500)]
    // Quote order (sorted by id) differs from cart order on purpose.
    const changes = detectPriceChanges(cart, [quoted(2, 2500), quoted(5, 1200), quoted(8, 500)])

    expect(changes).toEqual(
      new Map([
        [5, { fromCents: 1000, toCents: 1200 }],
        [2, { fromCents: 3000, toCents: 2500 }],
      ]),
    )
  })

  it('ignores lines without a quoted price and offers missing from the quote', () => {
    expect(detectPriceChanges([line(1, 1000), line(2, 1000)], [quoted(1, null)]).size).toBe(0)
  })
})

describe('mergePriceChanges', () => {
  const shown: PriceChanges = new Map([[1, { fromCents: 2000, toCents: 2200 }]])

  it('keeps the first price seen across several changes', () => {
    // The snapshot became 2200 after the first note; now 2200 -> 2500.
    expect(mergePriceChanges(shown, new Map([[1, { fromCents: 2200, toCents: 2500 }]]))).toEqual(
      new Map([[1, { fromCents: 2000, toCents: 2500 }]]),
    )
  })

  it('removes the note when the price returns to where it started', () => {
    expect(mergePriceChanges(shown, new Map([[1, { fromCents: 2200, toCents: 2000 }]])).size).toBe(0)
  })

  it('adds new offers and keeps existing notes', () => {
    expect(mergePriceChanges(shown, new Map([[7, { fromCents: 100, toCents: 90 }]]))).toEqual(
      new Map([
        [1, { fromCents: 2000, toCents: 2200 }],
        [7, { fromCents: 100, toCents: 90 }],
      ]),
    )
  })

  it('returns the same map when nothing was detected', () => {
    expect(mergePriceChanges(shown, new Map())).toBe(shown)
  })
})
