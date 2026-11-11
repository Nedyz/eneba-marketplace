<?php

/**
 * Checkout race (D34): many buyers at once against a real server.
 * Run through race.sh, which prepares the database and the server.
 *
 * Each round: a new offer with K keys; N buyers send POST /orders at the same
 * moment. Expected: exactly K orders (201), N - K "409 out_of_stock", and in
 * the database every key reserved at most once and every order holding
 * exactly its one key. Then one winner sends 5 pay requests at once (a
 * double click, five times over): exactly one real charge (fake gateway log),
 * every answer 200 with the same keys or 409 payment_in_progress, keys sold.
 */

use App\Models\Offer;
use App\Models\Order;
use App\Models\Platform;
use App\Models\Product;
use App\Models\ProductKey;
use App\Models\Region;
use App\Models\Seller;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (config('database.connections.pgsql.database') !== 'eneba_race') {
    fwrite(STDERR, "Refusing to run: DB_DATABASE must be eneba_race (use race.sh).\n");
    exit(1);
}

[$api, $rounds, $buyerCount, $keyCount] = [$argv[1], (int) $argv[2], (int) $argv[3], (int) $argv[4]];
$log = storage_path('logs/laravel.log');
$logStart = is_file($log) ? filesize($log) : 0;

$buyers = User::factory()->count($buyerCount)->create();
$tokens = $buyers->map(fn (User $u) => $u->createToken('race')->plainTextToken)->all();
$platform = Platform::factory()->create();
$region = Region::factory()->create();
$seller = Seller::factory()->create();

$failures = [];
$maxParallel = 0;
$fail = function (int $round, string $what) use (&$failures) {
    $failures[] = "round {$round}: {$what}";
};

for ($round = 1; $round <= $rounds; $round++) {
    $product = Product::factory()->for($platform)->for($region)->create();
    $offer = Offer::factory()->for($product)->for($seller)->price(1000)->withKeys($keyCount)->create();

    // ---- N buyers, K keys, all at once.
    $responses = parallel(array_map(fn (string $token) => [
        'POST', "{$api}/orders", $token,
        ['lines' => [['offer_id' => $offer->id, 'qty' => 1]], 'expected_total_cents' => 1000],
    ], $tokens), $parallelSeen);
    $maxParallel = max($maxParallel, $parallelSeen);

    $created = array_values(array_filter($responses, fn ($r) => $r['status'] === 201));
    $conflicts = array_filter($responses, fn ($r) => $r['status'] === 409 && ($r['body']['code'] ?? null) === 'out_of_stock');
    if (count($created) !== $keyCount) {
        $fail($round, count($created)." orders created, expected {$keyCount}");
    }
    if (count($created) + count($conflicts) !== $buyerCount) {
        $other = array_map(fn ($r) => $r['status'], array_filter($responses, fn ($r) => ! in_array($r['status'], [201, 409])));
        $fail($round, 'unexpected answers: '.json_encode(array_values($other)));
    }

    // ---- Database: each key reserved at most once, each order holds exactly one key.
    $reserved = ProductKey::where('offer_id', $offer->id)->where('status', 'reserved')->count();
    $orderIds = array_map(fn ($r) => $r['body']['data']['id'], $created);
    $keysPerOrder = DB::table('product_keys')
        ->join('order_items', 'order_items.id', '=', 'product_keys.order_item_id')
        ->whereIn('order_items.order_id', $orderIds ?: [0])
        ->where('product_keys.status', 'reserved')
        ->groupBy('order_items.order_id')
        ->selectRaw('order_items.order_id, count(*) AS n')
        ->pluck('n', 'order_id')->map(fn ($n) => (int) $n);
    if ($reserved !== count($created)) {
        $fail($round, "{$reserved} keys reserved for ".count($created).' orders');
    }
    foreach ($orderIds as $id) {
        if (($keysPerOrder[$id] ?? 0) !== 1) {
            $fail($round, "order {$id} holds ".($keysPerOrder[$id] ?? 0).' keys');
        }
    }

    // ---- One winner clicks Pay five times at once.
    if ($created === []) {
        continue;
    }
    $winner = $created[0]['body']['data']['id'];
    $token = $tokens[array_search($created[0], $responses, true)];
    $card = ['card_number' => '4242 4242 4242 4242', 'card_expiry' => '12/30', 'card_cvc' => '123'];
    $pays = parallel(array_fill(0, 5, ['POST', "{$api}/orders/{$winner}/pay", $token, $card]), $parallelSeen);
    $maxParallel = max($maxParallel, $parallelSeen);

    $paid = array_filter($pays, fn ($r) => $r['status'] === 200 && $r['body']['data']['status'] === 'paid');
    $inProgress = array_filter($pays, fn ($r) => $r['status'] === 409 && $r['body']['code'] === 'payment_in_progress');
    if (count($paid) + count($inProgress) !== 5 || count($paid) === 0) {
        $fail($round, 'pay answers: '.json_encode(array_map(fn ($r) => $r['status'].' '.($r['body']['code'] ?? $r['body']['data']['status'] ?? ''), $pays)));
    }
    $keySets = array_unique(array_map(fn ($r) => json_encode($r['body']['data']['items'][0]['keys']), $paid));
    if (count($keySets) !== 1) {
        $fail($round, 'paid answers show different keys');
    }
    $order = Order::findOrFail($winner);
    if ($order->status->value !== 'paid') {
        $fail($round, "order {$winner} is {$order->status->value}");
    }
    $chargeKey = "order-{$winner}-{$order->created_at->getTimestamp()}";
    $charges = substr_count((string) file_get_contents($log, offset: $logStart), "fake_payment.charged {\"idempotency_key\":\"{$chargeKey}\"");
    if ($charges !== 1) {
        $fail($round, "order {$winner} charged {$charges} times");
    }
    $sold = ProductKey::where('offer_id', $offer->id)->where('status', 'sold')->count();
    if ($sold !== 1) {
        $fail($round, "{$sold} keys sold for one paid order");
    }

    echo sprintf("round %2d: %d created, %d out of stock; pay x5: %d paid, %d in progress, %d charge(s)\n",
        $round, count($created), count($conflicts), count($paid), count($inProgress), $charges);
}

