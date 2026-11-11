import { Navigate, Outlet, useLocation } from 'react-router'
import { PageContainer } from '../components/layout/PageContainer'
import { useAuth } from '../features/auth/useAuth'

/**
 * Layout route guarding its children (checkout, orders), like [Authorize] on
 * a group of controllers. It only decides what to *show*: the API checks the
 * token on every protected request anyway, so this is about UX, not security.
 *
 * Signed out: go to /login, remembering this page in router state (not in the
 * URL, so a crafted link cannot choose where sign-in leads). `replace`, so Back
 * from the login page does not land on the guard again.
 */
export function RequireAuth() {
  const auth = useAuth()
  const location = useLocation()

  switch (auth.status) {
    case 'authenticated':
      return <Outlet />
    case 'anonymous':
      return <Navigate to="/login" replace state={{ from: location }} />
    case 'loading':
      return (
        <PageContainer className="py-10">
          <p role="status" className="text-text-muted">
            Checking your sign-in…
          </p>
        </PageContainer>
      )
    case 'error':
      return (
        <PageContainer className="py-10">
          <div role="alert" className="flex flex-col items-start gap-3">
            <p className="font-semibold">Could not check your sign-in.</p>
            <button type="button" onClick={auth.retry} className="underline">
              Try again
            </button>
          </div>
        </PageContainer>
      )
  }
}
