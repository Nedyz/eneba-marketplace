import tailwindcss from '@tailwindcss/vite'
import react from '@vitejs/plugin-react'
// vitest/config's defineConfig is Vite's, plus types for the `test` section.
import { defineConfig } from 'vitest/config'

// https://vite.dev/config/
export default defineConfig({
  // react(): JSX transform + fast refresh in dev.
  // tailwindcss(): scans our files for class names and generates only the CSS we use.
  plugins: [react(), tailwindcss()],
  test: {
    // api/client.ts throws at import when VITE_API_URL is missing (on purpose).
    // Tests get a fixed placeholder instead of whatever is in a developer's
    // .env.local, so they behave the same locally and in CI. Unit tests never
    // make real requests, so the host does not need to exist.
    //
    // TZ: tests run west of UTC (CI runs in UTC, this Mac east of it), so a date
    // formatted in local time instead of UTC shows the previous day and fails.
    env: { VITE_API_URL: 'http://api.test/api', TZ: 'America/New_York' },
  },
})
