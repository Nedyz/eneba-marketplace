import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { type FormEvent, useEffect, useMemo, useRef, useState } from 'react'
import { Link, Navigate, useNavigate, useParams } from 'react-router'
import { ApiError } from '../../api/client'
import { getOrder, type Order, orderQueryKeys, payOrder, placeOrder } from '../../api/orders'
import { PageContainer } from '../../components/layout/PageContainer'
import { formatPrice } from '../../lib/format'
import { FormAlert, SubmitButton, TextField } from '../auth/AuthFormParts'
import { cartActions } from '../cart/cartStore'
import { OrderItems } from '../orders/OrderItems'
import { readCheckoutError } from './checkoutErrors'
import { deadlineFrom, formatCountdown, secondsUntil, timerAnnouncement } from './countdown'
import {
  type CardErrors,
  type CardForm,
  digitsOnly,
  formatCardNumber,
  formatExpiry,
  TEST_CARDS,
  TEST_CVC,
  TEST_EXPIRY,
  validateCard,
} from './paymentCard'

/**
 * /checkout/:orderId (signed in only). Step 2: the order exists and its keys
 * are reserved; the buyer pays with a test card before the timer runs out.
 *
 * The order comes from the server (GET /orders/{id}), so a reload keeps the
 * same reservation and timer. What is shown follows the order's status:
 * pending -> form, paying -> wait, paid -> the order page, anything else ->
 * what happened and "Try again".
 */
export function PaymentPage() {
  const id = Number(useParams().orderId)
  const valid = Number.isSafeInteger(id) && id > 0
  // Set by this page when its own payment succeeded: the order page then
  // says "Payment successful". Opening an old paid order here does not.
  const [paidHere, setPaidHere] = useState(false)
  // Why the order closed, when this page knows (the pay answer's message).
  const [closedReason, setClosedReason] = useState<string | null>(null)

  const order = useQuery({
    queryKey: orderQueryKeys.detail(id),
    queryFn: ({ signal }) => getOrder(id, signal),
    enabled: valid,
    // While another request is paying this order (second tab, double click),
    // poll until it is decided.
    refetchInterval: (query) => (query.state.data?.status === 'paying' ? 1000 : false),
    retry: (count, error) => !(error instanceof ApiError && error.status === 404) && count < 2,
  })

  if (!valid || (order.error instanceof ApiError && order.error.status === 404)) {
    return (
      <Shell>
        <p>This order does not exist, or it is not yours.</p>
        <Link to="/cart" className="underline">
          Back to cart
        </Link>
      </Shell>
    )
  }
  if (order.isError && !order.data) {
    return (
      <Shell>
        <div role="alert" className="flex flex-col items-start gap-3">
          <p className="font-semibold">Could not load your order.</p>
          <button type="button" onClick={() => order.refetch()} className="underline">
            Try again
          </button>
        </div>
      </Shell>
    )
  }
  if (!order.data) {
    return (
      <Shell>
        <p role="status" className="text-text-muted">
          Loading your order…
        </p>
      </Shell>
    )
  }

  const data = order.data
  switch (data.status) {
    case 'paid':
      return <Navigate to={`/orders/${data.id}`} replace state={{ justPaid: paidHere }} />
    case 'paying':
      return (
        <Shell>
          <p role="status" className="font-semibold">
            Your payment is being processed…
          </p>
        </Shell>
      )
    case 'pending':
      return (
        <PayStep
          order={data}
          receivedAt={order.dataUpdatedAt}
          onPaid={() => setPaidHere(true)}
          onClosed={setClosedReason}
        />
      )
    default:
      return <OrderClosed order={data} reason={closedReason} />
  }
}

function Shell({ children }: { children: React.ReactNode }) {
  return (
    <PageContainer className="flex flex-col items-start gap-4 py-10">
      <title>Payment · Intersheba</title>
      <h1 className="text-2xl font-extrabold">Payment</h1>
      {children}
    </PageContainer>
  )
}

const EMPTY_CARD: CardForm = { number: '', expiry: '', cvc: '' }

interface PayStepProps {
  order: Order
  /** When this order data arrived (TanStack Query's dataUpdatedAt), on this computer's clock. */
  receivedAt: number
  onPaid: () => void
  onClosed: (reason: string) => void
}

