import { Link } from 'react-router'
import { PageContainer } from '../components/layout/PageContainer'

/**
 * Any unknown path. Same layout as the other empty states (empty cart): the
 * page container, so it lines up with the header, and a card with a way on.
 */
export function NotFoundPage() {
  return (
    <PageContainer className="py-10">
      <title>Page not found · Intersheba</title>
      <div className="flex flex-col items-start gap-4 bg-surface p-8">
        <h1 className="text-2xl font-extrabold">Page not found</h1>
        <p className="text-text-soft">This page does not exist. It may have moved, or the link may be mistyped.</p>
        <div className="flex flex-wrap items-center gap-x-6 gap-y-3">
          <Link to="/store" className="bg-accent px-5 py-3 text-button font-extrabold text-black hover:bg-accent-hover">
            Browse games
          </Link>
          <Link to="/" className="font-semibold underline">
            Back to the homepage
          </Link>
        </div>
      </div>
    </PageContainer>
  )
}
