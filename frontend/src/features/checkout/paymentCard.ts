/**
 * Pure rules for the simulated payment form (D11, D34).
 *
 * Only the two test cards are accepted, and that is checked here, before
 * anything is sent: the page tells buyers not to type a real card, and if
 * someone does anyway, it never leaves the browser. The backend rejects any
 * other number too (PayOrderRequest).
 */

export interface TestCard {
  number: string
  outcome: string
}

export const TEST_CARDS: readonly TestCard[] = [
  { number: '4242 4242 4242 4242', outcome: 'Payment succeeds' },
  { number: '4000 0000 0000 0002', outcome: 'Card is declined' },
]

/** Expiry and security code to use with any test card. */
export const TEST_EXPIRY = '12/34'
export const TEST_CVC = '123'

export interface CardForm {
  number: string
  expiry: string
  cvc: string
}

export type CardErrors = Partial<Record<keyof CardForm, string>>

export function digitsOnly(value: string): string {
  return value.replace(/\D/g, '')
}

/** "4242424242424242" (or anything typed) -> "4242 4242 4242 4242", at most 16 digits. */
export function formatCardNumber(value: string): string {
  return digitsOnly(value).slice(0, 16).replace(/(\d{4})(?=\d)/g, '$1 ')
}

/** "123" -> "12/3"; the slash is added after the month. Deleting it works (no re-insert at length 2). */
export function formatExpiry(value: string): string {
  const digits = digitsOnly(value).slice(0, 4)
  return digits.length > 2 ? `${digits.slice(0, 2)}/${digits.slice(2)}` : digits
}

export function isTestCard(number: string): boolean {
  const digits = digitsOnly(number)
  return TEST_CARDS.some((card) => digitsOnly(card.number) === digits)
}

/**
 * Field errors, or an empty object when the form may be sent. `now` is passed
 * in so tests can fix the date.
 */
export function validateCard(form: CardForm, now: Date): CardErrors {
  const errors: CardErrors = {}

  if (digitsOnly(form.number) === '') errors.number = 'Enter a test card number.'
  else if (!isTestCard(form.number)) {
    errors.number = 'This is a simulated payment: only the test cards listed above work. Do not enter a real card.'
  }

  const match = /^(0[1-9]|1[0-2])\/(\d{2})$/.exec(form.expiry)
  if (!match) errors.expiry = 'Enter the expiry date as MM/YY.'
  else {
    const month = Number(match[1])
    const year = 2000 + Number(match[2])
    // Valid until the end of its expiry month (same rule as the backend).
    if (year * 12 + month < now.getFullYear() * 12 + now.getMonth() + 1) errors.expiry = 'This card has expired.'
  }

  if (!/^\d{3}$/.test(form.cvc)) errors.cvc = 'Enter the 3-digit security code.'

  return errors
}