function PayStep({ order, receivedAt, onPaid, onClosed }: PayStepProps) {
  const queryClient = useQueryClient()
  const deadline = useMemo(() => deadlineFrom(order.seconds_left ?? 0, receivedAt), [order.seconds_left, receivedAt])
  const secondsLeft = useSecondsLeft(deadline)
  const expired = secondsLeft === 0

  // At zero, ask the server: it expires the order (lazy expiry, D10) and the
  // page switches to "expired". A moment later, because the server only
  // expires strictly after the deadline.
  useEffect(() => {
    if (!expired) return
    const timer = window.setTimeout(() => queryClient.invalidateQueries({ queryKey: orderQueryKeys.detail(order.id) }), 1500)
    return () => window.clearTimeout(timer)
  }, [expired, order.id, queryClient])

  const [card, setCard] = useState<CardForm>(EMPTY_CARD)
  const [errors, setErrors] = useState<CardErrors>({})
  const [formMessage, setFormMessage] = useState<string | null>(null)

  // Double-click guard: set synchronously on the first submit, before React
  // has re-rendered the disabled button (D34). The server refuses a second
  // payment anyway (`paying`), this just avoids sending it.
  const paying = useRef(false)
  const pay = useMutation({
    mutationFn: () =>
      payOrder(order.id, { card_number: digitsOnly(card.number), card_expiry: card.expiry, card_cvc: card.cvc }),
    onSuccess: (paid) => {
      // Only now, with the server's "paid" in hand, do the bought offers leave
      // the cart (D34). A decline or an expiry leaves the cart as it was.
      cartActions.removeOrdered(paid.items.map((item) => item.offer_id))
      onPaid()
      queryClient.setQueryData(orderQueryKeys.detail(order.id), paid)
    },
    onError: (error) => {
      paying.current = false
      const result = readCheckoutError(error)
      switch (result.kind) {
        case 'conflict':
          if (result.code === 'payment_in_progress') {
            // Another request is paying it: refetch, the page then polls.
            queryClient.invalidateQueries({ queryKey: orderQueryKeys.detail(order.id) })
            return
          }
          // Declined, expired, replaced...: show the order in its new state.
          onClosed(result.message)
          if (result.order) queryClient.setQueryData(orderQueryKeys.detail(order.id), result.order)
          return
        case 'invalid':
          setErrors({
            number: result.fields.card_number,
            expiry: result.fields.card_expiry,
            cvc: result.fields.card_cvc,
          })
          setFormMessage(result.message)
          return
        case 'not_found':
          setFormMessage('This order no longer exists.')
          return
        case 'unknown':
          // The outcome is unknown, but a retry can never charge twice:
          // the server pays an order at most once (D34).
          setFormMessage(`${result.message} You will never be charged twice for this order.`)
      }
    },
  })

  function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (paying.current || expired) return
    const found = validateCard(card, new Date())
    setErrors(found)
    setFormMessage(null)
    // Anything that is not a test card stops here and is never sent.
    if (Object.keys(found).length > 0) return
    paying.current = true
    pay.mutate()
  }

  function fillTestCard(number: string) {
    setCard({ number, expiry: TEST_EXPIRY, cvc: TEST_CVC })
    setErrors({})
  }

  return (
    <PageContainer className="py-8">
      <title>Payment · Intersheba</title>
      <h1 className="mb-6 text-3xl font-extrabold">Payment</h1>

      <div className="flex flex-col gap-6 lg:flex-row lg:items-start">
        <section aria-labelledby="order-heading" className="flex flex-1 flex-col gap-4">
          <h2 id="order-heading" className="text-xl font-extrabold">
            Your order
          </h2>
          <OrderItems order={order} />
        </section>

        <section aria-labelledby="card-heading" className="flex flex-col gap-5 bg-surface p-5 lg:w-96">
          <Countdown seconds={secondsLeft} />

          <h2 id="card-heading" className="text-xl font-extrabold">
            Pay with a test card
          </h2>

          {/* D34: said before the form, so nobody types a real card number. */}
          <div className="flex flex-col gap-3 border-l-4 border-accent bg-bg-deep p-4">
            <p className="font-bold">This is a simulated payment.</p>
            <p className="text-sm text-text-soft">
              No money is charged and no real card can be used. Only the two test cards below work.{' '}
              <strong className="text-text">Do not enter a real card number.</strong>
            </p>
            <ul aria-label="Test cards" className="flex flex-col gap-2">
              {TEST_CARDS.map((testCard) => (
                <li key={testCard.number} className="flex flex-wrap items-center justify-between gap-2">
                  <span>
                    <code className="font-mono">{testCard.number}</code>
                    <span className="block text-xs text-text-muted">{testCard.outcome}</span>
                  </span>
                  <button
                    type="button"
                    onClick={() => fillTestCard(testCard.number)}
                    disabled={expired}
                    className="border border-white/30 px-3 py-1.5 text-sm font-semibold hover:border-white/70 disabled:opacity-50"
                    aria-label={`Use test card ${testCard.number} (${testCard.outcome.toLowerCase()})`}
                  >
                    Use
                  </button>
                </li>
              ))}
            </ul>
            <p className="text-xs text-text-muted">
              Any future expiry date (for example {TEST_EXPIRY}) and any 3 digits as the security code.
            </p>
          </div>

          <form onSubmit={submit} noValidate className="flex flex-col gap-4">
            <FormAlert message={formMessage} />
            {/* autoComplete="off" and no cc-* names: the browser must not offer
                to fill in the buyer's real saved cards here. */}
            <TextField
              label="Card number"
              inputMode="numeric"
              autoComplete="off"
              placeholder="4242 4242 4242 4242"
              value={card.number}
              error={errors.number}
              disabled={expired}
              onChange={(event) => setCard({ ...card, number: formatCardNumber(event.target.value) })}
            />
            <div className="grid grid-cols-2 gap-4">
              <TextField
                label="Expiry (MM/YY)"
                inputMode="numeric"
                autoComplete="off"
                placeholder="MM/YY"
                value={card.expiry}
                error={errors.expiry}
                disabled={expired}
                onChange={(event) => setCard({ ...card, expiry: formatExpiry(event.target.value) })}
              />
              <TextField
                label="Security code"
                inputMode="numeric"
                autoComplete="off"
                placeholder="123"
                maxLength={3}
                value={card.cvc}
                error={errors.cvc}
                disabled={expired}
                onChange={(event) => setCard({ ...card, cvc: digitsOnly(event.target.value) })}
              />
            </div>
            <SubmitButton pending={pay.isPending || expired}>
              {pay.isPending ? 'Processing…' : expired ? 'Reservation expired' : `Pay ${formatPrice(order.total_cents)}`}
            </SubmitButton>
          </form>
        </section>
      </div>
    </PageContainer>
  )
}

