import { useSyncExternalStore } from 'react'
import {
  addToCart,
  type Cart,
  CART_STORAGE_KEY,
  type CartSnapshot,
  markPricesSeen,
  parseCart,
  removeLine,
  removeOffers,
  serializeCart,
  setQty,
} from './cartStorage'
import { detectPriceChanges, mergePriceChanges, type PriceChanges } from './priceChanges'

/**
 * The one in-memory copy of the cart, kept in sync with localStorage. Like a
 * singleton service with a change event in .NET: React components subscribe
 * through useCart() (useSyncExternalStore), and every change goes through
 * update(), which writes localStorage once and notifies subscribers.
 *
 * Not a React context: writing in the same call as the change (instead of in
 * an effect) means exactly one write per change, and another tab's change
 * arrives through the `storage` event as just another update.
 */

let cart: Cart = readStorage()
// Price change notes shown on the cart page during this visit (memory only,
// cleared when the cart page unmounts). Kept here, next to the snapshot they
// are detected from, so detecting a change and recording the new price as
// seen happen in one step.
let priceNotes: PriceChanges = new Map()
const listeners = new Set<() => void>()

function emit(): void {
  listeners.forEach((listener) => listener())
}

function readStorage(): Cart {
  try {
    return parseCart(window.localStorage.getItem(CART_STORAGE_KEY))
  } catch {
    // Storage disabled (some private modes): start empty, the cart still works in memory.
    return []
  }
}

function update(change: (current: Cart) => Cart): void {
  const next = change(cart)
  if (next === cart) return // the pure functions return the same object when nothing changed
  cart = next
  try {
    window.localStorage.setItem(CART_STORAGE_KEY, serializeCart(next))
  } catch {
    // Quota exceeded or storage disabled: keep the in-memory cart for this tab.
  }
  emit()
}

// Another tab changed the cart (the event never fires in the tab that wrote).
// key === null means localStorage.clear().
window.addEventListener('storage', (event) => {
  if (event.storageArea !== window.localStorage) return
  if (event.key !== CART_STORAGE_KEY && event.key !== null) return
  cart = parseCart(event.newValue)
  emit()
})

function subscribe(listener: () => void): () => void {
  listeners.add(listener)
  return () => listeners.delete(listener)
}

/** The current cart; re-renders the component whenever it changes (in this tab or another). */
export function useCart(): Cart {
  return useSyncExternalStore(subscribe, () => cart)
}

/** Price change notes for the cart page's current visit, by offer id. */
export function usePriceNotes(): PriceChanges {
  return useSyncExternalStore(subscribe, () => priceNotes)
}

/** Cart actions. Plain functions: they work outside components too. */
export const cartActions = {
  add: (offerId: number, seen: CartSnapshot, stock: number) => update((c) => addToCart(c, offerId, seen, stock)),
  setQty: (offerId: number, qty: number) => update((c) => setQty(c, offerId, qty)),
  remove: (offerId: number) => update((c) => removeLine(c, offerId)),
  /** A paid order's offers leave the cart (D34). Only called with the pay response. */
  removeOrdered: (offerIds: Iterable<number>) => update((c) => removeOffers(c, new Set(offerIds))),
  /**
   * A fresh quote arrived: note every price that differs from what the buyer
   * last saw, then record the new price as seen (D31: one note per detection;
   * after a reload it is gone).
   */
  recordQuotedPrices: (lines: ReadonlyArray<{ offer_id: number; unit_price_cents: number | null }>) => {
    const detected = detectPriceChanges(cart, lines)
    if (detected.size === 0) return
    priceNotes = mergePriceChanges(priceNotes, detected)
    // update() emits, so the new notes and the new snapshot render together.
    update((c) => markPricesSeen(c, new Map([...detected].map(([offerId, change]) => [offerId, change.toCents]))))
  },
  clearPriceNotes: () => {
    if (priceNotes.size === 0) return
    priceNotes = new Map()
    emit()
  },
}
