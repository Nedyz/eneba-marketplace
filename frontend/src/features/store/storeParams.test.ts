import { describe, expect, it } from 'vitest'
import { applyStoreChange, parseStoreParams, type StoreParams, toSearchParams } from './storeParams'

const parse = (query: string) => parseStoreParams(new URLSearchParams(query))

const defaults: StoreParams = {
  q: null,
  platforms: [],
  regions: [],
  priceMin: null,
  priceMax: null,
  sort: 'newest',
  page: 1,
}

describe('parseStoreParams', () => {
  it('returns the defaults for an empty URL', () => {
    expect(parse('')).toEqual(defaults)
  })

  it('reads every parameter', () => {
    expect(parse('q=witcher&platform=steam&region=europe&price_min=5&price_max=30&sort=price_asc&page=3')).toEqual({
      q: 'witcher',
      platforms: ['steam'],
      regions: ['europe'],
      priceMin: 5,
      priceMax: 30,
      sort: 'price_asc',
      page: 3,
    })
  })

  // Each invalid value is repaired on its own; the rest of the URL still applies.
  it.each([
    ['page=abc', { page: 1 }],
    ['page=0', { page: 1 }],
    ['page=-2', { page: 1 }],
    ['page=2.5', { page: 1 }],
    ['page=1e3', { page: 1 }],
    ['page=99999999999', { page: 1 }],
    ['sort=cheapest', { sort: 'newest' }],
    ['price_min=abc', { priceMin: null }],
    ['price_min=-5', { priceMin: null }],
    ['price_min=19.99', { priceMin: null }],
    ['price_max=', { priceMax: null }],
    ['price_max=2000000', { priceMax: null }],
    ['platform=&platform=%20', { platforms: [] }],
    [`platform=${'x'.repeat(101)}`, { platforms: [] }],
  ])('repairs %s', (query, expected) => {
    expect(parse(`${query}&region=europe`)).toEqual({ ...defaults, regions: ['europe'], ...expected })
  })

  it('swaps a minimum price above the maximum (the API would answer 422)', () => {
    expect(parse('price_min=50&price_max=10')).toMatchObject({ priceMin: 10, priceMax: 50 })
  })

  it('keeps an equal minimum and maximum', () => {
    expect(parse('price_min=20&price_max=20')).toMatchObject({ priceMin: 20, priceMax: 20 })
  })

  it('deduplicates and sorts slugs, so the order in the URL does not matter', () => {
    expect(parse('platform=steam&platform=gog&platform=steam').platforms).toEqual(['gog', 'steam'])
    expect(parse('platform=gog&platform=steam')).toEqual(parse('platform=steam&platform=gog'))
  })

  it('keeps at most 20 slugs (backend limit)', () => {
    const query = Array.from({ length: 25 }, (_, i) => `region=r${String(i).padStart(2, '0')}`).join('&')
    expect(parse(query).regions).toHaveLength(20)
  })

  it('keeps unknown slugs: the API treats them as matching nothing (D24)', () => {
    expect(parse('platform=sega-dreamcast').platforms).toEqual(['sega-dreamcast'])
  })

  describe('search term', () => {
    it.each([
      ['q=%20%20elden%20%20%20ring%20', 'elden ring'],
      ['q=elden%09ring', 'elden ring'],
      ['q=', null],
      ['q=%20%20%20', null],
    ])('normalizes %s', (query, expected) => {
      expect(parse(query).q).toBe(expected)
    })

    it('caps the term at 100 characters without a trailing space', () => {
      const q = parse(`q=${'a'.repeat(99)}%20bbbb`).q
      expect(q).toBe('a'.repeat(99))
    })
  })

  describe('sort', () => {
    it('defaults to relevance when searching and newest otherwise', () => {
      expect(parse('q=mario').sort).toBe('relevance')
      expect(parse('').sort).toBe('newest')
    })

    it('falls back to newest for relevance without a search term, like the API (D28)', () => {
      expect(parse('sort=relevance').sort).toBe('newest')
      expect(parse('q=%20&sort=relevance').sort).toBe('newest')
    })

    it('keeps an explicit sort while searching', () => {
      expect(parse('q=mario&sort=price_desc').sort).toBe('price_desc')
    })
  })
})

describe('toSearchParams', () => {
  it('writes nothing for the defaults', () => {
    expect(toSearchParams(defaults).toString()).toBe('')
  })

  it('leaves out the default sort for the current search state', () => {
    expect(toSearchParams({ ...defaults, q: 'mario', sort: 'relevance' }).toString()).toBe('q=mario')
    expect(toSearchParams({ ...defaults, q: 'mario', sort: 'newest' }).toString()).toBe('q=mario&sort=newest')
  })

  it('writes parameters in a fixed order, with repeated keys for slugs', () => {
    const params: StoreParams = {
      q: 'witcher 3',
      platforms: ['gog', 'steam'],
      regions: ['global'],
      priceMin: 5,
      priceMax: 30,
      sort: 'price_asc',
      page: 2,
    }
    expect(toSearchParams(params).toString()).toBe(
      'q=witcher+3&platform=gog&platform=steam&region=global&price_min=5&price_max=30&sort=price_asc&page=2',
    )
  })

  // Round trip: canonical URL -> params -> the same URL. This is what makes
  // equal states share one URL and one query cache entry.
  it.each([
    '',
    'q=mario',
    'q=mario&sort=newest',
    'platform=gog&platform=steam&region=europe',
    'price_min=0&price_max=0',
    'price_max=30&sort=price_desc&page=4',
  ])('round-trips %s unchanged', (query) => {
    expect(toSearchParams(parse(query)).toString()).toBe(query)
  })

  it('canonicalizes a messy URL', () => {
    expect(toSearchParams(parse('page=1&sort=newest&platform=steam&platform=gog&price_min=40&price_max=10')).toString()).toBe(
      'platform=gog&platform=steam&price_min=10&price_max=40',
    )
  })
})

describe('applyStoreChange', () => {
  const onPage3: StoreParams = { ...defaults, platforms: ['steam'], page: 3 }

  it('goes back to page 1 when a filter changes', () => {
    expect(applyStoreChange(onPage3, { regions: ['europe'] })).toEqual({ ...onPage3, regions: ['europe'], page: 1 })
  })

  it('goes back to page 1 when the sort changes', () => {
    expect(applyStoreChange(onPage3, { sort: 'name' }).page).toBe(1)
  })

  it('keeps the filters when only the page changes', () => {
    expect(applyStoreChange(onPage3, { page: 4 })).toEqual({ ...onPage3, page: 4 })
  })

  it('canonicalizes the change like a URL (sorted slugs, swapped prices)', () => {
    expect(applyStoreChange(defaults, { platforms: ['steam', 'gog'], priceMin: 50, priceMax: 10 })).toMatchObject({
      platforms: ['gog', 'steam'],
      priceMin: 10,
      priceMax: 50,
    })
  })

  it('drops relevance when the search is cleared', () => {
    const searching: StoreParams = { ...defaults, q: 'mario', sort: 'relevance' }
    expect(applyStoreChange(searching, { q: null }).sort).toBe('newest')
  })

  it('keeps an explicit sort when the search is cleared', () => {
    const searching: StoreParams = { ...defaults, q: 'mario', sort: 'price_asc' }
    expect(applyStoreChange(searching, { q: null }).sort).toBe('price_asc')
  })
})
