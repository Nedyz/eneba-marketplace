/**
 * Generated product cover (D21): a gradient in the platform's color with the
 * title on it, so no third-party images are loaded. A real `image_url`, if one
 * is ever set, is shown instead.
 *
 * Colors are our own picks per platform, not brand assets. Text is white on
 * dark gradients (contrast checked against the lightest stop).
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
  name: string
  platformSlug: string
  imageUrl: string | null
  /** "lg": store and homepage cards; "sm": thumbnails (search suggestions). */
  size?: 'sm' | 'lg'
  className?: string
}

export function ProductCover({ name, platformSlug, imageUrl, size = 'lg', className = '' }: ProductCoverProps) {
  // Decorative: the product name is always in the text next to the cover.
  if (imageUrl) {
    return <img src={imageUrl} alt="" loading="lazy" className={`aspect-[3/4] w-full object-cover ${className}`} />
  }

  const gradient = PLATFORM_GRADIENTS[platformSlug] ?? FALLBACK_GRADIENT

  return (
    <div
      aria-hidden
      className={`flex aspect-[3/4] w-full items-end overflow-hidden bg-linear-to-br ${gradient} ${size === 'lg' ? 'p-4' : 'p-1'} ${className}`}
    >
      {size === 'lg' && (
        <span className="line-clamp-4 text-xl leading-tight font-extrabold break-words text-white">{name}</span>
      )}
    </div>
  )
}
