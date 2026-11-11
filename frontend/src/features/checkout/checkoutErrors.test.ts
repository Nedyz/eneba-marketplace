import { describe, expect, it } from 'vitest'
import { ApiError } from '../../api/client'
import { readCheckoutError } from './checkoutErrors'

describe('readCheckoutError', () => {
  it('reads a 409 with its fresh quote', () => {
    const quote = { lines: [], total_cents: 3998, currency: 'EUR', can_checkout: true }
    const error = new ApiError(409, { message: 'Prices changed.', code: 'price_changed', quote })

    expect(readCheckoutError(error)).toEqual({ kind: 'conflict', code: 'price_changed', message: 'Prices changed.', quote, order: null })
  })

  it('reads a 402 decline with the failed order', () => {
    const order = { id: 7, status: 'failed' }
    const result = readCheckoutError(new ApiError(402, { message: 'Declined.', code: 'card_declined', order }))

    expect(result).toMatchObject({ kind: 'conflict', code: 'card_declined', order })
  })

  it('treats an unknown code as an unknown failure, not as a conflict', () => {
    expect(readCheckoutError(new ApiError(409, { code: 'something_new' })).kind).toBe('unknown')
  })

  it('reads 422 field messages', () => {
    const error = new ApiError(422, { message: 'x', errors: { card_number: ['Only test cards.'] } })

    expect(readCheckoutError(error)).toEqual({ kind: 'invalid', fields: { card_number: 'Only test cards.' }, message: null })
  })

  it('404 is not found', () => {
    expect(readCheckoutError(new ApiError(404, { message: 'Not Found' }))).toEqual({ kind: 'not_found' })
  })

  it('a network failure or a 500 is unknown (retrying is safe)', () => {
    expect(readCheckoutError(new TypeError('Failed to fetch')).kind).toBe('unknown')
    expect(readCheckoutError(new ApiError(500, null)).kind).toBe('unknown')
  })
})
