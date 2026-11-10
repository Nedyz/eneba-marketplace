// One formatter, created once (constructing Intl formatters is relatively slow).
// en-IE: English text with euro formatting, "€19.99".
const euros = new Intl.NumberFormat('en-IE', { style: 'currency', currency: 'EUR' })

/** Integer cents from the API (D12) -> "€19.99". */
export function formatPrice(cents: number): string {
  return euros.format(cents / 100)
}

// timeZone UTC: new Date('2022-02-25') is midnight UTC, so formatting it in a
// timezone west of UTC (e.g. New York) would otherwise show 24 Feb.
const dates = new Intl.DateTimeFormat('en-IE', { day: 'numeric', month: 'long', year: 'numeric', timeZone: 'UTC' })

/** "2022-02-25" (a date without time from the API) -> "25 February 2022". */
export function formatDate(isoDate: string): string {
  return dates.format(new Date(isoDate))
}
