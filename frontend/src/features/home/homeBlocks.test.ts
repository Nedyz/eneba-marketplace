import { describe, expect, it } from 'vitest'
import type { HomeBlock } from '../../api/home'
import { renderableBlocks } from './homeBlocks'

const block = (id: number, type: string): HomeBlock => ({ id, type, title: null, endpoint: `/home/${id}` })
const registry = { banner: 'BannerBlock', product_carousel: 'ProductCarouselBlock' }

describe('renderableBlocks', () => {
  it('keeps known blocks in API order, with their component', () => {
    const blocks = [block(3, 'product_carousel'), block(1, 'banner'), block(2, 'product_carousel')]

    expect(renderableBlocks(blocks, registry)).toEqual([
      { block: blocks[0], Component: 'ProductCarouselBlock' },
      { block: blocks[1], Component: 'BannerBlock' },
      { block: blocks[2], Component: 'ProductCarouselBlock' },
    ])
  })

  it('skips unknown types instead of failing', () => {
    const result = renderableBlocks([block(1, 'video_hero'), block(2, 'banner'), block(3, '')], registry)

    expect(result.map((r) => r.block.id)).toEqual([2])
  })

  it('does not treat inherited object properties as block types', () => {
    const blocks = ['constructor', 'toString', 'hasOwnProperty', '__proto__'].map((type, i) => block(i, type))

    expect(renderableBlocks(blocks, registry)).toEqual([])
  })

  it('returns nothing for an empty layout', () => {
    expect(renderableBlocks([], registry)).toEqual([])
  })
})
