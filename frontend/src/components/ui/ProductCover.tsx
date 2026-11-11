/**
 * Generated product cover (D21): a gradient in the platform's color with the
 * platform's name on it, so no third-party images are loaded. A real
 * `image_url`, if one is ever set, is shown instead.
 *
 * The platform, not the title: the title is always printed right next to the
 * cover, and one game is sold as several editions (D5), so "Split Fiction"
 * twice in a row only looks like a duplicate. The platform is what tells
 * those cards apart at a glance.
 *
 * Colors are our own picks per platform, not brand assets, and the name is
 * plain text, not a logo (D14). Text is white on dark gradients (contrast
 * checked against the lightest stop).
 */
const PLATFORM_GRADIENTS: Record<string, string> = {
  steam: 'from-[#1b2838] to-[#2a475e]',
  playstation: 'from-[#00296b] to-[#0050b5]',
  xbox: 'from-[#0b3d0b] to-[#1e6b1e]',
  nintendo: 'from-[#7a0010] to-[#c4001a]',
  'ea-app': 'from-[#4a0a2c] to-[#8a1450]',
  'ubisoft-connect': 'from-[#06205c] to-[#1c4fa8]',
  'epic-games': 'from-[#1a1a1a] to-[#3d3d3d]',
}
const FALLBACK_GRADIENT = 'from-bg-deep to-surface'

interface ProductCoverProps {
  /** Undefined while unknown (a cart line before its first quote): fallback gradient, no label. */
  platform: { slug: string; name: string } | undefined
  imageUrl: string | null
  /** "lg": store and homepage cards, product page; "md": cart lines; "sm": thumbnails (search suggestions). Only "lg" prints the platform. */
  size?: 'sm' | 'md' | 'lg'
}

/**
 * The size owns the width. No className prop on purpose: a caller's "w-9"
 * next to a built-in "w-full" would be decided by stylesheet order, not by
 * which class comes last in the string (Tailwind does not merge classes).
 *
 * Thumbnails are self-start: in a flex row (a cart line) the default
 * align-items: stretch makes them as tall as the row, and the stretch wins
 * over the aspect ratio (on a phone they became 64 x 160 px bars).
 */
const SIZE_CLASSES = { lg: 'w-full', md: 'w-16 shrink-0 self-start', sm: 'w-9 shrink-0' } as const

export function ProductCover({ platform, imageUrl, size = 'lg' }: ProductCoverProps) {
  // Decorative: the product name and platform are always in the text next to the cover.
  if (imageUrl) {
    return <img src={imageUrl} alt="" loading="lazy" className={`aspect-[3/4] object-cover ${SIZE_CLASSES[size]}`} />
  }

  const gradient = (platform && PLATFORM_GRADIENTS[platform.slug]) ?? FALLBACK_GRADIENT

  return (
    <div
      aria-hidden
      className={`flex aspect-[3/4] items-end overflow-hidden bg-linear-to-br ${gradient} ${SIZE_CLASSES[size]} ${size === 'lg' ? 'p-4' : ''}`}
    >
      {size === 'lg' && platform && (
        <span className="text-sm font-extrabold tracking-widest text-white/90 uppercase">{platform.name}</span>
      )}
    </div>
  )
}
