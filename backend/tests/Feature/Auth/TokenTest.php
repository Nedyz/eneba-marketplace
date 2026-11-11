<?php

namespace Tests\Feature\Auth;

use App\Models\User;

/**
 * Bearer tokens on protected routes: GET /api/auth/me, POST /api/auth/logout,
 * expiry after 7 days (D18, D33).
 */
class TokenTest extends AuthTestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['name' => 'Ada', 'email' => 'ada@example.com']);
    }

    private function token(): string
    {
        return $this->user->createToken('spa')->plainTextToken;
    }

    public function test_me_returns_only_public_fields(): void
    {
        $this->me($this->token())
            ->assertOk()
            ->assertExactJson(['data' => ['id' => $this->user->id, 'name' => 'Ada', 'email' => 'ada@example.com']]);
    }

    public function test_me_without_a_token_is_401_json(): void
    {
        $this->getJson('/api/auth/me')->assertUnauthorized()->assertExactJson(['message' => 'Unauthenticated.']);
    }

    /**
     * Without "Accept: application/json" Laravel would try to redirect to a
     * login route this API does not have. The api/* JSON rule (bootstrap/app.php)
     * must still make it a 401.
     */
    public function test_401_is_json_even_without_an_accept_header(): void
    {
        $this->get('/api/auth/me')->assertUnauthorized()->assertJson(['message' => 'Unauthenticated.']);
    }

    public function test_a_made_up_or_mangled_token_is_401(): void
    {
        $real = $this->token();
        [$id] = explode('|', $real, 2);

        $this->me('not-a-token')->assertUnauthorized();
        $this->me($id.'|'.str_repeat('x', 40))->assertUnauthorized(); // right id, wrong secret
        $this->me(substr($real, 0, -1))->assertUnauthorized();
    }

    public function test_the_token_of_a_deleted_user_is_401(): void
    {
        $token = $this->token();
        $this->user->delete();

        $this->me($token)->assertUnauthorized();
    }

    public function test_a_token_works_until_7_days_and_not_after(): void
    {
        $token = $this->token();

        $this->travel(7 * 24 * 60 - 1)->minutes();
        $this->me($token)->assertOk();

        $this->travel(2)->minutes();
        $this->me($token)->assertUnauthorized();
    }

    public function test_logout_deletes_the_token_so_it_stops_working_at_once(): void
    {
        $token = $this->token();

        $this->bearer($token, 'POST', '/api/auth/logout')->assertNoContent();

        $this->assertSame(0, $this->user->tokens()->count());
        $this->me($token)->assertUnauthorized();
        $this->bearer($token, 'POST', '/api/auth/logout')->assertUnauthorized();
    }

    public function test_logout_signs_out_only_this_device(): void
    {
        $laptop = $this->token();
        $phone = $this->token();
        $otherUser = User::factory()->create()->createToken('spa')->plainTextToken;

        $this->bearer($laptop, 'POST', '/api/auth/logout')->assertNoContent();

        $this->me($laptop)->assertUnauthorized();
        $this->me($phone)->assertOk();
        $this->me($otherUser)->assertOk();
    }

    public function test_logout_without_a_token_is_401(): void
    {
        $this->postJson('/api/auth/logout')->assertUnauthorized();
    }

    public function test_the_old_default_user_route_is_gone(): void
    {
        $this->bearer($this->token(), 'GET', '/api/user')->assertNotFound();
    }
}
