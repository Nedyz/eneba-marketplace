<?php

namespace Tests\Feature\Catalog;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The search lowers pg_trgm.word_similarity_threshold with
 * set_config(..., is_local => true), which lasts until the end of the
 * surrounding transaction. This checks that the setting is back to the default
 * after a request, so it cannot leak into later queries on the same connection.
 *
 * Separate class WITHOUT RefreshDatabase on purpose: RefreshDatabase wraps each
 * test in a transaction, so the search's own transaction becomes a savepoint, and
 * a transaction-local setting survives a savepoint until the OUTER transaction
 * ends. Inside RefreshDatabase this test would see 0.4 even with a correct
 * implementation, and could not tell a correct one from a leaking one.
 *
 * No rows are created: they would be committed and leak into other tests. An
 * empty catalog still runs the full search path, including set_config.
 */
class SearchThresholdIsolationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Without RefreshDatabase nothing migrates the test database. A no-op when
        // another test class already did.
        $this->artisan('migrate', ['--force' => true]);
    }

    public function test_store_search_does_not_leak_the_threshold(): void
    {
        $this->assertSame('0.6', $this->threshold(), 'precondition: pg_trgm default');

        $this->getJson('/api/products?q=elden')->assertOk();

        $this->assertSame(0, DB::transactionLevel(), 'no transaction left open');
        $this->assertSame('0.6', $this->threshold());
    }

    public function test_suggestions_do_not_leak_the_threshold(): void
    {
        $this->getJson('/api/search/suggestions?q=elden')->assertOk();

        $this->assertSame(0, DB::transactionLevel(), 'no transaction left open');
        $this->assertSame('0.6', $this->threshold());
    }

    /**
     * Same connection the request used (one PDO per process in tests). pg_trgm's
     * settings are only known to a session after its library is loaded, so a
     * trigram function is called first (a separate statement, because the order
     * in which one SELECT evaluates its columns is not guaranteed).
     */
    private function threshold(): string
    {
        DB::select("SELECT word_similarity('a', 'a')");

        return DB::selectOne("SELECT current_setting('pg_trgm.word_similarity_threshold') AS value")->value;
    }
}
