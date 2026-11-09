import { apiGet } from './client'

/** Mirrors GET /api/products/filters (backend CatalogFilterOptions). */
export interface FilterOption {
  slug: string
  name: string
}

export interface FilterOptions {
  platforms: FilterOption[]
  regions: FilterOption[]
  price: { min_cents: number | null; max_cents: number | null }
}

export function getFilterOptions(): Promise<FilterOptions> {
  return apiGet<FilterOptions>('/products/filters')
}

/** Shared query key, so the header and (day 3) the store page use one cached response. */
export const filterOptionsQueryKey = ['products', 'filters'] as const
