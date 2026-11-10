import { keepPreviousData, type UseQueryResult, useQuery } from '@tanstack/react-query'
import { useEffect, useMemo } from 'react'
import { Link } from 'react-router'
import { type CartQuote, cartQueryKeys, quoteCart } from '../../api/cart'
import { PageContainer } from '../../components/layout/PageContainer'
import { formatPrice } from '../../lib/format'
import { CartLineItem } from './CartLineItem'
import { cartCount, quoteRequestLines } from './cartStorage'
import { cartActions, useCart, usePriceNotes } from './cartStore'

/**
 * /cart. The cart's contents come from localStorage (useCart); every price,
 * stock count and the total come from POST /cart/quote (D8, D31).
 *
 * Problem lines (sold out, not enough stock, withdrawn) stay in the cart and
 * block checkout: the cart is never changed automatically, because stock can
 * come back when another buyer's reservation expires (D10).
 */
export function CartPage() {
  const cart = useCart()

  // Sorted [offer id, qty] pairs: the request body and the cache key. Lines
  // are rendered in the cart's own order and matched to the quote by offer id.
  const request = useMemo(() => quoteRequestLines(cart), [cart])

  const quote = useQuery({
    queryKey: cartQueryKeys.quote(request),
    queryFn: ({ signal }) => quoteCart(request, signal),
    enabled: cart.length > 0,
    // Always refetch on page load and window focus: prices and stock change
    // while the cart sits in the browser.
    staleTime: 0,
    // Keep the previous quote on screen while a quantity change is checked.
    placeholderData: keepPreviousData,
  })

  // Hand each fresh quote to the cart store, which notes price changes and
  // records the new prices as seen (D31). The previous quote kept during a
  // quantity change (placeholder) is not fresh, so it is skipped.
  useEffect(() => {
    if (quote.data && !quote.isPlaceholderData) cartActions.recordQuotedPrices(quote.data.lines)
  }, [quote.data, quote.isPlaceholderData])
  // Notes last for this visit of the cart page only.
  useEffect(() => cartActions.clearPriceNotes, [])
  const priceNotes = usePriceNotes()

  const quoteByOffer = useMemo(() => new Map(quote.data?.lines.map((line) => [line.offer_id, line])), [quote.data])

  if (cart.length === 0) {
    return (
      <PageContainer className="py-8">
        <title>Cart · Intersheba</title>
        <div className="flex flex-col items-start gap-4 bg-surface p-8">
          <h1 className="text-2xl font-extrabold">Your cart is empty</h1>
          <Link to="/store" className="bg-accent px-5 py-3 text-button font-extrabold text-black hover:bg-accent-hover">
            Browse games
          </Link>
        </div>
      </PageContainer>
    )
  }

  const count = cartCount(cart)

  return (
    <PageContainer className="py-8">
      <title>{`Cart (${count}) · Intersheba`}</title>
      <h1 className="mb-6 text-3xl font-extrabold">
        Cart <span className="text-text-muted">({count === 1 ? '1 item' : `${count} items`})</span>
      </h1>

      <div className="flex flex-col gap-6 lg:flex-row lg:items-start">
        <ul aria-label="Items in your cart" className="flex flex-1 flex-col gap-3">
          {cart.map((line) => (
            <CartLineItem
              key={line.offerId}
              line={line}
              quoted={quoteByOffer.get(line.offerId)}
              priceChange={priceNotes.get(line.offerId)}
            />
          ))}
        </ul>

        <Summary quote={quote} />
      </div>
    </PageContainer>
  )
}

function Summary({ quote }: { quote: UseQueryResult<CartQuote> }) {
  const data = quote.data
  // The shown quote must be current (not the previous one kept during a
  // change) and fresh (not an older one kept after a failed refetch).
  const upToDate = data !== undefined && !quote.isPlaceholderData && !quote.isError
  const canCheckout = upToDate && data.can_checkout
  const problems = data?.lines.filter((line) => line.status !== 'available').length ?? 0

  return (
    <aside aria-labelledby="summary-heading" className="flex flex-col gap-4 bg-surface p-5 lg:sticky lg:top-40 lg:w-80">
      <h2 id="summary-heading" className="text-xl font-extrabold">
        Summary
      </h2>

      <div className="flex items-baseline justify-between gap-4">
        <span className="font-semibold">{problems > 0 ? 'Total (available items)' : 'Total'}</span>
        {data ? (
          <span className={`text-price font-extrabold ${upToDate ? '' : 'text-text-muted'}`}>
            {formatPrice(data.total_cents)}
          </span>
        ) : (
          <span aria-hidden className="h-7 w-24 animate-pulse bg-white/10" />
        )}
      </div>

      {/* Announces the checking state and problems to screen readers. */}
      <div role="status" className="text-sm">
        {quote.isError ? (
          <p className="flex flex-col items-start gap-2 font-semibold text-accent">
            Could not check current prices.
            <button type="button" onClick={() => quote.refetch()} className="text-text underline">
              Try again
            </button>
          </p>
        ) : !upToDate ? (
          <p className="text-text-muted">Checking prices and stock…</p>
        ) : (
          problems > 0 && (
            <p className="font-semibold text-accent">
              {problems === 1 ? '1 item needs' : `${problems} items need`} your attention before checkout.
            </p>
          )
        )}
      </div>

      {canCheckout ? (
        // /checkout asks guests to sign in first (RequireAuth); the cart stays as it is.
        <Link
          to="/checkout"
          className="bg-accent px-5 py-3 text-center text-button font-extrabold text-black hover:bg-accent-hover"
        >
          Checkout
        </Link>
      ) : (
        <button type="button" disabled className="cursor-not-allowed bg-white/10 px-5 py-3 text-button font-extrabold text-text-muted">
          Checkout
        </button>
      )}
      <p className="text-xs text-text-muted">Prices are checked again when you place the order.</p>
    </aside>
  )
}
