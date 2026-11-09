<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductKey;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\OrderHistorySeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The demo seed runs in production on every container start, so it must produce
 * consistent data and must be safe to run twice. These tests check the rules the
 * rest of the app relies on.
 */
class DemoSeedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_seeded_keys_are_encrypted_and_decrypt_through_the_model(): void
    {
        $raw = DB::table('product_keys')->value('code');
        $this->assertDoesNotMatchRegularExpression('/^[A-Z0-9]{5}-[A-Z0-9]{5}-[A-Z0-9]{5}$/', $raw);

        // Bulk insert skipped the cast; reading via Eloquent must still work,
        // which proves the seeder used the same format as the `encrypted` cast.
        $this->assertMatchesRegularExpression('/^[A-Z0-9]{5}-[A-Z0-9]{5}-[A-Z0-9]{5}$/', ProductKey::first()->code);
    }

    public function test_every_sold_key_belongs_to_a_paid_order_item_of_the_same_offer(): void
    {
        $this->assertGreaterThan(0, DB::table('product_keys')->where('status', 'sold')->count());

        $inconsistent = DB::table('product_keys as k')
            ->leftJoin('order_items as i', 'i.id', '=', 'k.order_item_id')
            ->leftJoin('orders as o', 'o.id', '=', 'i.order_id')
            ->where('k.status', 'sold')
            ->where(fn ($q) => $q->whereNull('o.id')
                ->orWhere('o.status', '!=', 'paid')
                ->orWhereColumn('i.offer_id', '!=', 'k.offer_id'))
            ->count();

        $this->assertSame(0, $inconsistent);
    }

    public function test_paid_items_have_exactly_as_many_keys_as_their_quantity(): void
    {
        $mismatched = DB::table('order_items as i')
            ->join('orders as o', 'o.id', '=', 'i.order_id')
            ->where('o.status', 'paid')
            ->whereRaw('i.quantity <> (SELECT count(*) FROM product_keys k WHERE k.order_item_id = i.id)')
            ->count();

        $this->assertSame(0, $mismatched);
    }

    public function test_paid_order_totals_match_their_items(): void
    {
        $wrong = DB::table('orders as o')
            ->where('o.status', 'paid')
            ->whereRaw('o.total_cents <> (SELECT sum(quantity * unit_price_cents) FROM order_items WHERE order_id = o.id)')
            ->count();

        $this->assertSame(0, $wrong);
    }

    public function test_catalog_has_deals_and_every_product_can_be_bought(): void
    {
        $this->assertSame(105, Product::count());
        $this->assertGreaterThan(0, DB::table('offers')->whereNotNull('original_price_cents')->count());

        $unbuyable = Product::query()->whereDoesntHave('offers', fn ($q) => $q
            ->where('is_active', true)
            ->whereHas('keys', fn ($k) => $k->where('status', 'available')))
            ->count();

        $this->assertSame(0, $unbuyable);
    }

    public function test_best_sellers_has_data_inside_and_outside_the_30_day_window(): void
    {
        $paid = DB::table('orders')->where('status', 'paid');

        $this->assertGreaterThan(0, (clone $paid)->where('paid_at', '>=', now()->subDays(30))->count());
        $this->assertGreaterThan(0, (clone $paid)->where('paid_at', '<', now()->subDays(30))->count());
    }

    public function test_demo_user_can_sign_in_with_the_documented_password(): void
    {
        $user = User::where('email', OrderHistorySeeder::DEMO_EMAIL)->firstOrFail();

        $this->assertTrue(Hash::check(OrderHistorySeeder::DEMO_PASSWORD, $user->password));
        $this->assertGreaterThan(0, $user->orders()->where('status', 'paid')->count());
    }

    public function test_seeding_twice_changes_nothing(): void
    {
        $before = DB::table('product_keys')->count();

        $this->seed(DatabaseSeeder::class);

        $this->assertSame($before, DB::table('product_keys')->count());
        $this->assertSame(105, Product::count());
    }

    public function test_database_rejects_a_deal_that_is_not_cheaper(): void
    {
        $offer = DB::table('offers')->first();

        $this->expectException(QueryException::class);
        DB::table('offers')->where('id', $offer->id)->update(['original_price_cents' => $offer->price_cents]);
    }

    public function test_database_rejects_a_sold_key_without_an_order_item(): void
    {
        $this->expectException(QueryException::class);
        DB::table('product_keys')->where('status', 'available')->limit(1)
            ->update(['status' => 'sold', 'sold_at' => now()]);
    }

    public function test_trigram_search_finds_a_misspelled_name(): void
    {
        // Smoke test that the trigram setup works end to end; real search tests come on day 3.
        $names = DB::table('products')->whereRaw('? <% name', ['eldn ring'])->distinct()->pluck('name');

        $this->assertContains('Elden Ring', $names);
    }
}
