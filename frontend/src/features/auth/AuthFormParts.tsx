import { type InputHTMLAttributes, type ReactNode, useId } from 'react'
import { PageContainer } from '../../components/layout/PageContainer'

/** The narrow centered panel both auth pages use. */
export function AuthPanel({ title, children }: { title: string; children: ReactNode }) {
  return (
    <PageContainer className="py-10 md:py-16">
      <title>{`${title} · Intersheba`}</title>
      <section aria-labelledby="auth-title" className="mx-auto flex max-w-md flex-col gap-6 bg-surface p-6 md:p-8">
        <h1 id="auth-title" className="text-2xl font-extrabold">
          {title}
        </h1>
        {children}
      </section>
    </PageContainer>
  )
}

interface TextFieldProps extends InputHTMLAttributes<HTMLInputElement> {
  label: string
  /** Server message for this field; links it to the input for screen readers. */
  error?: string
  hint?: string
}

/**
 * Label + input + hint + error. The error and hint are tied to the input with
 * aria-describedby and the input is marked aria-invalid, so a screen reader
 * reads the message when the field gets focus.
 */
export function TextField({ label, error, hint, ...input }: TextFieldProps) {
  const id = useId()
  const hintId = `${id}-hint`
  const errorId = `${id}-error`
  const describedBy = [hint && hintId, error && errorId].filter(Boolean).join(' ') || undefined

  return (
    <div className="flex flex-col gap-1.5">
      <label htmlFor={id} className="font-semibold">
        {label}
      </label>
      <input
        id={id}
        aria-invalid={error ? true : undefined}
        aria-describedby={describedBy}
        className={`h-12 border bg-bg-deep px-3 text-text placeholder:text-text-muted ${
          error ? 'border-accent' : 'border-white/20 focus:border-white/60'
        }`}
        {...input}
      />
      {hint && !error && (
        <p id={hintId} className="text-sm text-text-muted">
          {hint}
        </p>
      )}
      {error && (
        <p id={errorId} className="text-sm font-semibold text-accent">
          {error}
        </p>
      )}
    </div>
  )
}

/** A message about the whole form; role="alert" announces it when it appears. */
export function FormAlert({ message }: { message: string | null }) {
  return (
    <div role="alert" className="empty:hidden">
      {message && <p className="border-l-4 border-accent bg-bg-deep px-4 py-3 font-semibold">{message}</p>}
    </div>
  )
}

export function SubmitButton({ pending, children }: { pending: boolean; children: ReactNode }) {
  return (
    <button
      type="submit"
      disabled={pending}
      className="bg-accent px-5 py-3 text-button font-extrabold text-black hover:bg-accent-hover disabled:cursor-wait disabled:opacity-70"
    >
      {children}
    </button>
  )
}
