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

/**
 * Mirrors backend ProductDetailResource: the card's fields (same `price`, from
 * the same backend code) plus the description and the offers.
 */
export interface ProductDetail extends ProductSummary {
  description: string
  /** Display order from the API: in stock first, then price, then offer id (D30). */
  offers: Offer[]
}

/** Mirrors backend OfferResource: one seller's price for a product. */
export interface Offer {
  id: number
  seller: { id: number; name: string; rating: number }
  price_cents: number
  original_price_cents: number | null
  discount_percent: number | null
  currency: 'EUR'
  /** Number of sellable keys; 0 = sold out. */
  stock: number
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

/** Rejects with ApiError status 404 for an unknown slug. */
export function getProduct(slug: string, signal?: AbortSignal): Promise<ProductDetail> {
  // encodeURIComponent: the slug comes from the browser URL (untrusted), and must
  // stay one path segment ("../health" must not reach another endpoint).
  return apiGet<{ data: ProductDetail }>(`/products/${encodeURIComponent(slug)}`, { signal }).then(
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
  detail: (slug: string) => ['products', 'detail', slug] as const,
}
