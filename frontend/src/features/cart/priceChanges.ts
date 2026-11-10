import type { Cart } from './cartStorage'

/** "The price was X when you last saw it, it is Y now." */
export interface PriceChange {
  fromCents: number
  toCents: number
}

/** Offer id -> change. Matched by offer id, never by position (the quote is sorted by offer id). */
export type PriceChanges = ReadonlyMap<number, PriceChange>

/**
 * Compares the price each cart line last showed the buyer (the `seen`
 * snapshot) with the quoted price. Lines without a quoted price
 * (unavailable) have nothing to compare.
 */
export function detectPriceChanges(
  cart: Cart,
  quoted: ReadonlyArray<{ offer_id: number; unit_price_cents: number | null }>,
): PriceChanges {
  const priceByOffer = new Map(quoted.map((l) => [l.offer_id, l.unit_price_cents]))
  const changes = new Map<number, PriceChange>()
  for (const line of cart) {
    const now = priceByOffer.get(line.offerId)
    if (now != null && now !== line.seen.priceCents) {
      changes.set(line.offerId, { fromCents: line.seen.priceCents, toCents: now })
    }
  }
  return changes
}

/**
 * Adds newly detected changes to the notes already shown during this visit.
 * The snapshot is updated after each detection (D31), so a second change of
 * the same offer is detected from the intermediate price; the note keeps the
 * price the buyer first saw instead (20 -> 22 -> 25 reads "from 20 to 25").
 * A price that returns to where it started removes the note.
 */
export function mergePriceChanges(shown: PriceChanges, detected: PriceChanges): PriceChanges {
  if (detected.size === 0) return shown
  const merged = new Map(shown)
  for (const [offerId, change] of detected) {
    const fromCents = shown.get(offerId)?.fromCents ?? change.fromCents
    if (fromCents === change.toCents) merged.delete(offerId)
    else merged.set(offerId, { fromCents, toCents: change.toCents })
  }
  return merged
}
