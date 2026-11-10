import type { StoreSort } from './storeParams'

const LABELS: Record<StoreSort, string> = {
  relevance: 'Best match',
  newest: 'Newest',
  price_asc: 'Price: low to high',
  price_desc: 'Price: high to low',
  name: 'Name: A to Z',
}

/** "Best match" (relevance) only exists while searching (D28). */
export function SortSelect(props: { value: StoreSort; searching: boolean; onChange: (sort: StoreSort) => void }) {
  const { value, searching, onChange } = props
  const sorts = (Object.keys(LABELS) as StoreSort[]).filter((sort) => searching || sort !== 'relevance')

  return (
    <label className="flex items-center gap-3 text-sm font-semibold text-text-soft">
      Sort by
      <select
        value={value}
        onChange={(event) => onChange(event.target.value as StoreSort)}
        className="border border-white/40 bg-bg px-3 py-2 font-extrabold text-text"
      >
        {sorts.map((sort) => (
          <option key={sort} value={sort}>
            {LABELS[sort]}
          </option>
        ))}
      </select>
    </label>
  )
}
