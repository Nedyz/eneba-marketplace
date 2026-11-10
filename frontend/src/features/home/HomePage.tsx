import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router'
import { getHomeLayout, homeQueryKeys, type HomeBlock } from '../../api/home'
import { PageContainer } from '../../components/layout/PageContainer'
import { blockRegistry } from './blockRegistry'
import { renderableBlocks } from './homeBlocks'

/**
 * "/": the server-driven homepage (D4). Fetches the layout, then renders each
 * block the frontend knows with its component; each block fetches its own
 * content, so blocks load (and fail) independently, in parallel.
 */
export function HomePage() {
  const layout = useQuery({
    queryKey: homeQueryKeys.layout(),
    queryFn: ({ signal }) => getHomeLayout(signal),
  })

  return (
    <PageContainer className="flex flex-col gap-12 py-8">
      <title>Intersheba · Game keys from trusted sellers</title>
      {/* Every visible heading comes from the API; the page still needs an h1. */}
      <h1 className="sr-only">Intersheba</h1>

      {layout.isPending && <div aria-hidden className="h-72 animate-pulse bg-white/5 md:h-80" />}

      {layout.isError && (
        <div role="alert" className="flex flex-col items-start gap-4 py-12">
          <p className="text-xl font-bold">Could not load the homepage.</p>
          <button
            type="button"
            onClick={() => layout.refetch()}
            className="bg-accent px-5 py-3 text-button font-extrabold text-black hover:bg-accent-hover"
          >
            Try again
          </button>
        </div>
      )}

      {layout.isSuccess && <Blocks blocks={layout.data} />}
    </PageContainer>
  )
}

function Blocks({ blocks }: { blocks: HomeBlock[] }) {
  const renderable = renderableBlocks(blocks, blockRegistry)

  if (renderable.length === 0) {
    return (
      <p className="py-12 text-text-soft">
        Nothing featured right now.{' '}
        <Link to="/store" className="font-extrabold text-text underline">
          Browse all games
        </Link>
      </p>
    )
  }

  // key = block id: reordering blocks in the database keeps each block's state.
  return renderable.map(({ block, Component }) => <Component key={block.id} block={block} />)
}
