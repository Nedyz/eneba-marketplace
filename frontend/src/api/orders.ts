import type { QuoteRequestLine } from '../features/cart/cartStorage'
import { apiGet, apiPost } from './client'

/** Backend OrderStatus (D34). */
export type OrderStatus = 'pending' | 'paying' | 'paid' | 'failed' | 'expired' | 'cancelled'

/** Mirrors backend OrderResource's items. Names and price are as they were when ordered. */
export interface OrderItem {
  offer_id: number
  product_name: string
  seller_name: string
  product_slug: string
  platform: string
  region: string
  quantity: number
  unit_price_cents: number
  line_total_cents: number
  /** The bought keys, only once the order is paid; null before. */
  keys: string[] | null
}

/** Mirrors backend OrderResource. */
export interface Order {
  id: number
  status: OrderStatus
  total_cents: number
  currency: 'EUR'
  created_at: string
  /** Only while keys are held (pending, paying). */
  expires_at: string | null
  /**
   * Seconds until the reservation ends, computed by the server when it
   * answered, so the timer does not depend on this computer's clock.
   */
  seconds_left: number | null
  paid_at: string | null
  items: OrderItem[]
}

export interface CardInput {
  card_number: string
  card_expiry: string
  card_cvc: string
}

/** POST /orders: reserves the keys. 409 out_of_stock / price_changed carry a fresh quote. */
export function placeOrder(lines: QuoteRequestLine[], expectedTotalCents: number): Promise<Order> {
  return apiPost<{ data: Order }>('/orders', { lines, expected_total_cents: expectedTotalCents }).then((r) => r.data)
}

export function getOrder(id: number, signal?: AbortSignal): Promise<Order> {
  return apiGet<{ data: Order }>(`/orders/${id}`, { signal }).then((r) => r.data)
}

/** 200 paid; 402 card_declined and 409 conflicts carry the order in its new state. */
export function payOrder(id: number, card: CardInput): Promise<Order> {
  return apiPost<{ data: Order }>(`/orders/${id}/pay`, card).then((r) => r.data)
}

export const orderQueryKeys = {
  /** Under ['orders']: sign-out removes every order (and its keys) from the cache (D33). */
  detail: (id: number) => ['orders', id] as const,
}
