import { useQuery } from '@tanstack/react-query'
import type { ReactNode } from 'react'
import { Link, useParams } from 'react-router'
import { ApiError } from '../../api/client'
import { getProduct, productQueryKeys } from '../../api/products'
import { PageContainer } from '../../components/layout/PageContainer'
import { Price } from '../../components/ui/Price'
import { ProductCover } from '../../components/ui/ProductCover'
import { formatDate } from '../../lib/format'
import { OfferList } from './OfferList'

const isNotFound = (error: unknown) => error instanceof ApiError && error.status === 404

/**
 * /product/:slug: one edition (game + platform + region) and every seller's
 * offer for it (D5, D30). Add to cart comes with the cart (next session).
 */
export function ProductPage() {
  // Always defined on this route; the fallback only satisfies the type.
  const { slug = '' } = useParams()

  const product = useQuery({
    queryKey: productQueryKeys.detail(slug),
    queryFn: ({ signal }) => getProduct(slug, signal),
    // A 404 will not change on retry; other errors (network, cold start) get
    // the app-wide single retry.
    retry: (failureCount, error) => !isNotFound(error) && failureCount < 1,
  })

  if (product.isPending) {
    return <ProductPageSkeleton />
  }

  if (product.isError) {
    return isNotFound(product.error) ? (
      <Message title="Game not found">
        <title>Game not found · Intersheba</title>
        <p className="text-text-soft">This link may be outdated, or the edition is no longer sold.</p>
        <Link to="/store" className="font-extrabold underline">
          Browse all games
        </Link>
      </Message>
    ) : (
      <Message title="Could not load this game">
        <button
          type="button"
          onClick={() => product.refetch()}
          className="bg-accent px-5 py-3 text-button font-extrabold text-black hover:bg-accent-hover"
        >
          Try again
        </button>
      </Message>
    )
  }

  const game = product.data
  const inStockCount = game.offers.filter((offer) => offer.stock > 0).length

  return (
    <PageContainer className="py-8">
      {/* React 19 moves <title> into <head>. */}
      <title>{`${game.name} (${game.platform.name}, ${game.region.name}) · Intersheba`}</title>

      <article className="flex flex-col gap-10">
        <header className="flex flex-col gap-6 sm:flex-row sm:items-start md:gap-10">
          <div className="w-40 shrink-0 sm:w-56 md:w-64">
            <ProductCover name={game.name} platformSlug={game.platform.slug} imageUrl={game.image_url} />
          </div>

          <div className="flex min-w-0 flex-col gap-4">
            <h1 className="text-3xl font-extrabold md:text-4xl">{game.name}</h1>
            <dl className="flex flex-wrap gap-x-8 gap-y-2 text-sm">
              <Fact label="Platform">
                <Link to={`/store?platform=${encodeURIComponent(game.platform.slug)}`} className="underline">
                  {game.platform.name}
                </Link>
              </Fact>
              <Fact label="Region">{game.region.name}</Fact>
              {game.release_date && <Fact label="Release date">{formatDate(game.release_date)}</Fact>}
            </dl>
            <Price price={game.price} />
            <p className="max-w-prose text-text-soft">{game.description}</p>
          </div>
        </header>

        <section aria-labelledby="offers-heading" className="flex flex-col gap-4">
          <div className="flex flex-wrap items-baseline gap-x-4 gap-y-1">
            <h2 id="offers-heading" className="text-2xl font-extrabold">
              Offers
            </h2>
            {game.offers.length > 0 && (
              <p className="text-sm font-semibold text-text-soft">
                {game.offers.length} {game.offers.length === 1 ? 'seller' : 'sellers'}, {inStockCount} in stock
              </p>
            )}
          </div>
          <OfferList offers={game.offers} />
        </section>
      </article>
    </PageContainer>
  )
}

function Fact({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div className="flex gap-2">
      <dt className="text-text-muted">{label}</dt>
      <dd className="font-semibold">{children}</dd>
    </div>
  )
}

function Message({ title, children }: { title: string; children: ReactNode }) {
  return (
    <PageContainer className="py-8">
      <div className="flex flex-col items-start gap-4 bg-surface p-8">
        <h1 className="text-2xl font-extrabold">{title}</h1>
        {children}
      </div>
    </PageContainer>
  )
}

/** Same layout as the loaded page, so nothing jumps when the data arrives. */
function ProductPageSkeleton() {
  return (
    <PageContainer className="py-8">
      <div aria-busy className="flex flex-col gap-10">
        <span className="sr-only" role="status">
          Loading game
        </span>
        <div aria-hidden className="flex flex-col gap-6 sm:flex-row md:gap-10">
          <div className="aspect-[3/4] w-40 shrink-0 animate-pulse bg-white/5 sm:w-56 md:w-64" />
          <div className="flex flex-1 flex-col gap-4">
            <div className="h-9 w-2/3 animate-pulse bg-white/10" />
            <div className="h-4 w-1/2 animate-pulse bg-white/10" />
            <div className="h-10 w-28 animate-pulse bg-white/10" />
            <div className="h-16 w-full max-w-prose animate-pulse bg-white/10" />
          </div>
        </div>
        <div aria-hidden className="flex flex-col gap-3">
          {Array.from({ length: 3 }, (_, i) => (
            <div key={i} className="h-20 animate-pulse bg-surface" />
          ))}
        </div>
      </div>
    </PageContainer>
  )
}