echo "\nMost requests seen in flight at the same time: {$maxParallel}\n";
if ($failures !== []) {
    echo "\nFAILED (".count($failures)."):\n  ".implode("\n  ", array_slice($failures, 0, 20))."\n";
    exit(1);
}
echo "OK: {$rounds} rounds, {$buyerCount} buyers for {$keyCount} keys each, no key reserved twice, one charge per order.\n";

/**
 * Sends all requests at the same moment (curl_multi) and returns the answers
 * in request order. $maxInFlight: the largest number of requests whose
 * [sent, answered] intervals overlapped, to show they really ran in parallel.
 *
 * @param  list<array{0: string, 1: string, 2: string, 3: array}>  $requests
 */
function parallel(array $requests, ?int &$maxInFlight): array
{
    $multi = curl_multi_init();
    $handles = [];
    foreach ($requests as [$method, $url, $token, $body]) {
        $h = curl_init($url);
        curl_setopt_array($h, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS => json_encode($body),
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json', "Authorization: Bearer {$token}"],
            CURLOPT_TIMEOUT => 30,
        ]);
        curl_multi_add_handle($multi, $h);
        $handles[] = $h;
    }
    do {
        curl_multi_exec($multi, $running);
        curl_multi_select($multi);
    } while ($running > 0);

    $events = [];
    $out = [];
    foreach ($handles as $h) {
        $start = curl_getinfo($h, CURLINFO_PRETRANSFER_TIME);
        $events[] = [$start, 1];
        $events[] = [curl_getinfo($h, CURLINFO_TOTAL_TIME), -1];
        $out[] = ['status' => curl_getinfo($h, CURLINFO_RESPONSE_CODE), 'body' => json_decode((string) curl_multi_getcontent($h), true)];
        curl_multi_remove_handle($multi, $h);
    }
    curl_multi_close($multi);

    usort($events, fn ($a, $b) => $a[0] <=> $b[0] ?: $a[1] <=> $b[1]);
    $maxInFlight = $now = 0;
    foreach ($events as [, $delta]) {
        $maxInFlight = max($maxInFlight, $now += $delta);
    }

    return $out;
}
