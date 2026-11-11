import { describe, expect, it } from 'vitest'
import { isInternalPath } from './paths'

describe('isInternalPath', () => {
  it.each(['/store', '/store?platform=steam', '/store?q=split+fiction', '/product/elden-ring', '/'])('allows %s', (link) => {
    expect(isInternalPath(link)).toBe(true)
  })

  it.each([
    'https://example.com',
    'http://example.com/store',
    '//example.com',
    '/\\example.com',
    'javascript:alert(1)',
    'store',
    '',
  ])('rejects %s', (link) => {
    expect(isInternalPath(link)).toBe(false)
  })
})
