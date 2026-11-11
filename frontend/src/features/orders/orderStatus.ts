import type { OrderStatus } from '../../api/orders'

/**
 * How an order's status is shown, in one place for the order history and the
 * order page (D34, D35). Pure functions, tested in orderStatus.test.ts.
 */

/** Short badge text for the history. */
export function statusLabel(status: OrderStatus): string {
  switch (status) {
    case 'paid':
      return 'Paid'
    case 'pending':
      return 'Awaiting payment'
    case 'paying':
      return 'Payment in progress'
    case 'failed':
      return 'Payment failed'
    case 'expired':
      return 'Expired'
    case 'cancelled':
      return 'Cancelled'
  }
}

/**
 * Colour of the badge. The label always carries the meaning; the colour only
 * helps scanning (WCAG: never colour alone).
 */
export type StatusTone = 'done' | 'waiting' | 'ended'

export function statusTone(status: OrderStatus): StatusTone {
  if (status === 'paid') return 'done'
  if (awaitsPayment(status)) return 'waiting'
  return 'ended'
}

/** Keys are still reserved and the payment page can be opened. */
export function awaitsPayment(status: OrderStatus): boolean {
  return status === 'pending' || status === 'paying'
}

/** The sentence under the order page's heading. */
export function statusLine(order: { id: number; status: OrderStatus }, justPaid: boolean): string {
  switch (order.status) {
    case 'paid':
      return justPaid ? `Thank you! Order #${order.id} is paid. Your keys are below.` : `Paid. Your keys are below.`
    case 'pending':
    case 'paying':
      return 'Waiting for payment. Your keys are reserved for a few more minutes.'
    case 'failed':
      return 'Not paid. If anything was charged, it was refunded.'
    case 'expired':
      return 'The reservation expired before payment. Nothing was charged.'
    case 'cancelled':
      return 'Replaced by a newer checkout. Nothing was charged.'
  }
}

/** The API's ceiling (OrderIndexRequest::MAX_PAGE); a higher page would be a 422. */
export const ORDERS_MAX_PAGE = 10_000

/**
 * `?page=` of /orders -> a page the API accepts. Anything that is not a whole
 * number from 1 is page 1; a number above the ceiling is the ceiling (which
 * is past the end, so the page says it is empty instead of failing).
 */
export function parseOrdersPage(value: string | null): number {
  if (value === null || !/^\d{1,9}$/.test(value)) return 1
  return Math.min(Math.max(1, Number(value)), ORDERS_MAX_PAGE)
}
