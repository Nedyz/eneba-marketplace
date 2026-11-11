import { Link } from 'react-router'
import type { QuoteLine } from '../../api/cart'
import { ProductCover } from '../../components/ui/ProductCover'
import { formatPrice } from '../../lib/format'
import { type CartLine, MAX_QTY } from './cartStorage'
import { cartActions } from './cartStore'
import type { PriceChange } from './priceChanges'

interface CartLineItemProps {
  line: CartLine
  /** This offer's quote line, matched by offer id. Undefined until the first quote arrives. */
  quoted: QuoteLine | undefined
  priceChange: PriceChange | undefined
}

/**
 * One cart line. Names come from the quote when there is one, otherwise from
 * the snapshot (before the first quote, or when the offer was deleted).
 * Prices only ever come from the quote: the snapshot price is never shown.
 */
export function CartLineItem({ line, quoted, priceChange }: CartLineItemProps) {
  const name = quoted?.product?.name ?? line.seen.productName
  const slug = quoted?.product?.slug ?? line.seen.productSlug
  const sellerName = quoted?.seller?.name ?? line.seen.sellerName
  const productUrl = `/product/${encodeURIComponent(slug)}`

  // While a new quote loads, the previous one is still shown (keepPreviousData).
  // If it was for a different quantity, its status and total do not apply yet.
  const current = quoted !== undefined && quoted.qty === line.qty ? quoted : undefined
  const status = current?.status
  const blocked = status === 'sold_out' || status === 'unavailable'

  return (
    <li
      className={`flex gap-4 p-4 md:p-5 ${
        // Same treatment as sold-out offers on the product page (D30): no card
        // background and muted text instead of opacity, so contrast stays AA.
        blocked ? 'border border-white/15 text-text-muted' : 'bg-surface'
      }`}
    >
      <ProductCover platform={quoted?.product?.platform} imageUrl={quoted?.product?.image_url ?? null} size="md" />

      <div className="flex min-w-0 flex-1 flex-col gap-3">
        <div className="flex flex-wrap items-start justify-between gap-x-6 gap-y-2">
          <div className="flex min-w-0 flex-col gap-1">
            <h3 className="font-extrabold">
              <Link to={productUrl} className="hover:underline">
                {name}
              </Link>
            </h3>
            <p className="text-sm text-text-soft">
              {quoted?.product && (
                <>
                  {quoted.product.platform.name} · {quoted.product.region.name} ·{' '}
                </>
              )}
              Sold by {sellerName}
            </p>
          </div>
          <LinePrice line={line} quoted={quoted} current={current} />
        </div>

        <div className="flex flex-wrap items-center gap-x-6 gap-y-2">
          {blocked ? (
            <p className="text-sm font-semibold">Quantity: {line.qty}</p>
          ) : (
            <QuantityStepper
              name={name}
              qty={line.qty}
              // An insufficient line can go down but not up: its qty is already above the stock.
              max={Math.min(MAX_QTY, quoted?.stock ?? MAX_QTY)}
              onChange={(qty) => cartActions.setQty(line.offerId, qty)}
            />
          )}
          <button
            type="button"
            onClick={() => cartActions.remove(line.offerId)}
            className="text-sm font-semibold underline hover:text-text"
          >
            Remove<span className="sr-only"> {name}</span>
          </button>
        </div>

        <LineNotes line={line} current={current} productUrl={productUrl} name={name} priceChange={priceChange} />
      </div>
    </li>
  )
}

function LinePrice({ line, quoted, current }: { line: CartLine; quoted: QuoteLine | undefined; current: QuoteLine | undefined }) {
  // No quote yet: a placeholder, never the snapshot price.
  if (quoted === undefined) {
    return <div aria-hidden className="h-7 w-20 animate-pulse bg-white/10" />
  }
  if (quoted.unit_price_cents === null) {
    return null // unavailable: there is no price to show
  }

  return (
    <div className="flex flex-col items-end">
      {current?.line_total_cents != null ? (
        <p className="text-price font-extrabold">{formatPrice(current.line_total_cents)}</p>
      ) : (
        // Not buyable as is, or a new quantity is being checked: no line total.
        <p className="text-price font-extrabold text-text-muted">{formatPrice(quoted.unit_price_cents)}</p>
      )}
      {(line.qty > 1 || current?.line_total_cents == null) && (
        <p className="text-xs text-text-muted">{formatPrice(quoted.unit_price_cents)} each</p>
      )}
    </div>
  )
}

function LineNotes({
  line,
  current,
  productUrl,
  name,
  priceChange,
}: {
  line: CartLine
  current: QuoteLine | undefined
  productUrl: string
  name: string
  priceChange: PriceChange | undefined
}) {
  const otherSellers = (
    <Link to={productUrl} className="font-extrabold text-text underline">
      See other sellers<span className="sr-only"> for {name}</span>
    </Link>
  )

  return (
    <>
      {priceChange && (
        <p className={`text-sm font-semibold ${priceChange.toCents > priceChange.fromCents ? 'text-accent' : 'text-discount'}`}>
          {priceChange.toCents > priceChange.fromCents ? 'Price went up' : 'Price dropped'} from{' '}
          {formatPrice(priceChange.fromCents)} to {formatPrice(priceChange.toCents)} since you last saw it.
        </p>
      )}
      {current?.status === 'insufficient_stock' && (
        <p className="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm font-semibold text-accent">
          Only {current.stock} available right now.
          <button
            type="button"
            onClick={() => cartActions.setQty(line.offerId, current.stock)}
            className="bg-accent px-3 py-1 font-extrabold text-black hover:bg-accent-hover"
          >
            Change to {current.stock}
          </button>
        </p>
      )}
      {current?.status === 'sold_out' && (
        <p className="flex flex-wrap gap-x-3 text-sm font-semibold">
          <span>Out of stock at this seller right now.</span>
          {otherSellers}
        </p>
      )}
      {current?.status === 'unavailable' && (
        <p className="flex flex-wrap gap-x-3 text-sm font-semibold">
          <span>No longer sold by this seller.</span>
          {otherSellers}
        </p>
      )}
    </>
  )
}

function QuantityStepper({
  name,
  qty,
  max,
  onChange,
}: {
  name: string
  qty: number
  max: number
  onChange: (qty: number) => void
}) {
  const buttonClass = 'size-9 text-lg font-extrabold hover:bg-white/10 disabled:text-text-muted disabled:hover:bg-transparent'

  return (
    <div role="group" aria-label={`Quantity of ${name}`} className="flex items-center border border-white/30">
      <button type="button" className={buttonClass} disabled={qty <= 1} onClick={() => onChange(qty - 1)} aria-label="Decrease quantity">
        −
      </button>
      {/* Announced when it changes, so screen reader users hear the new quantity. */}
      <output aria-live="polite" className="w-8 text-center font-semibold">
        {qty}
      </output>
      <button type="button" className={buttonClass} disabled={qty >= max} onClick={() => onChange(qty + 1)} aria-label="Increase quantity">
        +
      </button>
    </div>
  )
}
