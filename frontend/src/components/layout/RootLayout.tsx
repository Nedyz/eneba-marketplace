import { Outlet } from 'react-router'
import { Footer } from './Footer'
import { Header } from './Header'
import { ServerWakingBanner } from './ServerWakingBanner'

/**
 * Shell shared by every page. <Outlet /> is where the matched child route
 * renders, like @RenderBody() in an ASP.NET layout.
 */
export function RootLayout() {
  return (
    <div className="flex min-h-screen flex-col">
      <Header />
      <ServerWakingBanner />
      <main className="flex-1">
        <Outlet />
      </main>
      <Footer />
    </div>
  )
}
