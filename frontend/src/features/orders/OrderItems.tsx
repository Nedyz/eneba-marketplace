import { Link } from 'react-router'
import type { Order } from '../../api/orders'
import { formatPrice } from '../../lib/format'
import { OrderKeys } from './OrderKeys'

/**
 * An order's lines and total, as the server recorded them (names and prices
 * copied at purchase time). Keys are listed under each line once paid.
 */
export function OrderItems({ order }: { order: Order }) {
  return (
    <div className="flex flex-col gap-3">
      <ul aria-label="Items in this order" className="flex flex-col gap-3">
        {order.items.map((item) => (
          <li key={item.offer_id} className="flex flex-col gap-3 bg-surface p-4 md:p-5">
            <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
              <div className="flex flex-col gap-1">
                <Link to={`/product/${encodeURIComponent(item.product_slug)}`} className="font-bold hover:underline">
                  {item.product_name}
                </Link>
                <p className="text-sm text-text-muted">
                  {item.platform} · {item.region} · Sold by {item.seller_name}
                </p>
              </div>
              <p className="text-right">
                <span className="text-sm text-text-muted">
                  {item.quantity} × {formatPrice(item.unit_price_cents)}
                </span>{' '}
                <span className="font-extrabold">{formatPrice(item.line_total_cents)}</span>
              </p>
            </div>
            {item.keys && <OrderKeys productName={item.product_name} keys={item.keys} />}
          </li>
        ))}
      </ul>
      <p className="flex items-baseline justify-between gap-4 px-1">
        <span className="font-semibold">Total</span>
        <span className="text-price font-extrabold">{formatPrice(order.total_cents)}</span>
      </p>
    </div>
  )
}