/** Re-renders every second with the seconds left until the deadline. */
function useSecondsLeft(deadline: number): number {
  const [now, setNow] = useState(() => Date.now())
  useEffect(() => {
    const timer = window.setInterval(() => setNow(Date.now()), 1000)
    return () => window.clearInterval(timer)
  }, [])
  return secondsUntil(deadline, now)
}

function Countdown({ seconds }: { seconds: number }) {
  const urgent = seconds <= 60
  return (
    <div className={`flex items-baseline justify-between gap-3 px-4 py-3 ${urgent ? 'bg-accent text-black' : 'bg-bg-deep'}`}>
      <span className="font-semibold">{seconds > 0 ? 'Keys reserved for' : 'Reservation expired'}</span>
      {/* Visible every second, but not a live region: that would be read out
          every second. The milestones are announced separately below. */}
      <span role="timer" className="font-mono text-2xl font-extrabold tabular-nums">
        {formatCountdown(seconds)}
      </span>
      <span aria-live="polite" className="sr-only">
        {timerAnnouncement(seconds)}
      </span>
    </div>
  )
}

/** Failed, expired or replaced: what happened, and one click to try again. */
function OrderClosed({ order, reason }: { order: Order; reason: string | null }) {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const starting = useRef(false)

  // "Try again" orders exactly the same lines again, at the total the buyer
  // saw for this order. The server re-checks stock and price; if anything
  // changed, the checkout page explains it with a fresh quote. The cart was
  // never touched, so it still holds these items.
  const retry = useMutation({
    mutationFn: () =>
      placeOrder(
        order.items.map((item) => ({ offer_id: item.offer_id, qty: item.quantity })).sort((a, b) => a.offer_id - b.offer_id),
        order.total_cents,
      ),
    onSuccess: (next) => {
      queryClient.setQueryData(orderQueryKeys.detail(next.id), next)
      navigate(`/checkout/${next.id}`, { replace: true })
    },
    onError: (error) => {
      starting.current = false
      const result = readCheckoutError(error)
      navigate('/checkout', {
        replace: true,
        state: { notice: result.kind === 'conflict' ? result.message : 'Could not start a new order. Please try again.' },
      })
    },
  })

  const text = reason ?? defaultReason(order.status)

  return (
    <Shell>
      <div role="alert" className="flex flex-col gap-2">
        <p className="text-lg font-bold">{text}</p>
        <p className="text-text-soft">Your cart still has these items.</p>
      </div>
      <div className="flex flex-wrap items-center gap-4">
        <button
          type="button"
          disabled={retry.isPending}
          onClick={() => {
            if (starting.current) return
            starting.current = true
            retry.mutate()
          }}
          className="bg-accent px-5 py-3 text-button font-extrabold text-black hover:bg-accent-hover disabled:cursor-wait disabled:opacity-70"
        >
          {retry.isPending ? 'Reserving your keys…' : 'Try again'}
        </button>
        <Link to="/cart" className="underline">
          Back to cart
        </Link>
      </div>
    </Shell>
  )
}

function defaultReason(status: Order['status']): string {
  switch (status) {
    case 'expired':
      return 'Your reservation expired. Nothing was charged.'
    case 'cancelled':
      return 'You started a newer checkout, so this order was replaced. Nothing was charged.'
    default:
      return 'This order was not paid. If anything was charged, it was refunded.'
  }
}
