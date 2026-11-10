/**
 * Which page numbers the pagination shows: always the first and last page,
 * the current page with one neighbor on each side, and "gap" where pages are
 * skipped. A gap that would hide a single page shows that page instead.
 *
 *   pageWindow(6, 12) -> [1, 'gap', 5, 6, 7, 'gap', 12]
 *   pageWindow(3, 12) -> [1, 2, 3, 4, 'gap', 12]
 */
export function pageWindow(current: number, last: number): (number | 'gap')[] {
  const pages = new Set([1, last, current - 1, current, current + 1])
  const sorted = [...pages].filter((page) => page >= 1 && page <= last).sort((a, b) => a - b)

  const result: (number | 'gap')[] = []
  for (const page of sorted) {
    const previous = result.at(-1)
    if (typeof previous === 'number' && page - previous === 2) result.push(previous + 1)
    else if (typeof previous === 'number' && page - previous > 2) result.push('gap')
    result.push(page)
  }
  return result
}
