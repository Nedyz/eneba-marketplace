// One formatter, created once (constructing Intl formatters is relatively slow).
// en-IE: English text with euro formatting, "€19.99".
const euros = new Intl.NumberFormat('en-IE', { style: 'currency', currency: 'EUR' })

/** Integer cents from the API (D12) -> "€19.99". */
export function formatPrice(cents: number): string {
  return euros.format(cents / 100)
}
