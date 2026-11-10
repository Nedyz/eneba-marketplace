import { describe, expect, it } from 'vitest'
import { pageWindow } from './pageWindow'

describe('pageWindow', () => {
  it.each([
    [1, 1, [1]],
    [1, 2, [1, 2]],
    [1, 5, [1, 2, 'gap', 5]],
    [3, 5, [1, 2, 3, 4, 5]],
    [3, 12, [1, 2, 3, 4, 'gap', 12]],
    // A gap would hide only page 2 or 4, so they are shown instead.
    [4, 12, [1, 2, 3, 4, 5, 'gap', 12]],
    [6, 12, [1, 'gap', 5, 6, 7, 'gap', 12]],
    [12, 12, [1, 'gap', 11, 12]],
  ] as const)('page %i of %i', (current, last, expected) => {
    expect(pageWindow(current, last)).toEqual(expected)
  })

  it('handles a current page past the end (an old link): only real pages', () => {
    expect(pageWindow(40, 3)).toEqual([1, 2, 3])
  })
})
