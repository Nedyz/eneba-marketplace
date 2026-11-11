<?php

namespace App\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Validates POST /auth/register (D33).
 */
class RegisterRequest extends FormRequest
{
    /**
     * bcrypt only reads the first 72 bytes of a password and Laravel's limit
     * is off by default, so anything longer would be cut silently. Checked in
     * bytes (strlen), not characters: "max:72" counts characters, and 72
     * multibyte characters can be up to 288 bytes.
     */
    public const MAX_PASSWORD_BYTES = 72;

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', Password::min(8), self::passwordByteLimit()],
        ];
    }

    private static function passwordByteLimit(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (is_string($value) && strlen($value) > self::MAX_PASSWORD_BYTES) {
                $fail('The password field must not be longer than '.self::MAX_PASSWORD_BYTES.' bytes.');
            }
        };
    }

    /**
     * Runs before the rules. PostgreSQL's unique index is case-sensitive, so
     * without this "Demo@Example.com" would be a second account next to
     * "demo@example.com". Login lowercases the same way (LoginRequest).
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => self::normalizeEmail($this->input('email'))]);
        }
    }

    public static function normalizeEmail(string $email): string
    {
        return Str::lower(trim($email));
    }
}
