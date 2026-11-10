import type { ProductPrice } from '../../api/products'
import { formatPrice } from '../../lib/format'

/**
 * "From €19.99", the crossed-out original price and the discount badge, or
 * "Out of stock" when no offer has a sellable key (price null, D24).
 * Shared by product cards and search suggestions.
 */
export function Price({ price, size = 'lg' }: { price: ProductPrice | null; size?: 'sm' | 'lg' }) {
  if (price === null) {
    return <p className="text-sm font-semibold text-text-muted">Out of stock</p>
  }

  const hasDiscount = price.original_cents !== null && price.discount_percent !== null && price.discount_percent > 0

  return (
    <div>
      <p className="flex items-center gap-2 text-xs text-text-muted">
        <span>From</span>
        {hasDiscount && (
          <>
            <s>
              <span className="sr-only">Was </span>
              {formatPrice(price.original_cents!)}
            </s>
            <span className="font-extrabold text-discount">-{price.discount_percent}%</span>
          </>
        )}
      </p>
      <p className={`font-extrabold ${size === 'lg' ? 'text-price' : 'text-base'}`}>{formatPrice(price.from_cents)}</p>
    </div>
  )
}
