<?php

namespace Tests\Feature\Auth;

use App\Http\Requests\LoginRequest;
use App\Models\User;
use App\Providers\AppServiceProvider;
use Illuminate\Support\Facades\DB;

/**
 * POST /api/auth/login (D33).
 */
class LoginTest extends AuthTestCase
{
    private const WRONG = 'These credentials do not match our records.';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'correct horse']);
    }

    private function login(string $email, string $password = 'correct horse')
    {
        return $this->postJson('/api/auth/login', ['email' => $email, 'password' => $password]);
    }

    public function test_returns_the_user_and_a_working_token(): void
    {
        $response = $this->login('ada@example.com')
            ->assertOk()
            ->assertJsonPath('data.user', ['id' => $this->user->id, 'name' => 'Ada', 'email' => 'ada@example.com']);

        $this->me($response->json('data.token'))->assertOk()->assertJsonPath('data.email', 'ada@example.com');
    }

    public function test_each_sign_in_creates_its_own_token(): void
    {
        $first = $this->login('ada@example.com')->json('data.token');
        $second = $this->login('ada@example.com')->json('data.token');

        $this->assertNotSame($first, $second);
        $this->assertSame(2, $this->user->tokens()->count());
    }

    public function test_the_token_is_stored_only_as_a_sha256_hash(): void
    {
        $token = $this->login('ada@example.com')->json('data.token');
        [, $secret] = explode('|', $token, 2);

        $this->assertSame(hash('sha256', $secret), DB::table('personal_access_tokens')->value('token'));
    }

    public function test_email_letter_case_and_spaces_do_not_matter(): void
    {
        $this->login('  ADA@Example.com ')->assertOk();
    }

    public function test_the_password_is_case_sensitive_and_not_trimmed(): void
    {
        $this->login('ada@example.com', 'Correct horse')->assertUnprocessable();
        $this->login('ada@example.com', 'correct horse ')->assertUnprocessable();
    }

    /**
     * Same status, same field, same message: the answer does not reveal
     * whether an account exists for the email.
     */
    public function test_wrong_password_and_unknown_email_get_the_same_answer(): void
    {
        $wrongPassword = $this->login('ada@example.com', 'nope')->assertUnprocessable();
        $unknownEmail = $this->login('nobody@example.com')->assertUnprocessable();

        $this->assertSame(['email' => [self::WRONG]], $wrongPassword->json('errors'));
        $this->assertSame($wrongPassword->json(), $unknownEmail->json());
        $this->assertSame(0, DB::table('personal_access_tokens')->count());
    }

    public function test_missing_fields_are_validation_errors(): void
    {
        $this->postJson('/api/auth/login', [])->assertUnprocessable()->assertOnlyJsonValidationErrors(['email', 'password']);
        $this->postJson('/api/auth/login', ['email' => ['x'], 'password' => 1])->assertUnprocessable()->assertOnlyJsonValidationErrors(['email', 'password']);
    }

    public function test_failed_attempts_lock_out_that_email_from_that_ip(): void
    {
        for ($i = 0; $i < LoginRequest::MAX_FAILED_ATTEMPTS; $i++) {
            $this->login('ada@example.com', 'nope')->assertUnprocessable();
        }

        // Even the right password is refused during the lockout.
        $this->login('ada@example.com')->assertTooManyRequests()->assertHeader('Retry-After');
        // Spelled differently, it is still the same email.
        $this->login('ADA@example.com')->assertTooManyRequests();

        // Another email from the same IP is not locked.
        User::factory()->create(['email' => 'bob@example.com', 'password' => 'correct horse']);
        $this->login('bob@example.com')->assertOk();

        // Nor is the same email from another IP.
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->login('ada@example.com')->assertOk();
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1']);

        $this->travel(LoginRequest::LOCKOUT_SECONDS + 1)->seconds();
        $this->login('ada@example.com')->assertOk();
    }

    public function test_a_successful_sign_in_resets_the_failure_count(): void
    {
        for ($i = 0; $i < LoginRequest::MAX_FAILED_ATTEMPTS - 1; $i++) {
            $this->login('ada@example.com', 'nope');
        }
        $this->login('ada@example.com')->assertOk();

        for ($i = 0; $i < LoginRequest::MAX_FAILED_ATTEMPTS - 1; $i++) {
            $this->login('ada@example.com', 'nope')->assertUnprocessable();
        }
        $this->login('ada@example.com')->assertOk();
    }

    public function test_requests_from_one_ip_are_limited_across_all_emails(): void
    {
        // Different unknown emails, so the per-email lockout never triggers.
        for ($i = 0; $i < AppServiceProvider::LOGIN_PER_IP_PER_MINUTE; $i++) {
            $this->login("guess{$i}@example.com")->assertUnprocessable();
        }

        $this->login('ada@example.com')->assertTooManyRequests();
    }
}
