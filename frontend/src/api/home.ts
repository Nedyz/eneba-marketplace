import { apiGet } from './client'

/**
 * Server-driven homepage (D4). GET /home says which blocks to show, in which
 * order and with which titles; each block then fetches its own `endpoint`.
 * Mirrors backend HomeBlockResource.
 */
export interface HomeBlock {
  id: number
  /** Unknown types are skipped by the homepage, never an error. */
  type: string
  title: string | null
  /** Path relative to the API base, e.g. "/home/deals". */
  endpoint: string
}

/** Mirrors backend BannerResource. `link_url` is a frontend route. */
export interface Banner {
  id: number
  title: string
  subtitle: string | null
  image_url: string | null
  cta_label: string
  link_url: string
}

export function getHomeLayout(signal?: AbortSignal): Promise<HomeBlock[]> {
  return apiGet<{ blocks: HomeBlock[] }>('/home', { signal }).then((response) => response.blocks)
}

/**
 * Content of one block. The endpoint comes from the database, so it is only
 * ever requested through apiGet: that rejects anything not starting with "/",
 * and appends the path to the API base URL, so even "//other.host" stays on the
 * API host. The element type is the block type's contract (see the backend's
 * HomeController): banner -> Banner, product_carousel -> ProductSummary.
 */
export function getBlockContent<T>(endpoint: string, signal?: AbortSignal): Promise<T[]> {
  return apiGet<{ data: T[] }>(endpoint, { signal }).then((response) => response.data)
}

export const homeQueryKeys = {
  layout: () => ['home', 'layout'] as const,
  /** By endpoint: two blocks pointing at the same endpoint share one request. */
  block: (endpoint: string) => ['home', 'block', endpoint] as const,
}
