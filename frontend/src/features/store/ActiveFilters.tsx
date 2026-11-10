import type { FilterOption, FilterOptions } from '../../api/catalog'
import { hasActiveFilters, type StoreParams } from './storeParams'

/**
 * One removable chip per active filter, plus "Clear filters". The search term
 * is not a chip: it has its own clear button in the page heading.
 */
export function ActiveFilters(props: {
  params: StoreParams
  options: FilterOptions | undefined
  onChange: (change: Partial<StoreParams>) => void
}) {
  const { params, options, onChange } = props
  if (!hasActiveFilters(params)) return null

  // Unknown slugs from an old link have no option: show the slug itself.
  const nameOf = (list: FilterOption[] | undefined, slug: string) => list?.find((o) => o.slug === slug)?.name ?? slug

  const chips = [
    ...params.platforms.map((slug) => ({
      key: `platform-${slug}`,
      label: nameOf(options?.platforms, slug),
      remove: () => onChange({ platforms: params.platforms.filter((s) => s !== slug) }),
    })),
    ...params.regions.map((slug) => ({
      key: `region-${slug}`,
      label: nameOf(options?.regions, slug),
      remove: () => onChange({ regions: params.regions.filter((s) => s !== slug) }),
    })),
  ]
  if (params.priceMin !== null || params.priceMax !== null) {
    chips.push({
      key: 'price',
      label: priceLabel(params.priceMin, params.priceMax),
      remove: () => onChange({ priceMin: null, priceMax: null }),
    })
  }

  return (
    <ul aria-label="Active filters" className="flex flex-wrap items-center gap-2">
      {chips.map((chip) => (
        <li key={chip.key}>
          <button
            type="button"
            onClick={chip.remove}
            aria-label={`Remove filter: ${chip.label}`}
            className="flex items-center gap-2 bg-white/10 px-3 py-1.5 text-sm font-semibold hover:bg-white/20"
          >
            {chip.label}
            <span aria-hidden>×</span>
          </button>
        </li>
      ))}
      <li>
        <button
          type="button"
          onClick={() => onChange({ platforms: [], regions: [], priceMin: null, priceMax: null })}
          className="px-2 py-1.5 text-sm font-semibold text-text-soft underline hover:text-text"
        >
          Clear filters
        </button>
      </li>
    </ul>
  )
}

function priceLabel(min: number | null, max: number | null): string {
  if (min !== null && max !== null) return `€${min} – €${max}`
  if (min !== null) return `From €${min}`
  return `Up to €${max}`
}
