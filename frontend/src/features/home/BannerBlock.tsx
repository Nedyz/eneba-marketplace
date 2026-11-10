import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { Link } from 'react-router'
import { ArrowButton } from '../../components/ui/ArrowButton'
import { getBlockContent, homeQueryKeys, type Banner, type HomeBlock } from '../../api/home'
import { BlockLoadError } from './BlockLoadError'
import { isInternalPath } from './homeBlocks'

/**
 * Background per slide when a banner has no image (D21: the frontend draws it).
 * Styling only, chosen by position; the content all comes from the API.
 * White text on each, contrast checked against the lighter stop (>= 4.5:1).
 */
const GRADIENTS = [
  'from-[#351183] to-[#7a2bd6]',
  'from-[#0b3d5c] to-[#17668c]',
  'from-[#5c0b3d] to-[#a81c6b]',
]

/**
 * Block type "banner": the banners from the block's endpoint, one at a time,
 * with previous/next buttons and dots. No autoplay: moving content the user
 * did not start is hard to read and to click (WCAG 2.2.2), and the page has no
 * pause control to offer.
 */
export function BannerBlock({ block }: { block: HomeBlock }) {
  const content = useQuery({
    queryKey: homeQueryKeys.block(block.endpoint),
    queryFn: ({ signal }) => getBlockContent<Banner>(block.endpoint, signal),
  })
  const [current, setCurrent] = useState(0)

  if (content.isPending) {
    return <div aria-hidden className="h-72 animate-pulse bg-white/5 md:h-80" />
  }
  if (content.isError) {
    return <BlockLoadError what={block.title ?? 'the banners'} onRetry={() => content.refetch()} />
  }

  // A banner whose link would leave the site is not shown (D32).
  const banners = content.data.filter((banner) => isInternalPath(banner.link_url))
  if (banners.length === 0) {
    return null
  }

  // The list can shrink on a refetch (a banner ended): stay in range.
  const index = current % banners.length
  const banner = banners[index]
  const count = banners.length
  // Functional update: two quick clicks both count, even before React re-renders
  // (with `index + 1` both would read the same stale index).
  const step = (delta: 1 | -1) => setCurrent((c) => (((c % count) + delta) % count + count) % count)

  return (
    <section aria-roledescription="carousel" aria-label={block.title ?? 'Featured'} className="relative">
      <div
        // Announces the new slide after a button press (no autoplay, so this
        // never interrupts on its own).
        aria-live="polite"
        role="group"
        aria-roledescription="slide"
        aria-label={`${index + 1} of ${banners.length}`}
        className={`relative flex h-72 flex-col justify-end gap-4 overflow-hidden p-6 md:h-80 md:p-10 ${
          banner.image_url ? 'bg-bg-deep' : `bg-linear-to-br ${GRADIENTS[index % GRADIENTS.length]}`
        }`}
      >
        {banner.image_url && (
          <>
            <img src={banner.image_url} alt="" className="absolute inset-0 h-full w-full object-cover" />
            {/* Keeps the white text readable on any photo. */}
            <div aria-hidden className="absolute inset-0 bg-linear-to-t from-black/80 to-black/10" />
          </>
        )}
        <div className="relative flex max-w-xl flex-col gap-3">
          <h2 className="text-3xl font-extrabold md:text-5xl">{banner.title}</h2>
          {banner.subtitle && <p className="text-lg text-white/90">{banner.subtitle}</p>}
          <Link
            to={banner.link_url}
            className="mt-2 self-start bg-accent px-5 py-3 text-button font-extrabold text-black hover:bg-accent-hover"
          >
            {banner.cta_label}
          </Link>
        </div>
      </div>

      {banners.length > 1 && (
        <div className="absolute right-4 bottom-4 flex items-center gap-3 md:right-8 md:bottom-8">
          <div className="hidden gap-2 sm:flex">
            {banners.map((b, i) => (
              <button
                key={b.id}
                type="button"
                onClick={() => setCurrent(i)}
                aria-label={`Show banner ${i + 1}: ${b.title}`}
                aria-current={i === index}
                className={`size-3 border-2 border-white ${i === index ? 'bg-white' : 'bg-transparent hover:bg-white/50'}`}
              />
            ))}
          </div>
          <ArrowButton direction="previous" onClick={() => step(-1)} />
          <ArrowButton direction="next" onClick={() => step(1)} />
        </div>
      )}
    </section>
  )
}
