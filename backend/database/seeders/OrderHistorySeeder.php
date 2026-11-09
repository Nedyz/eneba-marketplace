<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Users and past orders.
 *
 * Why seed orders at all: Best sellers ranks products by units sold in the last
 * 30 days. With an empty order table that block would be empty. Orders are spread
 * over 45 days, so the 30-day window really filters something out.
 *
 * Every seeded sale follows the same rules as a real one: the order is paid, the
 * item has the price and names copied at purchase time, and the sold keys point
 * to that item. Popular games are bought more often, so the top list is clear.
 */
class OrderHistorySeeder extends Seeder
{
    public const DEMO_EMAIL = 'demo@example.com';

    public const DEMO_PASSWORD = 'password';

    private const BUYER_COUNT = 10;

    private const PAID_ORDER_COUNT = 60;

    public function run(): void
    {
        $rng = new Randomizer(new Mt19937(44));
        $now = now();

        // One hash for all seeded users: hashing is deliberately slow (bcrypt).
        $password = Hash::make(self::DEMO_PASSWORD);
        $users = [['name' => 'Demo User', 'email' => self::DEMO_EMAIL]];
        for ($i = 1; $i <= self::BUYER_COUNT; $i++) {
            $users[] = ['name' => "Buyer {$i}", 'email' => "buyer{$i}@example.com"];
        }
        DB::table('users')->insert(array_map(fn ($u) => $u + [
            'password' => $password,
            'email_verified_at' => $now,
            'created_at' => $now->copy()->subDays(60),
            'updated_at' => $now,
        ], $users));
        $userIds = DB::table('users')->orderBy('id')->pluck('id')->all();
        $demoUserId = $userIds[0];

        // Active offers with their available key ids, product popularity and snapshot names.
        $popularNames = $this->popularGameNames();
        $offers = DB::table('offers')
            ->join('products', 'products.id', '=', 'offers.product_id')
            ->join('sellers', 'sellers.id', '=', 'offers.seller_id')
            ->where('offers.is_active', true)
            ->orderBy('offers.id')
            ->get(['offers.id', 'offers.price_cents', 'products.name as product_name', 'sellers.name as seller_name']);
        $availableKeys = DB::table('product_keys')->orderBy('id')->get(['id', 'offer_id'])
            ->groupBy('offer_id')->map(fn ($keys) => $keys->pluck('id')->all())->all();

        $offers = $offers->filter(fn ($o) => isset($availableKeys[$o->id]))->values();
        $popularOffers = $offers->filter(fn ($o) => in_array($o->product_name, $popularNames, true))->values();

        for ($n = 0; $n < self::PAID_ORDER_COUNT; $n++) {
            // The demo user gets the first 3 orders, so their history is not empty.
            $userId = $n < 3 ? $demoUserId : $userIds[$rng->getInt(1, count($userIds) - 1)];
            $createdAt = $now->copy()->subMinutes($rng->getInt(60, 45 * 24 * 60));

            // 1-3 different offers per order; 70% chance each line is a popular game.
            $lines = [];
            $itemCount = $rng->getInt(1, 3);
            while (count($lines) < $itemCount) {
                $pool = $rng->getInt(1, 100) <= 70 ? $popularOffers : $offers;
                $offer = $pool[$rng->getInt(0, $pool->count() - 1)];
                $qty = $rng->getInt(1, 100) <= 85 ? 1 : 2;
                // Never sell the last key: offers seeded with stock stay in stock,
                // only the deliberately empty ones are out of stock.
                if (isset($lines[$offer->id]) || count($availableKeys[$offer->id]) <= $qty) {
                    continue;
                }
                $lines[$offer->id] = [$offer, $qty];
            }

            $this->createPaidOrder($userId, $createdAt, $lines, $availableKeys, $rng);
        }

        // Two non-paid orders for the demo user, so order history shows every outcome.
        // They hold no keys: a failed or expired order releases its keys.
        foreach (['failed' => 5, 'expired' => 12] as $status => $daysAgo) {
            $offer = $popularOffers[$rng->getInt(0, $popularOffers->count() - 1)];
            $createdAt = $now->copy()->subDays($daysAgo);
            $orderId = DB::table('orders')->insertGetId([
                'user_id' => $demoUserId,
                'status' => $status,
                'total_cents' => $offer->price_cents,
                'currency' => 'EUR',
                'expires_at' => $createdAt->copy()->addMinutes(15),
                'created_at' => $createdAt,
                'updated_at' => $createdAt->copy()->addMinutes($status === 'failed' ? 2 : 15),
            ]);
            DB::table('order_items')->insert($this->itemRow($orderId, $offer, 1, $createdAt));
        }
    }

    /**
     * @param  array<int, array{0: object, 1: int}>  $lines  offer id => [offer, quantity]
     * @param  array<int, list<int>>  $availableKeys  offer id => available key ids (consumed here)
     */
    private function createPaidOrder(int $userId, Carbon $createdAt, array $lines, array &$availableKeys, Randomizer $rng): void
    {
        $paidAt = $createdAt->copy()->addMinutes($rng->getInt(1, 10));
        $total = array_sum(array_map(fn ($l) => $l[0]->price_cents * $l[1], $lines));

        $orderId = DB::table('orders')->insertGetId([
            'user_id' => $userId,
            'status' => 'paid',
            'total_cents' => $total,
            'currency' => 'EUR',
            'expires_at' => $createdAt->copy()->addMinutes(15),
            'payment_reference' => 'seed_'.Str::lower($rng->getBytesFromString('abcdefghijklmnopqrstuvwxyz0123456789', 16)),
            'paid_at' => $paidAt,
            'created_at' => $createdAt,
            'updated_at' => $paidAt,
        ]);

        foreach ($lines as [$offer, $qty]) {
            $itemId = DB::table('order_items')->insertGetId($this->itemRow($orderId, $offer, $qty, $createdAt));

            // Take the first $qty available keys of the offer and mark them sold to this item.
            $keyIds = array_splice($availableKeys[$offer->id], 0, $qty);
            DB::table('product_keys')->whereIn('id', $keyIds)->update([
                'status' => 'sold',
                'order_item_id' => $itemId,
                'sold_at' => $paidAt,
                'updated_at' => $paidAt,
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function itemRow(int $orderId, object $offer, int $qty, Carbon $createdAt): array
    {
        return [
            'order_id' => $orderId,
            'offer_id' => $offer->id,
            'quantity' => $qty,
            'unit_price_cents' => $offer->price_cents,
            'product_name' => $offer->product_name,
            'seller_name' => $offer->seller_name,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ];
    }

    /** @return list<string> */
    private function popularGameNames(): array
    {
        $catalog = require __DIR__.'/data/catalog.php';

        return array_values(array_map(
            fn ($g) => $g['name'],
            array_filter($catalog['games'], fn ($g) => $g['popular'] ?? false),
        ));
    }
}
