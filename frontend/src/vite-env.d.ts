/// <reference types="vite/client" />

// Types for our environment variables, so import.meta.env.VITE_API_URL is a
// known string instead of `any`. Only variables starting with VITE_ are exposed
// to the browser, and they end up in the public JS bundle: never put secrets here.
interface ImportMetaEnv {
  readonly VITE_API_URL: string
}

interface ImportMeta {
  readonly env: ImportMetaEnv
}
