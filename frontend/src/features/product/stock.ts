/** At or below this many keys, the exact count is shown ("Only 3 left"). */
export const LOW_STOCK_THRESHOLD = 5

export type StockStatus = { kind: 'sold-out' } | { kind: 'low'; left: number } | { kind: 'in-stock' }

/**
 * The offer's stock (count of sellable keys from the API) -> what the row says.
 * Above the threshold the exact number is hidden: "In stock" is all the buyer
 * needs, and large counts would only add noise to the comparison.
 */
export function stockStatus(stock: number): StockStatus {
  if (stock <= 0) return { kind: 'sold-out' }
  if (stock <= LOW_STOCK_THRESHOLD) return { kind: 'low', left: stock }
  return { kind: 'in-stock' }
}

export function stockLabel(status: StockStatus): string {
  switch (status.kind) {
    case 'sold-out':
      return 'Out of stock'
    case 'low':
      return status.left === 1 ? 'Only 1 left' : `Only ${status.left} left`
    case 'in-stock':
      return 'In stock'
  }
}
