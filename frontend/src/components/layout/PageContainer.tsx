import type { ReactNode } from 'react'

/**
 * Same width and side padding as the header, so every page lines up with it.
 */
export function PageContainer({ children, className = '' }: { children: ReactNode; className?: string }) {
  return <div className={`mx-auto w-full max-w-page px-5 ${className}`}>{children}</div>
}
