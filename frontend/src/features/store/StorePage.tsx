import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { type ReactNode, useEffect, useRef, useState } from 'react'
import { Link } from 'react-router'
import { filterOptionsQueryKey, getFilterOptions } from '../../api/catalog'
import { getProducts, productQueryKeys } from '../../api/products'
import { PageContainer } from '../../components/layout/PageContainer'
import { ProductCard, ProductCardSkeleton } from '../../components/ui/ProductCard'
import { ActiveFilters } from './ActiveFilters'
import { FilterPanel } from './FilterPanel'
import { Pagination } from './Pagination'
import { SortSelect } from './SortSelect'
import { hasActiveFilters } from './storeParams'
import { useStoreParams } from './useStoreParams'

/**
 * /store: catalog and search results (D6). Every piece of state (search,
 * filters, sort, page) is in the URL; see useStoreParams and D29.
 */
export function StorePage() {
  const { params, update, searchFor } = useStoreParams()
  const [filtersOpen, setFiltersOpen] = useState(false)

  // The key is the parsed params: when the URL changes, the key changes and
  // TanStack Query fetches, or serves the cached response for that state
  // (Back/Forward). keepPreviousData keeps the old grid visible (dimmed) while
  // the next one loads, instead of flashing skeletons on every filter click.
  const products = useQuery({
    queryKey: productQueryKeys.list(params),
    queryFn: ({ signal }) => getProducts(params, signal),
    placeholderData: keepPreviousData,
  })

  // Same key and options as the header's category bar: one shared request.
  const { data: filterOptions } = useQuery({
    queryKey: filterOptionsQueryKey,
    queryFn: getFilterOptions,
    staleTime: 5 * 60_000,
  })

  // A new page starts at the top. Only the page: filter changes keep the
  // scroll position, so the panel the user is clicking in does not jump.
  useEffect(() => {
    window.scrollTo({ top: 0 })
  }, [params.page])

  // Removing a chip or clearing the search removes the button that was just
  // pressed, which would drop keyboard focus to <body> (back to the top of the
  // tab order). Focus moves to the result count instead; it is a live region,
  // so screen readers also hear the new total.
  const resultCountRef = useRef<HTMLParagraphElement>(null)
  const updateAndFocusResults = (change: Parameters<typeof update>[0]) => {
    update(change)
    resultCountRef.current?.focus()
  }

  const meta = products.data?.meta
  const filterCount =
    params.platforms.length + params.regions.length + (params.priceMin !== null || params.priceMax !== null ? 1 : 0)

  return (
    <PageContainer className="py-8">
      <div className="mb-6 flex flex-wrap items-baseline gap-x-4 gap-y-2">
        <h1 className="text-2xl font-extrabold md:text-3xl">
          {params.q === null ? 'All games' : <>Results for “{params.q}”</>}
        </h1>
        {params.q !== null && (
          <button
            type="button"
            onClick={() => updateAndFocusResults({ q: null })}
            className="text-sm font-semibold text-text-soft underline hover:text-text"
          >
            Clear search
          </button>
        )}
      </div>

      <div className="flex flex-col gap-8 md:flex-row">
        <aside aria-label="Filters" className="md:w-60 md:shrink-0">
          {/* Mobile: the panel is collapsed behind a button. One panel in the
              DOM for both layouts, so input ids stay unique. */}
          <button
            type="button"
            aria-expanded={filtersOpen}
            aria-controls="store-filters"
            onClick={() => setFiltersOpen((open) => !open)}
            className="w-full border border-white/40 px-4 py-3 text-left font-extrabold md:hidden"
          >
            Filters{filterCount > 0 && ` (${filterCount})`}
          </button>
          <div id="store-filters" className={`${filtersOpen ? 'mt-4 block' : 'hidden'} md:mt-0 md:block`}>
            <FilterPanel params={params} options={filterOptions} onChange={update} />
          </div>
        </aside>

        <section aria-label="Products" className="min-w-0 flex-1">
          <div className="mb-4 flex flex-wrap items-center justify-between gap-4">
            {/* role=status: screen readers announce the new count after a filter change. */}
            <p ref={resultCountRef} tabIndex={-1} role="status" className="text-sm font-semibold text-text-soft">
              {meta ? `${meta.total} ${meta.total === 1 ? 'game' : 'games'}` : ' '}
            </p>
            <SortSelect value={params.sort} searching={params.q !== null} onChange={(sort) => update({ sort })} />
          </div>

          <div className="mb-6">
            <ActiveFilters params={params} options={filterOptions} onChange={updateAndFocusResults} />
          </div>

          {products.isPending ? (
            <Grid>
              {Array.from({ length: 12 }, (_, i) => (
                <ProductCardSkeleton key={i} />
              ))}
            </Grid>
          ) : products.isError ? (
            <Message title="Could not load games">
              <button
                type="button"
                onClick={() => products.refetch()}
                className="bg-accent px-5 py-3 text-button font-extrabold text-black hover:bg-accent-hover"
              >
                Try again
              </button>
            </Message>
          ) : products.data.data.length === 0 ? (
            meta && meta.total > 0 ? (
              // An old link to a page past the end (results shrank since).
              <Message title={`Page ${params.page} is empty`}>
                <Link to={{ search: searchFor({ page: 1 }) }} className="font-extrabold underline">
                  Go to the first page
                </Link>
              </Message>
            ) : (
              <Message title={params.q === null ? 'No games match these filters' : `No games found for “${params.q}”`}>
                <div className="flex gap-4">
                  {hasActiveFilters(params) && (
                    <button
                      type="button"
                      onClick={() => updateAndFocusResults({ platforms: [], regions: [], priceMin: null, priceMax: null })}
                      className="font-extrabold underline"
                    >
                      Clear filters
                    </button>
                  )}
                  {params.q !== null && (
                    <button type="button" onClick={() => updateAndFocusResults({ q: null })} className="font-extrabold underline">
                      Clear search
                    </button>
                  )}
                </div>
              </Message>
            )
          ) : (
            <>
              <Grid busy={products.isPlaceholderData}>
                {products.data.data.map((product) => (
                  <li key={product.id}>
                    <ProductCard product={product} />
                  </li>
                ))}
              </Grid>
              {meta && (
                <div className="mt-10">
                  <Pagination current={params.page} last={meta.last_page} searchFor={(page) => searchFor({ page })} />
                </div>
              )}
            </>
          )}
        </section>
      </div>
    </PageContainer>
  )
}

function Grid({ children, busy = false }: { children: ReactNode; busy?: boolean }) {
  return (
    <ul
      aria-busy={busy}
      className={`grid grid-cols-2 gap-4 transition-opacity sm:grid-cols-3 lg:grid-cols-4 ${busy ? 'opacity-60' : ''}`}
    >
      {children}
    </ul>
  )
}

function Message({ title, children }: { title: string; children: ReactNode }) {
  return (
    <div className="flex flex-col items-start gap-4 bg-surface p-8">
      <h2 className="text-xl font-extrabold">{title}</h2>
      {children}
    </div>
  )
}
