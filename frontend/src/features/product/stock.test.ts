import { describe, expect, it } from 'vitest'
import { LOW_STOCK_THRESHOLD, stockLabel, stockStatus } from './stock'

describe('stockStatus and stockLabel', () => {
  it.each([
    [0, 'Out of stock'],
    [1, 'Only 1 left'],
    [3, 'Only 3 left'],
    [LOW_STOCK_THRESHOLD, `Only ${LOW_STOCK_THRESHOLD} left`],
    [LOW_STOCK_THRESHOLD + 1, 'In stock'],
    [250, 'In stock'],
  ])('stock %i -> %s', (stock, label) => {
    expect(stockLabel(stockStatus(stock))).toBe(label)
  })

  it('treats a negative count as sold out', () => {
    // Cannot come from the API (a COUNT), but a sold-out row must never offer stock.
    expect(stockStatus(-1)).toEqual({ kind: 'sold-out' })
  })
})
