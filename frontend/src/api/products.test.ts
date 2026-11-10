import { describe, expect, it } from 'vitest'
import { parseStoreParams } from '../features/store/storeParams'
import { toApiQuery } from './products'

const apiQueryFor = (browserQuery: string) => toApiQuery(parseStoreParams(new URLSearchParams(browserQuery))).toString()

describe('toApiQuery', () => {
  it('translates the browser URL to Laravel conventions: brackets for arrays, prices in cents', () => {
    expect(apiQueryFor('q=witcher&platform=steam&platform=gog&region=europe&price_min=5&price_max=30&page=2')).toBe(
      'q=witcher&platform%5B%5D=gog&platform%5B%5D=steam&region%5B%5D=europe&price_min=500&price_max=3000&sort=relevance&page=2',
    )
  })

  it('always sends sort and page, so the API never has to guess a default', () => {
    expect(apiQueryFor('')).toBe('sort=newest&page=1')
  })
})
