import type { ComponentType } from 'react'
import type { HomeBlock } from '../../api/home'
import { BannerBlock } from './BannerBlock'
import { ProductCarouselBlock } from './ProductCarouselBlock'

/**
 * Block type -> component: the only homepage knowledge in the frontend (D4).
 * Which blocks appear, their order, titles and data all come from the API.
 * Like a DI registration keyed by name: adding a block TYPE means one line
 * here; adding a block of an existing type means none.
 */
export const blockRegistry: Record<string, ComponentType<{ block: HomeBlock }>> = {
  banner: BannerBlock,
  product_carousel: ProductCarouselBlock,
}
