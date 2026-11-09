<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Separate class without RefreshDatabase: this test breaks the database
 * connection on purpose, and RefreshDatabase would try to roll back a
 * transaction on that broken connection afterwards.
 */
class HealthCheckDatabaseDownTest extends TestCase
{
    public function test_it_returns_503_without_leaking_connection_details(): void
    {
        // Point the connection at a port where nothing listens, and drop the
        // existing connection so the next query reconnects with the bad config.
        config(['database.connections.pgsql.port' => 1]);
        DB::purge('pgsql');

        $response = $this->getJson('/api/health')
            ->assertStatus(503)
            ->assertJson([
                'status' => 'degraded',
                'database' => 'error',
                'pg_trgm' => null,
            ]);

        // Only the four documented fields, no error message or host name.
        $this->assertSame(['status', 'database', 'pg_trgm', 'time'], array_keys($response->json()));
    }
}
