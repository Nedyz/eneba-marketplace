/**
 * The store's state lives in the URL (PLAN.md, D29). This module converts
 * between the browser URL and a typed, always-valid StoreParams object.
 *
 * The URL is untrusted input (hand-edited, shared, old bookmarks), and the API
 * answers 422 to malformed values. So parsing never fails: anything invalid is
 * dropped or repaired, like a lenient model binder in ASP.NET. Unknown slugs
 * are kept: the API treats them as "matches nothing" (D24), which is correct.
 *
 * The URL form is canonical (defaults omitted, fixed order, sorted slugs), so
 * equal states give equal URLs and one TanStack Query cache entry.
 */

/** Same values as the backend's ProductSort enum. */
export const STORE_SORTS = ['relevance', 'newest', 'price_asc', 'price_desc', 'name'] as const
export type StoreSort = (typeof STORE_SORTS)[number]

export interface StoreParams {
  /** Search term, normalized; null = no search. */
  q: string | null
  /** Platform slugs, deduplicated and sorted. */
  platforms: string[]
  /** Region slugs, deduplicated and sorted. */
  regions: string[]
  /** Whole euros (the API gets cents, see api/products.ts). */
  priceMin: number | null
  priceMax: number | null
  sort: StoreSort
  /** 1-based. */
  page: number
}

/** Backend limits (ProductIndexRequest), so a cleaned URL never gets a 422. */
export const MAX_QUERY_LENGTH = 100
const MAX_SLUGS = 20
const MAX_SLUG_LENGTH = 100
/** 100,000,000 cents, the backend's MAX_PRICE_CENTS. */
const MAX_PRICE_EUROS = 1_000_000

/** Relevance when searching, newest otherwise (the backend's default, D28). */
export function defaultSort(q: string | null): StoreSort {
  return q === null ? 'newest' : 'relevance'
}

/**
 * Trims, collapses inner whitespace and caps the length, like the backend's
 * normalizeSearch. Also used by the header search box. Empty means no search.
 */
export function normalizeQuery(raw: string | null | undefined): string | null {
  if (raw == null) return null
  // Trim again after slicing: the cut can end on a space.
  const q = raw.replace(/\s+/g, ' ').trim().slice(0, MAX_QUERY_LENGTH).trim()
  return q === '' ? null : q
}

function parseSlugs(values: string[]): string[] {
  const slugs = values.map((value) => value.trim()).filter((value) => value !== '' && value.length <= MAX_SLUG_LENGTH)
  // Sorted, so ?platform=steam&platform=gog and the reverse are one state.
  return [...new Set(slugs)].sort().slice(0, MAX_SLUGS)
}

/** Whole euros only: "30" is valid, "30.5", "-1", "1e3" and "" are not. */
function parseEuros(value: string | null): number | null {
  if (value === null || !/^\d{1,7}$/.test(value)) return null
  const euros = Number(value)
  return euros <= MAX_PRICE_EUROS ? euros : null
}

function parsePage(value: string | null): number {
  if (value === null || !/^\d{1,9}$/.test(value)) return 1
  return Math.max(1, Number(value))
}

export function parseStoreParams(searchParams: URLSearchParams): StoreParams {
  const q = normalizeQuery(searchParams.get('q'))

  let priceMin = parseEuros(searchParams.get('price_min'))
  let priceMax = parseEuros(searchParams.get('price_max'))
  // The API rejects max < min; the user most likely swapped the two fields.
  if (priceMin !== null && priceMax !== null && priceMin > priceMax) {
    ;[priceMin, priceMax] = [priceMax, priceMin]
  }

  const rawSort = searchParams.get('sort')
  const isKnownSort = (STORE_SORTS as readonly (string | null)[]).includes(rawSort)
  // Relevance needs a search term; the API falls back to newest, so do we.
  const sort = isKnownSort && !(rawSort === 'relevance' && q === null) ? (rawSort as StoreSort) : defaultSort(q)

  return {
    q,
    platforms: parseSlugs(searchParams.getAll('platform')),
    regions: parseSlugs(searchParams.getAll('region')),
    priceMin,
    priceMax,
    sort,
    page: parsePage(searchParams.get('page')),
  }
}

/** The canonical URL query for a state: fixed order, defaults left out. */
export function toSearchParams(params: StoreParams): URLSearchParams {
  const search = new URLSearchParams()
  if (params.q !== null) search.set('q', params.q)
  for (const slug of params.platforms) search.append('platform', slug)
  for (const slug of params.regions) search.append('region', slug)
  if (params.priceMin !== null) search.set('price_min', String(params.priceMin))
  if (params.priceMax !== null) search.set('price_max', String(params.priceMax))
  if (params.sort !== defaultSort(params.q)) search.set('sort', params.sort)
  if (params.page > 1) search.set('page', String(params.page))
  return search
}

/**
 * Applies a change and returns the canonical next state. Any change other than
 * the page itself goes back to page 1 (page 5 of the old result set may not
 * exist in the new one). The result goes through the URL form, so it is
 * cleaned exactly like a URL typed by hand: a single set of rules.
 */
export function applyStoreChange(current: StoreParams, change: Partial<StoreParams>): StoreParams {
  // E.g. clearing the search while sorted by relevance: the parse step turns
  // "relevance without q" into newest, as for a hand-typed URL.
  return parseStoreParams(toSearchParams({ ...current, page: 1, ...change }))
}

/** A store URL containing only a search term (the header search, a fresh search). */
export function storeSearchUrl(q: string): string {
  return `/store?${new URLSearchParams({ q })}`
}

export function hasActiveFilters(params: StoreParams): boolean {
  return params.platforms.length > 0 || params.regions.length > 0 || params.priceMin !== null || params.priceMax !== null
}
