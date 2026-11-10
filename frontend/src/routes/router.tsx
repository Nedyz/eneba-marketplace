import { createBrowserRouter } from 'react-router'
import { RootLayout } from '../components/layout/RootLayout'
import { CartPage } from '../features/cart/CartPage'
import { HealthPage } from '../features/health/HealthPage'
import { HomePlaceholder } from '../features/home/HomePlaceholder'
import { ProductPage } from '../features/product/ProductPage'
import { StorePage } from '../features/store/StorePage'
import { NotFoundPage } from './NotFoundPage'

/**
 * All routes in one place, like a route table. Child routes render inside
 * RootLayout's <Outlet />. Planned routes are added feature by feature:
 * /checkout, /login, /orders...
 */
export const router = createBrowserRouter([
  {
    path: '/',
    element: <RootLayout />,
    children: [
      // Temporary placeholder until the server-driven homepage exists (day 4).
      { index: true, element: <HomePlaceholder /> },
      { path: 'store', element: <StorePage /> },
      { path: 'product/:slug', element: <ProductPage /> },
      { path: 'cart', element: <CartPage /> },
      { path: 'health', element: <HealthPage /> },
      // "*" matches anything not listed above.
      { path: '*', element: <NotFoundPage /> },
    ],
  },
])
