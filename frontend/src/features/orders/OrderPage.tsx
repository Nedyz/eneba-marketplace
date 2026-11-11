import { useQuery } from '@tanstack/react-query'
import { Link, useLocation, useParams } from 'react-router'
import { ApiError } from '../../api/client'
import { getOrder, type Order, orderQueryKeys } from '../../api/orders'
import { PageContainer } from '../../components/layout/PageContainer'
import { OrderItems } from './OrderItems'

/**
 * /orders/:orderId (signed in only): one order, with its keys once paid.
 * Right after a payment it is the success page ("Payment successful"); the
 * order history (day 6) will link here too.
 */
export function OrderPage() {
  const id = Number(useParams().orderId)
  const valid = Number.isSafeInteger(id) && id > 0
  const justPaid = isJustPaid(useLocation().state)

  const order = useQuery({
    queryKey: orderQueryKeys.detail(id),
    queryFn: ({ signal }) => getOrder(id, signal),
    enabled: valid,
    retry: (count, error) => !(error instanceof ApiError && error.status === 404) && count < 2,
  })

  if (!valid || (order.error instanceof ApiError && order.error.status === 404)) {
    return (
      <Shell title="Order not found">
        <p>This order does not exist, or it is not yours.</p>
      </Shell>
    )
  }
  if (!order.data) {
    return (
      <Shell title="Your order">
        {order.isError ? (
          <div role="alert" className="flex flex-col items-start gap-3">
            <p className="font-semibold">Could not load your order.</p>
            <button type="button" onClick={() => order.refetch()} className="underline">
              Try again
            </button>
          </div>
        ) : (
          <p role="status" className="text-text-muted">
            Loading your order…
          </p>
        )}
      </Shell>
    )
  }

  const data = order.data
  const paid = data.status === 'paid'

  return (
    <PageContainer className="flex flex-col gap-6 py-8">
      <title>{`Order #${data.id} · Intersheba`}</title>
      <header className="flex flex-col gap-2">
        <h1 className="text-3xl font-extrabold">{paid && justPaid ? 'Payment successful' : `Order #${data.id}`}</h1>
        <p className="text-text-soft">{statusLine(data, justPaid)}</p>
      </header>

      {(data.status === 'pending' || data.status === 'paying') && (
        <Link
          to={`/checkout/${data.id}`}
          className="self-start bg-accent px-5 py-3 text-button font-extrabold text-black hover:bg-accent-hover"
        >
          Go to payment
        </Link>
      )}

      <OrderItems order={data} />

      <div className="flex flex-wrap gap-4">
        <Link to="/store" className="underline">
          Continue shopping
        </Link>
      </div>
    </PageContainer>
  )
}

function Shell({ title, children }: { title: string; children: React.ReactNode }) {
  return (
    <PageContainer className="flex flex-col items-start gap-4 py-10">
      <title>{`${title} · Intersheba`}</title>
      <h1 className="text-2xl font-extrabold">{title}</h1>
      {children}
    </PageContainer>
  )
}

function statusLine(order: Order, justPaid: boolean): string {
  switch (order.status) {
    case 'paid':
      return justPaid
        ? `Thank you! Order #${order.id} is paid. Your keys are below.`
        : `Paid. Your keys are below.`
    case 'pending':
    case 'paying':
      return 'Waiting for payment. Your keys are reserved for a few more minutes.'
    case 'failed':
      return 'Not paid. If anything was charged, it was refunded.'
    case 'expired':
      return 'The reservation expired before payment. Nothing was charged.'
    case 'cancelled':
      return 'Replaced by a newer checkout. Nothing was charged.'
  }
}

function isJustPaid(state: unknown): boolean {
  return typeof state === 'object' && state !== null && 'justPaid' in state && state.justPaid === true
}
