import { describe, expect, it } from 'vitest'
import { formatCardNumber, formatExpiry, isTestCard, TEST_CARDS, TEST_CVC, TEST_EXPIRY, validateCard } from './paymentCard'

const NOW = new Date(2026, 10, 13) // 13 November 2026, local time
const valid = { number: '4242 4242 4242 4242', expiry: '12/30', cvc: '123' }

describe('formatCardNumber', () => {
  it('groups digits in fours', () => {
    expect(formatCardNumber('4242424242424242')).toBe('4242 4242 4242 4242')
    expect(formatCardNumber('42424')).toBe('4242 4')
    expect(formatCardNumber('4242')).toBe('4242')
  })

  it('drops anything that is not a digit and stops at 16 digits', () => {
    expect(formatCardNumber('4242-4242 abc 4242')).toBe('4242 4242 4242')
    expect(formatCardNumber('42424242424242421234')).toBe('4242 4242 4242 4242')
  })
})

describe('formatExpiry', () => {
  it('adds the slash after the month', () => {
    expect(formatExpiry('1')).toBe('1')
    expect(formatExpiry('12')).toBe('12')
    expect(formatExpiry('123')).toBe('12/3')
    expect(formatExpiry('12/34')).toBe('12/34')
    expect(formatExpiry('123456')).toBe('12/34')
  })
})

describe('isTestCard', () => {
  it('accepts the two test cards, with or without spaces', () => {
    expect(isTestCard('4242424242424242')).toBe(true)
    expect(isTestCard('4000 0000 0000 0002')).toBe(true)
  })

  it('rejects anything else, including a real-looking card', () => {
    expect(isTestCard('5555 5555 5555 4444')).toBe(false)
    expect(isTestCard('4242 4242 4242 424')).toBe(false)
    expect(isTestCard('')).toBe(false)
  })
})

describe('validateCard', () => {
  it('accepts a test card', () => {
    expect(validateCard(valid, NOW)).toEqual({})
  })

  it('every listed test card passes with the listed expiry and code', () => {
    for (const card of TEST_CARDS) {
      expect(validateCard({ number: card.number, expiry: TEST_EXPIRY, cvc: TEST_CVC }, NOW)).toEqual({})
    }
  })

  it('never lets a non-test card through, so it is never sent', () => {
    expect(validateCard({ ...valid, number: '5555 5555 5555 4444' }, NOW).number).toMatch(/only the test cards/)
  })

  it('asks for a number when empty', () => {
    expect(validateCard({ ...valid, number: '' }, NOW).number).toBe('Enter a test card number.')
  })

  it('checks the expiry format and month', () => {
    expect(validateCard({ ...valid, expiry: '1230' }, NOW).expiry).toBe('Enter the expiry date as MM/YY.')
    expect(validateCard({ ...valid, expiry: '13/30' }, NOW).expiry).toBe('Enter the expiry date as MM/YY.')
    expect(validateCard({ ...valid, expiry: '00/30' }, NOW).expiry).toBe('Enter the expiry date as MM/YY.')
  })

  it('a card is valid until the end of its expiry month', () => {
    expect(validateCard({ ...valid, expiry: '11/26' }, NOW).expiry).toBeUndefined()
    expect(validateCard({ ...valid, expiry: '10/26' }, NOW).expiry).toBe('This card has expired.')
    expect(validateCard({ ...valid, expiry: '12/25' }, NOW).expiry).toBe('This card has expired.')
  })

  it('needs a 3-digit code', () => {
    expect(validateCard({ ...valid, cvc: '12' }, NOW).cvc).toBeDefined()
    expect(validateCard({ ...valid, cvc: '1234' }, NOW).cvc).toBeDefined()
    expect(validateCard({ ...valid, cvc: '12a' }, NOW).cvc).toBeDefined()
  })

  it('reports every field at once', () => {
    expect(Object.keys(validateCard({ number: '1', expiry: 'x', cvc: '' }, NOW)).sort()).toEqual(['cvc', 'expiry', 'number'])
  })
})
