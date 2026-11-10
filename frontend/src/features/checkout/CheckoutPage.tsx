import { Link } from 'react-router'
import { PageContainer } from '../../components/layout/PageContainer'
import { cartCount } from '../cart/cartStorage'
import { useCart } from '../cart/cartStore'
import { useAuth } from '../auth/useAuth'

/**
 * Placeholder until the checkout session (day 5, session 2): proves the route
 * is behind sign-in and that the cart is still there after signing in.
 */
export function CheckoutPage() {
  const auth = useAuth()
  const count = cartCount(useCart())

  return (
    <PageContainer className="flex flex-col gap-4 py-10">
      <title>Checkout · Intersheba</title>
      <h1 className="text-2xl font-extrabold">Checkout</h1>
      <p>
        Signed in as <strong>{auth.user?.name}</strong>. Your cart has {count} {count === 1 ? 'item' : 'items'}.
      </p>
      <p className="text-text-muted">Placing the order comes next.</p>
      <Link to="/cart" className="underline">
        Back to cart
      </Link>
    </PageContainer>
  )
}
