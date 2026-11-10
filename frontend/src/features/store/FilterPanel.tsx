import { useState } from 'react'
import type { FilterOption, FilterOptions } from '../../api/catalog'
import type { StoreParams } from './storeParams'

interface FilterPanelProps {
  params: StoreParams
  options: FilterOptions | undefined
  onChange: (change: Partial<StoreParams>) => void
}

/**
 * Platform and region checkboxes and the price range. The options come from
 * GET /products/filters (the same cached query as the header's category bar),
 * and do not narrow with the active filters (D24).
 */
export function FilterPanel({ params, options, onChange }: FilterPanelProps) {
  return (
    <div className="flex flex-col gap-8">
      <CheckboxGroup
        legend="Platform"
        options={options?.platforms}
        selected={params.platforms}
        onChange={(platforms) => onChange({ platforms })}
      />
      <CheckboxGroup
        legend="Region"
        options={options?.regions}
        selected={params.regions}
        onChange={(regions) => onChange({ regions })}
      />
      <PriceRange
        min={params.priceMin}
        max={params.priceMax}
        bounds={options?.price}
        onChange={(priceMin, priceMax) => onChange({ priceMin, priceMax })}
      />
    </div>
  )
}

function CheckboxGroup(props: {
  legend: string
  options: FilterOption[] | undefined
  selected: string[]
  onChange: (selected: string[]) => void
}) {
  const { legend, options, selected, onChange } = props

  return (
    <fieldset>
      <legend className="mb-3 text-sm font-extrabold tracking-wide uppercase">{legend}</legend>
      {options === undefined ? (
        <div aria-hidden className="flex flex-col gap-2">
          {[1, 2, 3].map((i) => (
            <div key={i} className="h-5 w-32 animate-pulse bg-white/10" />
          ))}
        </div>
      ) : (
        <ul className="flex flex-col gap-1">
          {options.map((option) => {
            const checked = selected.includes(option.slug)
            return (
              <li key={option.slug}>
                <label className="flex cursor-pointer items-center gap-3 py-1 text-text-soft hover:text-text">
                  <input
                    type="checkbox"
                    checked={checked}
                    onChange={() =>
                      onChange(checked ? selected.filter((slug) => slug !== option.slug) : [...selected, option.slug])
                    }
                    className="size-4 accent-accent"
                  />
                  {option.name}
                </label>
              </li>
            )
          })}
        </ul>
      )}
    </fieldset>
  )
}

/** "" -> null; whole euros only. undefined = not a valid value. */
function parseEuroInput(value: string): number | null | undefined {
  const trimmed = value.trim()
  if (trimmed === '') return null
  return /^\d{1,7}$/.test(trimmed) ? Number(trimmed) : undefined
}

/**
 * Two whole-euro inputs. The draft is local state, applied on Enter / "Go" or
 * when focus leaves the price group, not on every keystroke: typing "30" would
 * otherwise create history entries (and requests) for "3" and "30". Tabbing
 * from min to max stays inside the group, so it does not apply half an edit.
 */
function PriceRange(props: {
  min: number | null
  max: number | null
  bounds: FilterOptions['price'] | undefined
  onChange: (min: number | null, max: number | null) => void
}) {
  const { min, max, bounds, onChange } = props
  const [draftMin, setDraftMin] = useState(toInput(min))
  const [draftMax, setDraftMax] = useState(toInput(max))

  // When the URL's prices change from outside (chip removed, Back), show them.
  // Adjusted during render (React's recommended pattern), not with a `key`
  // remount, which would take focus away from an input being edited.
  const [shown, setShown] = useState({ min, max })
  if (shown.min !== min || shown.max !== max) {
    setShown({ min, max })
    setDraftMin(toInput(min))
    setDraftMax(toInput(max))
  }

  function apply() {
    const nextMin = parseEuroInput(draftMin)
    const nextMax = parseEuroInput(draftMax)
    // Invalid input: put the applied values back instead of guessing.
    if (nextMin === undefined || nextMax === undefined) {
      setDraftMin(toInput(min))
      setDraftMax(toInput(max))
      return
    }
    if (nextMin !== min || nextMax !== max) onChange(nextMin, nextMax)
  }

  // Placeholders show the catalog's range, rounded outwards to whole euros.
  const minPlaceholder = bounds?.min_cents != null ? String(Math.floor(bounds.min_cents / 100)) : 'Min'
  const maxPlaceholder = bounds?.max_cents != null ? String(Math.ceil(bounds.max_cents / 100)) : 'Max'
  const inputClass =
    'w-full min-w-0 border border-white/40 bg-transparent px-3 py-2 placeholder:text-text-muted focus:border-white'

  return (
    <fieldset>
      <legend className="mb-3 text-sm font-extrabold tracking-wide uppercase">Price (€)</legend>
      <form
        className="flex items-center gap-2"
        onSubmit={(event) => {
          event.preventDefault()
          apply()
        }}
        // React's onBlur bubbles (focusout). relatedTarget = where focus goes;
        // only apply when it leaves the form, not when moving min -> max -> Go.
        onBlur={(event) => {
          if (!event.currentTarget.contains(event.relatedTarget)) apply()
        }}
      >
        <label className="sr-only" htmlFor="price-min">
          Minimum price in euros
        </label>
        <input
          id="price-min"
          inputMode="numeric"
          value={draftMin}
          placeholder={minPlaceholder}
          onChange={(event) => setDraftMin(event.target.value)}
          className={inputClass}
        />
        <span aria-hidden className="text-text-muted">
          –
        </span>
        <label className="sr-only" htmlFor="price-max">
          Maximum price in euros
        </label>
        <input
          id="price-max"
          inputMode="numeric"
          value={draftMax}
          placeholder={maxPlaceholder}
          onChange={(event) => setDraftMax(event.target.value)}
          className={inputClass}
        />
        <button
          type="submit"
          // Keeps focus in the input on click (Safari does not focus buttons on
          // click, so it would blur the form and apply twice: blur, then submit).
          onMouseDown={(event) => event.preventDefault()}
          className="shrink-0 bg-accent px-3 py-2 text-button font-extrabold text-black hover:bg-accent-hover"
        >
          Go
        </button>
      </form>
    </fieldset>
  )
}

function toInput(euros: number | null): string {
  return euros === null ? '' : String(euros)
}
