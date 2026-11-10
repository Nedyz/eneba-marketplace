import { type FormEvent, useState } from 'react'
import { Link, Navigate, useLocation } from 'react-router'
import { AuthPanel, FormAlert, SubmitButton, TextField } from './AuthFormParts'
import { afterSignInPath, formErrors, NO_ERRORS } from './authForms'
import { useAuth, useLogin } from './useAuth'

/**
 * /login. RequireAuth sends signed-out users here with the page they wanted in
 * router state; once signed in, this page redirects there (or home). There is
 * no navigate() in the submit handler: a successful sign-in makes useAuth()
 * `authenticated`, and the <Navigate> below does the rest. The same <Navigate>
 * handles opening /login while already signed in.
 */
export function LoginPage() {
  const auth = useAuth()
  const location = useLocation()
  const login = useLogin()
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')

  if (auth.status === 'authenticated') {
    return <Navigate to={afterSignInPath(location.state)} replace />
  }

  const errors = login.isError ? formErrors(login.error) : NO_ERRORS
  // Login reports wrong credentials under "email" (Laravel's convention). It
  // is about the pair, not the email, so every login message goes in the
  // alert at the top of the form rather than under a field.
  const message = errors.form ?? Object.values(errors.fields)[0] ?? null

  function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    login.mutate({ email, password })
  }

  return (
    <AuthPanel title="Sign in">
      <form onSubmit={submit} className="flex flex-col gap-5">
        <FormAlert message={message} />
        <TextField
          label="Email"
          type="email"
          name="email"
          autoComplete="email"
          required
          value={email}
          onChange={(event) => setEmail(event.target.value)}
        />
        <TextField
          label="Password"
          type="password"
          name="password"
          autoComplete="current-password"
          required
          value={password}
          onChange={(event) => setPassword(event.target.value)}
        />
        <SubmitButton pending={login.isPending}>{login.isPending ? 'Signing in…' : 'Sign in'}</SubmitButton>
      </form>

      <p className="text-text-soft">
        New here?{' '}
        {/* Passes the saved page on, so registering also returns to it. */}
        <Link to="/register" state={location.state} className="font-semibold text-text underline">
          Create an account
        </Link>
      </p>
      <p className="text-sm text-text-muted">Demo account: demo@example.com / password</p>
    </AuthPanel>
  )
}
