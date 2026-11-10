import type { QuoteRequestLine } from '../features/cart/cartStorage'
import type { FilterOption } from './catalog'
import { apiPost } from './client'

/** Backend CartLineStatus (D31). Only `available` lines count in the total. */
export type CartLineStatus = 'available' | 'insufficient_stock' | 'sold_out' | 'unavailable'

/** Mirrors backend CartQuoteLineResource. Every field is present; null when unknown. */
export interface QuoteLine {
  offer_id: number
  qty: number
  status: CartLineStatus
  /** Sellable keys right now; 0 for sold-out and unavailable lines. */
  stock: number
  /** null for unavailable lines: a withdrawn or deleted offer has no price. */
  unit_price_cents: number | null
  original_price_cents: number | null
  discount_percent: number | null
  /** Only for available lines. */
  line_total_cents: number | null
  /** null when the offer no longer exists. */
  product: {
    slug: string
    name: string
    image_url: string | null
    platform: FilterOption
    region: FilterOption
  } | null
  seller: { id: number; name: string; rating: number } | null
}

/** Mirrors backend CartQuoteResource. */
export interface CartQuote {
  /** In the request's order, which is sorted by offer id: match by offer_id, not position. */
  lines: QuoteLine[]
  /** Sum of the available lines. */
  total_cents: number
  currency: 'EUR'
  /** True only when every line is available in full. */
  can_checkout: boolean
}

/** Sends only offer ids and quantities; prices always come back from the server. */
export function quoteCart(lines: QuoteRequestLine[], signal?: AbortSignal): Promise<CartQuote> {
  return apiPost<{ data: CartQuote }>('/cart/quote', { lines }, { signal }).then((response) => response.data)
}

export const cartQueryKeys = {
  /** Sorted [offer id, qty] pairs only: the snapshot never changes the key. */
  quote: (lines: QuoteRequestLine[]) => ['cart', 'quote', lines] as const,
}
