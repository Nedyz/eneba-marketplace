import { describe, expect, it } from 'vitest'
import { formatDate, formatPrice } from './format'

describe('formatPrice', () => {
  it('turns integer cents into euros', () => {
    expect(formatPrice(1999)).toBe('€19.99')
    expect(formatPrice(500)).toBe('€5.00')
  })
})

describe('formatDate', () => {
  it('keeps the calendar day whatever the local timezone', () => {
    // The formatter is fixed to UTC, so this holds even when tests run in a
    // timezone west of UTC (where local formatting would show 24 February).
    expect(formatDate('2022-02-25')).toBe('25 February 2022')
  })
})
