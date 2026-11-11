import type { CartQuote } from '../../api/cart'
import { ApiError } from '../../api/client'
import type { Order } from '../../api/orders'
import { formErrors } from '../auth/authForms'

/** The `code` of a backend 409 (CheckoutConflict) or 402 (PaymentDeclined) answer. */
export type CheckoutCode =
  | 'out_of_stock'
  | 'price_changed'
  | 'reservation_expired'
  | 'reservation_lost'
  | 'payment_in_progress'
  | 'order_not_payable'
  | 'card_declined'

const CODES: readonly CheckoutCode[] = [
  'out_of_stock',
  'price_changed',
  'reservation_expired',
  'reservation_lost',
  'payment_in_progress',
  'order_not_payable',
  'card_declined',
]

/** A failed checkout request, sorted into what the page has to do about it. */
export type CheckoutError =
  /** The server refused for a business reason; may carry a fresh quote or the order's new state. */
  | { kind: 'conflict'; code: CheckoutCode; message: string; quote: CartQuote | null; order: Order | null }
  /** 422: messages per field (card_number, card_expiry, card_cvc...). */
  | { kind: 'invalid'; fields: Partial<Record<string, string>>; message: string | null }
  /** 404: not this user's order, or no such order. */
  | { kind: 'not_found' }
  /** Network failure or 5xx: the outcome is unknown, retrying is safe (D34). */
  | { kind: 'unknown'; message: string }

export function readCheckoutError(error: unknown): CheckoutError {
  if (!(error instanceof ApiError)) {
    return { kind: 'unknown', message: 'Could not reach the server. Check your connection and try again.' }
  }
  const body = isRecord(error.body) ? error.body : {}

  if ((error.status === 409 || error.status === 402) && typeof body.code === 'string' && isCode(body.code)) {
    return {
      kind: 'conflict',
      code: body.code,
      message: typeof body.message === 'string' ? body.message : 'This could not be completed.',
      // Trusted as the backend's own resource shapes (same as every API answer).
      quote: isRecord(body.quote) ? (body.quote as unknown as CartQuote) : null,
      order: isRecord(body.order) ? (body.order as unknown as Order) : null,
    }
  }
  if (error.status === 422) {
    const { fields, form } = formErrors(error)
    return { kind: 'invalid', fields, message: form }
  }
  if (error.status === 404) return { kind: 'not_found' }

  return { kind: 'unknown', message: 'Something went wrong on our side. Please try again.' }
}

function isCode(value: string): value is CheckoutCode {
  return (CODES as readonly string[]).includes(value)
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}
