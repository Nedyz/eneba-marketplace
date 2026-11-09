import { Link } from 'react-router'
import { PageContainer } from '../../components/layout/PageContainer'

/**
 * Temporary "/" until the server-driven homepage exists (day 4, DECISIONS.md D4).
 */
export function HomePlaceholder() {
  return (
    <PageContainer className="py-12">
      <h1 className="mb-4 text-3xl font-bold">Intersheba</h1>
      <p className="mb-6 max-w-xl text-text-soft">
        A demo game key marketplace built as an Eneba internship assignment. The store, search and checkout are being
        built this week.
      </p>
      <Link to="/health" className="inline-block bg-accent px-5 py-3 text-button font-extrabold text-black hover:bg-accent-hover">
        Check API status
      </Link>
    </PageContainer>
  )
}
