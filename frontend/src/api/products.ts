import type { StoreParams } from '../features/store/storeParams'
import type { FilterOption } from './catalog'
import { apiGet } from './client'

/** Mirrors backend ProductListResource: a product card (store grid, suggestions). */
export interface ProductSummary {
  id: number
  slug: string
  name: string
  image_url: string | null
  release_date: string | null
  platform: FilterOption
  region: FilterOption
  /** null = out of stock (D24). */
  price: ProductPrice | null
}

export interface ProductPrice {
  from_cents: number
  original_cents: number | null
  discount_percent: number | null
  currency: 'EUR'
}

/** Laravel's paginated resource collection (only the fields we use). */
export interface Paginated<T> {
  data: T[]
  meta: {
    current_page: number
    last_page: number
    per_page: number
    total: number
  }
}

/**
 * StoreParams -> the API's query string. The only place that knows Laravel's
 * conventions: array parameters need brackets (platform[]=steam) and prices
 * are integer cents (D12). The browser URL stays readable (platform=steam,
 * whole euros); see features/store/storeParams.ts.
 */
export function toApiQuery(params: StoreParams): URLSearchParams {
  const query = new URLSearchParams()
  if (params.q !== null) query.set('q', params.q)
  for (const slug of params.platforms) query.append('platform[]', slug)
  for (const slug of params.regions) query.append('region[]', slug)
  if (params.priceMin !== null) query.set('price_min', String(params.priceMin * 100))
  if (params.priceMax !== null) query.set('price_max', String(params.priceMax * 100))
  query.set('sort', params.sort)
  query.set('page', String(params.page))
  return query
}

/**
 * `signal` comes from TanStack Query: when a request is no longer needed (the
 * filters changed again before it finished), the query aborts it.
 */
export function getProducts(params: StoreParams, signal?: AbortSignal): Promise<Paginated<ProductSummary>> {
  return apiGet<Paginated<ProductSummary>>(`/products?${toApiQuery(params)}`, { signal })
}

/** The term must be 2 to 100 characters (backend SearchSuggestionsRequest). */
export function getSuggestions(term: string, signal?: AbortSignal): Promise<ProductSummary[]> {
  return apiGet<{ data: ProductSummary[] }>(`/search/suggestions?${new URLSearchParams({ q: term })}`, { signal }).then(
    (response) => response.data,
  )
}

/**
 * Query keys. The store key holds the parsed, canonical params, so URLs that
 * differ only in order or defaults share one cache entry.
 */
export const productQueryKeys = {
  list: (params: StoreParams) => ['products', 'list', params] as const,
  suggestions: (term: string) => ['search', 'suggestions', term] as const,
}
