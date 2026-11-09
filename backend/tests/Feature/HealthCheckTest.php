<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HealthCheckTest extends TestCase
{
    // Runs all migrations once on the test database, then wraps each test in a
    // transaction that is rolled back afterwards (similar to a per-test
    // transaction fixture in xUnit). The pg_trgm migration runs here too.
    use RefreshDatabase;

    public function test_it_reports_ok_when_database_and_pg_trgm_are_available(): void
    {
        $this->getJson('/api/health')
            ->assertOk()
            ->assertJson([
                'status' => 'ok',
                'database' => 'ok',
            ])
            ->assertJsonPath('pg_trgm', fn ($version) => is_string($version) && $version !== '');
    }

    public function test_it_reports_degraded_when_pg_trgm_is_missing(): void
    {
        // CASCADE: the products trigram index depends on the extension, so a plain
        // DROP is refused. Rolled back after the test, so both come back.
        DB::statement('DROP EXTENSION pg_trgm CASCADE');

        $this->getJson('/api/health')
            ->assertStatus(503)
            ->assertJson([
                'status' => 'degraded',
                'database' => 'ok',
                'pg_trgm' => null,
            ]);
    }

    public function test_cors_allows_the_configured_frontend(): void
    {
        config(['cors.allowed_origins' => ['https://eneba-frontend.example']]);

        $this->getJson('/api/health', ['Origin' => 'https://eneba-frontend.example'])
            ->assertHeader('Access-Control-Allow-Origin', 'https://eneba-frontend.example');
    }

    /**
     * The browser blocks a response unless Access-Control-Allow-Origin equals the
     * page's own origin. So the security property is: never send back a
     * disallowed origin.
     *
     * With a single allowed origin, the CORS library always sends that origin
     * (the browser on evil.example sees a mismatch and blocks). With several,
     * it echoes the request origin only if it is on the list.
     */
    public function test_cors_never_allows_other_origins(): void
    {
        foreach ([
            ['https://eneba-frontend.example'],
            ['https://eneba-frontend.example', 'http://localhost:5173'],
        ] as $allowedOrigins) {
            config(['cors.allowed_origins' => $allowedOrigins]);

            $header = $this->getJson('/api/health', ['Origin' => 'https://evil.example'])
                ->headers->get('Access-Control-Allow-Origin');

            $this->assertNotSame('https://evil.example', $header);
            $this->assertNotSame('*', $header);
        }
    }

    public function test_cors_echoes_each_allowed_origin_when_several_are_configured(): void
    {
        config(['cors.allowed_origins' => ['https://eneba-frontend.example', 'http://localhost:5173']]);

        $this->getJson('/api/health', ['Origin' => 'http://localhost:5173'])
            ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:5173');
    }
}
