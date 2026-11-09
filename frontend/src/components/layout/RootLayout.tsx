import { Link, Outlet } from 'react-router'

/**
 * Shell shared by every page. <Outlet /> is where the matched child route
 * renders, like @RenderBody() in an ASP.NET layout. The real Eneba-style
 * header (logo, search bar, cart) and footer replace this on day 2.
 */
export function RootLayout() {
  return (
    <div className="flex min-h-screen flex-col">
      <header className="border-b border-slate-200 px-6 py-4">
        <Link to="/" className="font-bold">
          Eneba Marketplace
        </Link>
      </header>

      <main className="flex-1">
        <Outlet />
      </main>
    </div>
  )
}
