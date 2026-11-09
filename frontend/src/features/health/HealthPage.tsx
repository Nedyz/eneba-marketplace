import { useQuery } from '@tanstack/react-query'
import { getHealth } from '../../api/health'

/**
 * Temporary day-1 page: proves the chain browser -> Vercel -> Render -> Neon
 * works in production. Replaced by the homepage on day 4 (moved to /health).
 */
export function HealthPage() {
  // useQuery runs getHealth, caches the result under the key ['health'] and
  // gives us loading / error / data states without writing useEffect by hand.
  const { data, error, isPending, refetch, isFetching } = useQuery({
    queryKey: ['health'],
    queryFn: getHealth,
  })

  return (
    <section className="mx-auto max-w-xl p-8">
      <h1 className="mb-6 text-2xl font-bold">API status</h1>

      {isPending && (
        // Render's free tier sleeps when idle; the first request can take up to a minute.
        <p className="text-slate-500">Contacting the API… (the server may be waking up, this can take up to a minute)</p>
      )}

      {error && <p className="text-red-600">Could not reach the API: {error.message}</p>}

      {data && (
        <dl className="grid grid-cols-2 gap-2 rounded-lg border border-slate-200 p-4">
          <dt>API</dt>
          <dd className={data.status === 'ok' ? 'text-green-600' : 'text-red-600'}>{data.status}</dd>
          <dt>Database</dt>
          <dd className={data.database === 'ok' ? 'text-green-600' : 'text-red-600'}>{data.database}</dd>
          <dt>pg_trgm</dt>
          <dd className={data.pg_trgm ? 'text-green-600' : 'text-red-600'}>{data.pg_trgm ?? 'missing'}</dd>
          <dt>Server time</dt>
          <dd>{new Date(data.time).toLocaleString()}</dd>
        </dl>
      )}

      <button
        type="button"
        onClick={() => refetch()}
        disabled={isFetching}
        className="mt-6 rounded bg-slate-900 px-4 py-2 text-white disabled:opacity-50"
      >
        {isFetching ? 'Checking…' : 'Check again'}
      </button>
    </section>
  )
}
