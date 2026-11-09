import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { RouterProvider } from 'react-router/dom'
import './index.css'
import { router } from './routes/router'

// One cache for all server data in the app (products, orders...).
const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      // Data counts as fresh for 30 s: navigating back to a page shows it
      // instantly instead of refetching every time.
      staleTime: 30_000,
      // One retry is enough; three (the default) makes errors feel slow.
      retry: 1,
    },
  },
})

// Entry point: mounts React into <div id="root"> in index.html.
// Providers wrap the app like services registered at startup in Program.cs.
createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <QueryClientProvider client={queryClient}>
      <RouterProvider router={router} />
    </QueryClientProvider>
  </StrictMode>,
)
