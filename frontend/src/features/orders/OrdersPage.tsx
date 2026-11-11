import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { type ReactNode, useEffect } from 'react'
import { Link, useSearchParams } from 'react-router'
import { getOrders, orderQueryKeys, type OrderSummary } from '../../api/orders'
import { PageContainer } from '../../components/layout/PageContainer'
import { formatDateTime, formatPrice } from '../../lib/format'
import { Pagination } from '../store/Pagination'
import { awaitsPayment, parseOrdersPage, statusLabel, statusTone, type StatusTone } from './orderStatus'

/**
 * /orders (signed in only, D35): the buyer's order history, newest first, 10
 * per page, page number in the URL. Each order links to its own page, which
 * has the keys; the history itself never shows keys.
 */
export function OrdersPage() {
  const [searchParams] = useSearchParams()
  const page = parseOrdersPage(searchParams.get('page'))

  const orders = useQuery({
    queryKey: orderQueryKeys.list(page),
    queryFn: ({ signal }) => getOrders(page, signal),
    // While the next page loads, keep showing the current one (as the store).
    placeholderData: keepPreviousData,
    // Always refetch when the page opens: a status changes after paying, and
    // by time (lazy expiry on the server). The app-wide 30 s would show the
    // list from before a purchase made a moment ago.
    staleTime: 0,
  })

  useEffect(() => {
    window.scrollTo({ top: 0 })
  }, [page])

  const title = page > 1 ? `Your orders, page ${page}` : 'Your orders'

  if (!orders.data) {
    return (
      <Shell title={title}>
        {orders.isError ? (
          <div role="alert" className="flex flex-col items-start gap-3">
            <p className="font-semibold">Could not load your orders.</p>
            <button type="button" onClick={() => orders.refetch()} className="underline">
              Try again
            </button>
          </div>
        ) : (
          <p role="status" className="text-text-muted">
            Loading your orders…
          </p>
        )}
      </Shell>
    )
  }

  const { data, meta } = orders.data

  if (meta.total === 0) {
    return (
      <Shell title={title}>
        <p>You have no orders yet.</p>
        <Link to="/store" className="font-extrabold underline">
          Browse the store
        </Link>
      </Shell>
    )
  }

  if (data.length === 0) {
    // An old link past the last page.
    return (
      <Shell title={title}>
        <p>Page {page} is empty.</p>
        <Link to="/orders" className="font-extrabold underline">
          Go to the first page
        </Link>
      </Shell>
    )
  }

  return (
    <Shell title={title}>
      <p className="text-text-muted">
        {meta.total} {meta.total === 1 ? 'order' : 'orders'}
      </p>
      <ul
        aria-label="Orders"
        aria-busy={orders.isPlaceholderData}
        className={`flex w-full flex-col gap-4 ${orders.isPlaceholderData ? 'opacity-60' : ''}`}
      >
        {data.map((order) => (
          <li key={order.id}>
            <OrderCard order={order} />
          </li>
        ))}
      </ul>
      <div className="self-center">
        <Pagination
          current={meta.current_page}
          last={meta.last_page}
          searchFor={(target) => (target > 1 ? `?page=${target}` : '')}
        />
      </div>
    </Shell>
  )
}

function OrderCard({ order }: { order: OrderSummary }) {
  const headingId = `order-${order.id}`

  return (
    <article aria-labelledby={headingId} className="flex flex-col gap-4 bg-surface p-4 md:p-5">
      <header className="flex flex-wrap items-start justify-between gap-x-4 gap-y-2">
        <div className="flex flex-col gap-1">
          <h2 id={headingId} className="text-lg font-extrabold">
            Order #{order.id}
          </h2>
          <p className="text-sm text-text-muted">
            <time dateTime={order.created_at}>{formatDateTime(order.created_at)}</time>
          </p>
        </div>
        <StatusBadge tone={statusTone(order.status)} label={statusLabel(order.status)} />
      </header>

      <ul aria-label={`Items in order #${order.id}`} className="flex flex-col gap-2">
        {order.items.map((item) => (
          <li key={item.offer_id} className="flex flex-wrap items-baseline justify-between gap-x-4">
            <span>
              <span className="font-semibold">{item.product_name}</span>{' '}
              <span className="text-sm text-text-muted">
                {item.platform} · {item.region}
              </span>
            </span>
            <span className="text-sm text-text-muted">
              {item.quantity} × {formatPrice(item.unit_price_cents)}
            </span>
          </li>
        ))}
      </ul>

      <footer className="flex flex-wrap items-center justify-between gap-4 border-t border-white/10 pt-4">
        <p>
          Total <span className="ml-2 text-price font-extrabold">{formatPrice(order.total_cents)}</span>
        </p>
        <div className="flex flex-wrap items-center gap-4">
          {awaitsPayment(order.status) && (
            <Link
              to={`/checkout/${order.id}`}
              className="bg-accent px-4 py-2 text-button font-extrabold text-black hover:bg-accent-hover"
            >
              Go to payment<span className="sr-only"> for order #{order.id}</span>
            </Link>
          )}
          <Link to={`/orders/${order.id}`} className="font-extrabold underline">
            {order.status === 'paid' ? 'View keys' : 'View order'}
            {/* Unique link names for screen readers' link lists. */}
            <span className="sr-only">{order.status === 'paid' ? ` of order #${order.id}` : ` #${order.id}`}</span>
          </Link>
        </div>
      </footer>
    </article>
  )
}

const toneClass: Record<StatusTone, string> = {
  done: 'text-success',
  waiting: 'text-accent',
  ended: 'text-text-muted',
}

function StatusBadge({ tone, label }: { tone: StatusTone; label: string }) {
  return (
    <p className={`flex items-center gap-2 font-bold ${toneClass[tone]}`}>
      <span aria-hidden className="size-2 bg-current" />
      <span className="sr-only">Status: </span>
      {label}
    </p>
  )
}

function Shell({ title, children }: { title: string; children: ReactNode }) {
  return (
    <PageContainer className="flex flex-col items-start gap-4 py-8">
      <title>{`${title} · Intersheba`}</title>
      <h1 className="text-3xl font-extrabold">Your orders</h1>
      {children}
    </PageContainer>
  )
}
