<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Providers\AppServiceProvider;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * POST /api/auth/register (D33).
 */
class RegisterTest extends AuthTestCase
{
    private const VALID = ['name' => 'Ada Lovelace', 'email' => 'ada@example.com', 'password' => 'correct horse'];

    public function test_creates_the_user_and_returns_a_working_token(): void
    {
        $response = $this->postJson('/api/auth/register', self::VALID)
            ->assertCreated()
            ->assertJsonStructure(['data' => ['token', 'user']]);

        $user = User::sole();
        $this->assertSame(['id' => $user->id, 'name' => 'Ada Lovelace', 'email' => 'ada@example.com'], $response->json('data.user'));

        $this->me($response->json('data.token'))->assertOk()->assertJsonPath('data.id', $user->id);
    }

    public function test_stores_a_bcrypt_hash_never_the_password(): void
    {
        $this->postJson('/api/auth/register', self::VALID)->assertCreated();

        $stored = User::sole()->password;
        $this->assertNotSame('correct horse', $stored);
        $this->assertTrue(Hash::check('correct horse', $stored));
        $this->assertStringStartsWith('$2y$', $stored);
    }

    public function test_the_response_never_contains_the_password_or_its_hash(): void
    {
        $body = $this->postJson('/api/auth/register', self::VALID)->assertCreated()->getContent();

        $this->assertStringNotContainsString('correct horse', $body);
        $this->assertStringNotContainsString('password', $body);
        $this->assertStringNotContainsString('$2y$', $body);
    }

    public function test_email_is_trimmed_and_lowercased(): void
    {
        $this->postJson('/api/auth/register', ['email' => '  Ada@Example.COM '] + self::VALID)
            ->assertCreated()
            ->assertJsonPath('data.user.email', 'ada@example.com');

        $this->assertSame('ada@example.com', User::sole()->email);
    }

    public function test_an_email_already_taken_in_any_letter_case_is_rejected(): void
    {
        User::factory()->create(['email' => 'ada@example.com']);

        $this->postJson('/api/auth/register', ['email' => 'ADA@example.com'] + self::VALID)
            ->assertUnprocessable()
            ->assertOnlyJsonValidationErrors(['email']);

        $this->assertSame(1, User::count());
    }

    /**
     * Each case changes one field of a valid request; that field, and only
     * that one, must be reported.
     */
    #[DataProvider('invalidInput')]
    public function test_invalid_input_is_rejected(array $change, string $field): void
    {
        $this->postJson('/api/auth/register', $change + self::VALID)
            ->assertUnprocessable()
            ->assertOnlyJsonValidationErrors([$field]);

        $this->assertSame(0, User::count());
    }

    public static function invalidInput(): array
    {
        return [
            'name missing' => [['name' => ''], 'name'],
            'name too long' => [['name' => str_repeat('a', 101)], 'name'],
            'name not a string' => [['name' => ['a']], 'name'],
            'email missing' => [['email' => ''], 'email'],
            'email malformed' => [['email' => 'not-an-email'], 'email'],
            'email too long' => [['email' => self::emailOfLength(256)], 'email'],
            'email not a string' => [['email' => 42], 'email'],
            'password missing' => [['password' => ''], 'password'],
            'password 7 characters' => [['password' => '1234567'], 'password'],
            'password 73 bytes' => [['password' => str_repeat('a', 73)], 'password'],
            // 25 characters but 75 bytes: "max:72" would let it through.
            'password 25 three-byte characters' => [['password' => str_repeat('€', 25)], 'password'],
            'password not a string' => [['password' => 12345678], 'password'],
        ];
    }

    public function test_boundaries_that_are_accepted(): void
    {
        $this->postJson('/api/auth/register', [
            'name' => str_repeat('a', 100),
            'email' => self::emailOfLength(255),
            'password' => str_repeat('a', 72),
        ])->assertCreated();

        $this->postJson('/api/auth/register', ['email' => 'b@example.com', 'password' => '12345678'] + self::VALID)
            ->assertCreated();
    }

    /**
     * Laravel runs every rule of a field (no "bail"), so this also proves
     * max:255 itself, not only the email format rule, which rejects addresses
     * this long as well.
     */
    public function test_email_longer_than_the_column_reports_the_length_rule(): void
    {
        $this->postJson('/api/auth/register', ['email' => self::emailOfLength(256)] + self::VALID)
            ->assertUnprocessable()
            ->assertJsonFragment(['The email field must not be greater than 255 characters.']);
    }

    /**
     * A valid address of exactly $length characters, padded in the domain in
     * 50-character labels: the part before "@" may be at most 64 characters,
     * and email-validator rejects labels near the 63-character DNS limit.
     */
    private static function emailOfLength(int $length): string
    {
        $labels = [];
        for ($rest = $length - strlen('a@.com'); $rest > 0; $rest -= strlen(end($labels)) + 1) {
            $labels[] = str_repeat('b', min(50, $rest));
        }

        return 'a@'.implode('.', $labels).'.com';
    }

    public function test_registrations_from_one_ip_are_rate_limited(): void
    {
        for ($i = 0; $i < AppServiceProvider::REGISTER_PER_IP_PER_MINUTE; $i++) {
            $this->postJson('/api/auth/register', ['email' => "user{$i}@example.com"] + self::VALID)->assertCreated();
        }

        $this->postJson('/api/auth/register', ['email' => 'one-more@example.com'] + self::VALID)
            ->assertTooManyRequests()
            ->assertHeader('Retry-After');

        // The window is one minute.
        $this->travel(61)->seconds();
        $this->postJson('/api/auth/register', ['email' => 'later@example.com'] + self::VALID)->assertCreated();
    }
}
