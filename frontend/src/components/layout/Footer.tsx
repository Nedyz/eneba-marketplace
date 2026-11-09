import { Link } from 'react-router'
import { PageContainer } from './PageContainer'

/**
 * States clearly that this is not the real Eneba (DECISIONS.md D26).
 */
export function Footer() {
  return (
    <footer className="mt-16 bg-footer py-8 text-xs text-text-soft">
      <PageContainer className="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
        <p>
          <span className="font-extrabold text-text">Intersheba</span> is an Eneba internship assignment. It is a demo
          store, not affiliated with Eneba: no real keys are sold and no real payments are taken.
        </p>
        <nav aria-label="Footer" className="flex gap-6">
          <a href="https://github.com/Nedyz/eneba-marketplace" className="hover:text-text">
            Source code
          </a>
          <Link to="/health" className="hover:text-text">
            API status
          </Link>
        </nav>
      </PageContainer>
    </footer>
  )
}
