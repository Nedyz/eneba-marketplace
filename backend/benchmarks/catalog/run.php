<?php

/**
 * Measures the store and search queries on the benchmark database (D23, D28).
 *
 * Each scenario is a real GET request sent through the app's HTTP kernel. The
 * SQL the app ran (from Laravel's query log, with its bindings) is then run
 * again with EXPLAIN ANALYZE, 5 times, and the best execution time is reported.
 * Search requests are re-run inside a transaction with the same
 * set_config(...) as ProductSearch::withThreshold(), so they see threshold 0.4.
 *
 * Usage (from backend/, after benchmarks/catalog/build.sh):
 *   php benchmarks/catalog/run.php           # summary table
 *   php benchmarks/catalog/run.php --plans   # plus the last plan of each query
 *
 * Numbers depend on the machine; the plans (which index, how many rows) should not.
 */

use App\Services\Catalog\ProductSearch;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

const BENCH_DB = 'eneba_bench';
const RUNS = 5;

// Point the app at the benchmark database before it boots. Real environment
// variables win over .env (Laravel loads .env without overwriting them).
foreach (['DB_CONNECTION' => 'pgsql', 'DB_DATABASE' => BENCH_DB, 'DB_URL' => ''] as $key => $value) {
    putenv("{$key}={$value}");
    $_ENV[$key] = $_SERVER[$key] = $value;
}

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

// Safety net: never measure (or EXPLAIN ANALYZE) anything but the throwaway database.
if (DB::connection()->getDatabaseName() !== BENCH_DB) {
    fwrite(STDERR, 'Refusing to run: connected to '.DB::connection()->getDatabaseName().', expected '.BENCH_DB." (cached config?)\n");
    exit(1);
}

$showPlans = in_array('--plans', $argv, true);

$scenarios = [
    // D23: store listing
    'D23 newest (default)' => [],
    'D23 price_asc' => ['sort' => 'price_asc'],
    'D23 platform + price range' => ['platform' => ['steam'], 'price_min' => 1000, 'price_max' => 5000],
    // D28: search, worst cases with a price filter (the count needs the from price too)
    'D28 q=witcer (typo)' => ['q' => 'witcer'],
    'D28 q=dragon + price' => ['q' => 'dragon', 'price_min' => 1000, 'price_max' => 5000],
    'D28 q=ring + price' => ['q' => 'ring', 'price_min' => 1000, 'price_max' => 5000],
    'D28 q=king + price' => ['q' => 'king', 'price_min' => 1000, 'price_max' => 5000],
    'D28 q=dr + price' => ['q' => 'dr', 'price_min' => 1000, 'price_max' => 5000],
    'D28 q=s + price' => ['q' => 's', 'price_min' => 1000, 'price_max' => 5000],
];

printf("%-30s %-6s %10s  %s\n", 'scenario', 'query', 'best (ms)', 'products accessed via');

foreach ($scenarios as $label => $params) {
    DB::flushQueryLog();
    DB::enableQueryLog();
    $response = $kernel->handle(Request::create('/api/products', 'GET', $params));
    DB::disableQueryLog();
    if ($response->getStatusCode() !== 200) {
        fwrite(STDERR, "{$label}: HTTP {$response->getStatusCode()}\n");
        exit(1);
    }

    foreach (DB::getQueryLog() as $logged) {
        $sql = $logged['query'];
        $kind = match (true) {
            str_starts_with($sql, 'select count(*)') => 'count',
            str_starts_with($sql, 'select "products".*') => 'page',
            default => null, // set_config, eager loads of platforms/regions
        };
        if ($kind === null) {
            continue;
        }

        [$best, $plan] = explainAnalyze($sql, $logged['bindings'], isset($params['q']));
        printf("%-30s %-6s %10.2f  %s\n", $label, $kind, $best, productsAccess($plan));
        if ($showPlans) {
            echo $plan, "\n\n";
        }
    }
}

// D28: the same search condition written as a function call cannot use the index.
foreach ([
    'D28 witcer: operator <%' => "SELECT id FROM products WHERE name ILIKE '%witcer%' OR 'witcer' <% name",
    'D28 witcer: function >= 0.4' => "SELECT id FROM products WHERE name ILIKE '%witcer%' OR word_similarity('witcer', name) >= 0.4",
] as $label => $sql) {
    [$best, $plan] = explainAnalyze($sql, [], true);
    printf("%-30s %-6s %10.2f  %s\n", $label, 'raw', $best, productsAccess($plan));
    if ($showPlans) {
        echo $plan, "\n\n";
    }
}

/** @return array{0: float, 1: string} best execution time in ms, last plan */
function explainAnalyze(string $sql, array $bindings, bool $search): array
{
    return DB::transaction(function () use ($sql, $bindings, $search) {
        if ($search) {
            DB::select("SELECT set_config('pg_trgm.word_similarity_threshold', ?, true)", [ProductSearch::WORD_SIMILARITY_THRESHOLD]);
        }
        $best = INF;
        $plan = '';
        for ($i = 0; $i < RUNS; $i++) {
            $rows = DB::select('EXPLAIN (ANALYZE, BUFFERS, COSTS OFF) '.$sql, $bindings);
            $plan = implode("\n", array_map(fn ($row) => $row->{'QUERY PLAN'}, $rows));
            preg_match('/Execution Time: ([\d.]+) ms/', $plan, $m);
            $best = min($best, (float) $m[1]);
        }

        return [$best, $plan];
    });
}

/** How the plan reads the products table: the first products node from the top. */
function productsAccess(string $plan): string
{
    preg_match('/(Seq Scan on products|Index (?:Only )?Scan(?: Backward)? using \S+ on products|Bitmap Index Scan on products\S*)/', $plan, $m);

    return $m[1] ?? '?';
}
