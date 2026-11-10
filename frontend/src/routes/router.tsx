import { createBrowserRouter } from 'react-router'
import { RootLayout } from '../components/layout/RootLayout'
import { LoginPage } from '../features/auth/LoginPage'
import { RegisterPage } from '../features/auth/RegisterPage'
import { CartPage } from '../features/cart/CartPage'
import { CheckoutPage } from '../features/checkout/CheckoutPage'
import { HealthPage } from '../features/health/HealthPage'
import { HomePage } from '../features/home/HomePage'
import { OrdersPage } from '../features/orders/OrdersPage'
import { ProductPage } from '../features/product/ProductPage'
import { StorePage } from '../features/store/StorePage'
import { NotFoundPage } from './NotFoundPage'
import { RequireAuth } from './RequireAuth'

/**
 * All routes in one place, like a route table. Child routes render inside
 * RootLayout's <Outlet />.
 */
export const router = createBrowserRouter([
  {
    path: '/',
    element: <RootLayout />,
    children: [
      // Server-driven: the layout comes from GET /api/home (D4).
      { index: true, element: <HomePage /> },
      { path: 'store', element: <StorePage /> },
      { path: 'product/:slug', element: <ProductPage /> },
      { path: 'cart', element: <CartPage /> },
      { path: 'login', element: <LoginPage /> },
      { path: 'register', element: <RegisterPage /> },
      // Pathless layout route: everything inside needs a signed-in user (D33).
      // The cart stays public: guests fill it before signing in (D8).
      {
        element: <RequireAuth />,
        children: [
          { path: 'checkout', element: <CheckoutPage /> },
          { path: 'orders', element: <OrdersPage /> },
        ],
      },
      { path: 'health', element: <HealthPage /> },
      // "*" matches anything not listed above.
      { path: '*', element: <NotFoundPage /> },
    ],
  },
])
