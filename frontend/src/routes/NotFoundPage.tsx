import { Link } from 'react-router'

export function NotFoundPage() {
  return (
    <section className="mx-auto max-w-xl p-8">
      <h1 className="mb-4 text-2xl font-bold">Page not found</h1>
      <Link to="/" className="underline">
        Back to the homepage
      </Link>
    </section>
  )
}
