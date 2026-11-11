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

const dateTimes = new Map<string | undefined, Intl.DateTimeFormat>()

/**
 * "2026-11-13T12:00:00+00:00" (a moment from the API) -> "13 November 2026 at
 * 14:00" in the buyer's own timezone: unlike a release date, an order happened
 * at a moment, and the buyer remembers it in local time. `timeZone` is for
 * tests only (they must not depend on the machine's timezone).
 */
export function formatDateTime(iso: string, timeZone?: string): string {
  let formatter = dateTimes.get(timeZone)
  if (!formatter) {
    formatter = new Intl.DateTimeFormat('en-IE', { dateStyle: 'long', timeStyle: 'short', timeZone })
    dateTimes.set(timeZone, formatter)
  }
  return formatter.format(new Date(iso))
}
