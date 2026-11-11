<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Validates POST /auth/login and checks the credentials (D33). The check lives
 * here, like in Laravel Breeze, so the failed-attempt counter sits next to the
 * check it protects and the controller stays thin.
 */
class LoginRequest extends FormRequest
{
    /** Failed attempts allowed per email + IP before a lockout. */
    public const MAX_FAILED_ATTEMPTS = 5;

    /** Length of the lockout window, in seconds. */
    public const LOCKOUT_SECONDS = 60;

    public function rules(): array
    {
        // No "email" format rule: a malformed address simply matches no user
        // and gets the same answer as a wrong password.
        return [
            'email' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => RegisterRequest::normalizeEmail($this->input('email'))]);
        }
    }

    /**
     * Returns the user whose email and password match, or fails with one
     * message for both "no such email" and "wrong password", so the answer
     * does not reveal which emails have an account.
     *
     * @throws ValidationException 422 when the credentials do not match
     * @throws ThrottleRequestsException 429 after too many failed attempts
     */
    public function authenticate(): User
    {
        $key = $this->throttleKey();

        if (RateLimiter::tooManyAttempts($key, self::MAX_FAILED_ATTEMPTS)) {
            event(new Lockout($this));
            $seconds = RateLimiter::availableIn($key);
            throw new ThrottleRequestsException(
                "Too many sign-in attempts. Try again in {$seconds} seconds.",
                headers: ['Retry-After' => $seconds],
            );
        }

        $password = (string) $this->validated('password');
        $user = User::where('email', $this->validated('email'))->first();

        if ($user === null) {
            // Same work as a real check (one bcrypt hash at the configured
            // cost), so the response time does not reveal that the email is
            // unknown either.
            Hash::make($password);
        }

        if ($user === null || ! Hash::check($password, $user->password)) {
            RateLimiter::hit($key, self::LOCKOUT_SECONDS);
            throw ValidationException::withMessages([
                'email' => 'These credentials do not match our records.',
            ]);
        }

        RateLimiter::clear($key);

        return $user;
    }

    /**
     * Per email and IP: one attacker cannot lock a user out from everywhere,
     * and one person mistyping does not block a whole office. The per-IP route
     * limit (AppServiceProvider) covers trying many emails from one address.
     */
    private function throttleKey(): string
    {
        return 'login|'.$this->validated('email').'|'.$this->ip();
    }
}
