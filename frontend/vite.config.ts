import tailwindcss from '@tailwindcss/vite'
import react from '@vitejs/plugin-react'
import { defineConfig } from 'vite'

// https://vite.dev/config/
export default defineConfig({
  // react(): JSX transform + fast refresh in dev.
  // tailwindcss(): scans our files for class names and generates only the CSS we use.
  plugins: [react(), tailwindcss()],
})
