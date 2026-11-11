import { type FormEvent, useState } from 'react'
import { Link, Navigate, useLocation } from 'react-router'
import { AuthPanel, FormAlert, SubmitButton, TextField } from './AuthFormParts'
import { afterSignInPath, formErrors, NO_ERRORS } from './authForms'
import { useAuth, useRegister } from './useAuth'

/**
 * /register. Same flow as LoginPage: a new account is signed in straight away
 * and sent back to the page that required it. Field errors come from the
 * server's 422 and show under their field; the browser checks the basics
 * (required, email format, 8 characters) first, the server stays the authority.
 */
export function RegisterPage() {
  const auth = useAuth()
  const location = useLocation()
  const register = useRegister()
  const [name, setName] = useState('')
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')

  if (auth.status === 'authenticated') {
    return <Navigate to={afterSignInPath(location.state)} replace />
  }

  const errors = register.isError ? formErrors(register.error) : NO_ERRORS

  function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    register.mutate({ name, email, password })
  }

  return (
    <AuthPanel title="Create an account">
      <form onSubmit={submit} className="flex flex-col gap-5">
        <FormAlert message={errors.form} />
        <TextField
          label="Name"
          name="name"
          autoComplete="name"
          required
          maxLength={100}
          value={name}
          onChange={(event) => setName(event.target.value)}
          error={errors.fields.name}
        />
        <TextField
          label="Email"
          type="email"
          name="email"
          autoComplete="email"
          required
          maxLength={255}
          value={email}
          onChange={(event) => setEmail(event.target.value)}
          error={errors.fields.email}
        />
        <TextField
          label="Password"
          type="password"
          name="password"
          // Tells password managers to offer a generated password.
          autoComplete="new-password"
          required
          minLength={8}
          value={password}
          onChange={(event) => setPassword(event.target.value)}
          hint="At least 8 characters."
          error={errors.fields.password}
        />
        <SubmitButton pending={register.isPending}>{register.isPending ? 'Creating account…' : 'Create account'}</SubmitButton>
      </form>

      <p className="text-text-soft">
        Already have an account?{' '}
        <Link to="/login" state={location.state} className="font-semibold text-text underline">
          Sign in
        </Link>
      </p>
    </AuthPanel>
  )
}
