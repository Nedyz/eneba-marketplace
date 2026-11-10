/** Square previous/next button, used by the homepage carousels. */
export function ArrowButton({ direction, onClick }: { direction: 'previous' | 'next'; onClick: () => void }) {
  return (
    <button
      type="button"
      onClick={onClick}
      aria-label={direction === 'previous' ? 'Previous' : 'Next'}
      className="flex size-10 items-center justify-center bg-black/50 text-xl font-extrabold text-white hover:bg-black/75"
    >
      <span aria-hidden>{direction === 'previous' ? '‹' : '›'}</span>
    </button>
  )
}
