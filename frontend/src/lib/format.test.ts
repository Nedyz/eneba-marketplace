import { describe, expect, it } from 'vitest'
import { formatDate, formatDateTime, formatPrice } from './format'

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

describe('formatDateTime', () => {
  it('shows the moment in the given timezone', () => {
    expect(formatDateTime('2026-11-13T12:00:00+00:00', 'UTC')).toBe('13 November 2026 at 12:00')
    // Vilnius is UTC+2 in November: the same moment, two hours later on the clock.
    expect(formatDateTime('2026-11-13T23:30:00+00:00', 'Europe/Vilnius')).toBe('14 November 2026 at 01:30')
  })
})
