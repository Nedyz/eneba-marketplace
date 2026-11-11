<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Shared helpers for the auth tests (D33).
 */
abstract class AuthTestCase extends TestCase
{
    use RefreshDatabase;

    /**
     * A request with a bearer token, as the frontend sends it.
     *
     * Laravel's test client reuses one application for every request in a
     * test, and the auth manager remembers the user it resolved. Forgetting
     * the guards first makes each request authenticate from its own header,
     * like a real request does (otherwise a deleted token would look valid).
     */
    protected function bearer(string $token, string $method, string $uri, array $data = []): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->json($method, $uri, $data, ['Authorization' => 'Bearer '.$token]);
    }

    protected function me(string $token): TestResponse
    {
        return $this->bearer($token, 'GET', '/api/auth/me');
    }
}
