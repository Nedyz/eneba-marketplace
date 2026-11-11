import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useEffect, useMemo, useRef, useState } from 'react'
import { Link, useLocation, useNavigate } from 'react-router'
import { cartQueryKeys, quoteCart } from '../../api/cart'
import { orderQueryKeys, placeOrder } from '../../api/orders'
import { PageContainer } from '../../components/layout/PageContainer'
import { formatPrice } from '../../lib/format'
import { FormAlert } from '../auth/AuthFormParts'
import { type QuoteRequestLine, quoteRequestLines } from '../cart/cartStorage'
import { cartActions, useCart, usePriceNotes } from '../cart/cartStore'
import { readCheckoutError } from './checkoutErrors'

/**
 * /checkout (signed in only, RequireAuth). Step 1: the buyer confirms what
 * they are buying at today's prices, then "Place order" reserves the keys
 * (POST /orders) and moves on to the payment page.
 *
 * The quote is the cart page's query (same key), refetched on arrival. The
 * total sent as expected_total_cents is the one on screen: if the server's
 * differs, the answer is 409 price_changed with a fresh quote, which replaces
 * this one, and the buyer confirms again (D31).
 */
export function CheckoutPage() {
  const cart = useCart()
  const request = useMemo(() => quoteRequestLines(cart), [cart])
  const queryClient = useQueryClient()
  const navigate = useNavigate()
  // A message passed from the payment page ("Try again" found a problem).
  const notice = readNotice(useLocation().state)
  const [message, setMessage] = useState<string | null>(notice)

  const quote = useQuery({
    queryKey: cartQueryKeys.quote(request),
    queryFn: ({ signal }) => quoteCart(request, signal),
    enabled: cart.length > 0,
    staleTime: 0,
    placeholderData: keepPreviousData,
  })

  // Same as the cart page: notice price changes, record the new prices as seen.
  useEffect(() => {
    if (quote.data && !quote.isPlaceholderData) cartActions.recordQuotedPrices(quote.data.lines)
  }, [quote.data, quote.isPlaceholderData])
  useEffect(() => cartActions.clearPriceNotes, [])
  const priceNotes = usePriceNotes()

  // Set synchronously on the first click. The disabled button alone is not
  // enough: two clicks can arrive before React re-renders it.
  const submitting = useRef(false)
  const place = useMutation({
    mutationFn: ({ lines, total }: { lines: QuoteRequestLine[]; total: number }) => placeOrder(lines, total),
    onSuccess: (order) => {
      queryClient.setQueryData(orderQueryKeys.detail(order.id), order)
      // replace: Back from the payment page returns to the cart, not here.
      navigate(`/checkout/${order.id}`, { replace: true })
    },
    onError: (error, { lines }) => {
      submitting.current = false
      const result = readCheckoutError(error)
      if (result.kind === 'conflict') {
        // The fresh quote becomes the one on screen (new total, problem lines).
        if (result.quote) queryClient.setQueryData(cartQueryKeys.quote(lines), result.quote)
        setMessage(result.message)
      } else {
        setMessage(result.kind === 'unknown' ? result.message : 'Could not place the order. Please try again.')
      }
    },
  })

  if (cart.length === 0) {
    return (
      <PageContainer className="py-10">
        <title>Checkout · Intersheba</title>
        <div className="flex flex-col items-start gap-4 bg-surface p-8">
          <h1 className="text-2xl font-extrabold">Your cart is empty</h1>
          <Link to="/store" className="bg-accent px-5 py-3 text-button font-extrabold text-black hover:bg-accent-hover">
            Browse games
          </Link>
        </div>
      </PageContainer>
    )
  }

  const data = quote.data
  const upToDate = data !== undefined && !quote.isPlaceholderData && !quote.isError
  const canPlace = upToDate && data.can_checkout && !place.isPending
  const quoteByOffer = new Map(data?.lines.map((line) => [line.offer_id, line]))

  function submit() {
    if (!canPlace || submitting.current || !data) return
    submitting.current = true
    setMessage(null)
    place.mutate({ lines: request, total: data.total_cents })
  }

  return (
    <PageContainer className="py-8">
      <title>Checkout · Intersheba</title>
      <h1 className="mb-6 text-3xl font-extrabold">Checkout</h1>

      <div className="flex flex-col gap-6 lg:flex-row lg:items-start">
        <section aria-labelledby="review-heading" className="flex flex-1 flex-col gap-3">
          <h2 id="review-heading" className="text-xl font-extrabold">
            Review your order
          </h2>
          <ul className="flex flex-col gap-3">
            {cart.map((line) => {
              const quoted = quoteByOffer.get(line.offerId)
              const current = quoted?.qty === line.qty ? quoted : undefined
              const note = priceNotes.get(line.offerId)
              return (
                // Same layout as the payment and order pages (OrderItems), so
                // the line does not change shape from one step to the next.
                <li key={line.offerId} className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 bg-surface p-4 md:p-5">
                  <div className="flex flex-col gap-1">
                    <span className="font-bold">{quoted?.product?.name ?? line.seen.productName}</span>
                    <span className="text-sm text-text-muted">
                      {quoted?.product && `${quoted.product.platform.name} · ${quoted.product.region.name} · `}
                      Sold by {quoted?.seller?.name ?? line.seen.sellerName}
                    </span>
                    {note && (
                      <span className="text-sm font-semibold text-accent">
                        Price {note.toCents > note.fromCents ? 'went up' : 'dropped'} from {formatPrice(note.fromCents)} to{' '}
                        {formatPrice(note.toCents)}.
                      </span>
                    )}
                    {current && current.status !== 'available' && (
                      <span className="text-sm font-semibold text-accent">{problemText(current.status, current.stock)}</span>
                    )}
                  </div>
                  <span className="text-right">
                    <span className="text-sm text-text-muted">
                      {line.qty} ×{current?.unit_price_cents != null && ` ${formatPrice(current.unit_price_cents)}`}
                    </span>{' '}
                    <span className="font-extrabold">
                      {current?.line_total_cents != null ? formatPrice(current.line_total_cents) : '—'}
                    </span>
                  </span>
                </li>
              )
            })}
          </ul>
          <Link to="/cart" className="self-start text-text-soft underline">
            Change your cart
          </Link>
        </section>

        <aside aria-labelledby="pay-heading" className="flex flex-col gap-4 bg-surface p-5 lg:sticky lg:top-40 lg:w-80">
          <h2 id="pay-heading" className="text-xl font-extrabold">
            Total
          </h2>
          <FormAlert message={message} />
          <div className="flex items-baseline justify-between gap-4">
            <span className="font-semibold">To pay</span>
            {data ? (
              <span className={`text-price font-extrabold ${upToDate ? '' : 'text-text-muted'}`}>{formatPrice(data.total_cents)}</span>
            ) : (
              <span aria-hidden className="h-7 w-24 animate-pulse bg-white/10" />
            )}
          </div>

          <div role="status" className="text-sm empty:hidden">
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
              !data.can_checkout && (
                <p className="font-semibold text-accent">
                  Some items are not available in the quantity you chose.{' '}
                  <Link to="/cart" className="text-text underline">
                    Fix them in your cart
                  </Link>
                </p>
              )
            )}
          </div>

          <button
            type="button"
            onClick={submit}
            disabled={!canPlace}
            className="bg-accent px-5 py-3 text-button font-extrabold text-black hover:bg-accent-hover disabled:cursor-not-allowed disabled:bg-white/10 disabled:text-text-muted"
          >
            {place.isPending ? 'Reserving your keys…' : 'Place order'}
          </button>
          <p className="text-xs text-text-muted">
            Your keys are reserved for 15 minutes while you pay. Nothing is charged until you pay.
          </p>
        </aside>
      </div>
    </PageContainer>
  )
}

function problemText(status: string, stock: number): string {
  if (status === 'insufficient_stock') return `Only ${stock} available right now.`
  if (status === 'sold_out') return 'Sold out right now.'
  return 'No longer sold by this seller.'
}

function readNotice(state: unknown): string | null {
  if (typeof state === 'object' && state !== null && 'notice' in state && typeof state.notice === 'string') {
    return state.notice
  }
  return null
}
