import { PageContainer } from '../../components/layout/PageContainer'

/** Placeholder until order history (day 6). The route is already behind sign-in. */
export function OrdersPage() {
  return (
    <PageContainer className="flex flex-col gap-4 py-10">
      <title>Your orders · Intersheba</title>
      <h1 className="text-2xl font-extrabold">Your orders</h1>
      <p className="text-text-muted">Order history is coming soon.</p>
    </PageContainer>
  )
}
