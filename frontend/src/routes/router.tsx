import { createBrowserRouter } from 'react-router'
import { RootLayout } from '../components/layout/RootLayout'
import { HealthPage } from '../features/health/HealthPage'
import { HomePlaceholder } from '../features/home/HomePlaceholder'
import { NotFoundPage } from './NotFoundPage'

/**
 * All routes in one place, like a route table. Child routes render inside
 * RootLayout's <Outlet />. Planned routes are added feature by feature:
 * /store, /product/:slug, /cart, /checkout, /login, /orders...
 */
export const router = createBrowserRouter([
  {
    path: '/',
    element: <RootLayout />,
    children: [
      // Temporary placeholder until the server-driven homepage exists (day 4).
      { index: true, element: <HomePlaceholder /> },
      { path: 'health', element: <HealthPage /> },
      // "*" matches anything not listed above.
      { path: '*', element: <NotFoundPage /> },
    ],
  },
])
