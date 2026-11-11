<?php

namespace Tests\Feature\Checkout;

use App\Models\Offer;
use App\Models\Order;
use App\Models\Platform;
use App\Models\Product;
use App\Models\ProductKey;
use App\Models\Region;
use App\Models\Seller;
use App\Models\User;
use App\Payments\PaymentGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Shared setup for the checkout tests (D34): a fixed clock, a recording
 * gateway, and helpers that call the API the way the frontend does.
 */
abstract class CheckoutTestCase extends TestCase
{
    use RefreshDatabase;

    public const SUCCESS = '4242 4242 4242 4242';

    public const DECLINED = '4000 0000 0000 0002';

    protected RecordingGateway $gateway;

    protected User $buyer;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-13 12:00:00');
        $this->gateway = new RecordingGateway;
        $this->app->instance(PaymentGateway::class, $this->gateway);
        $this->buyer = User::factory()->create();
        Sanctum::actingAs($this->buyer);
    }

    protected function offer(int $priceCents = 1000, int $keys = 3, string $name = 'Elden Ring'): Offer
    {
        $product = Product::factory()
            ->for(Platform::factory()->create())
            ->for(Region::factory()->create())
            ->create(['name' => $name]);

        return Offer::factory()->for($product)->for(Seller::factory()->create(['name' => 'KeyHub']))
            ->price($priceCents)->withKeys($keys)->create();
    }

    /** @param  list<array{0: Offer, 1: int}>  $lines */
    protected function place(array $lines, ?int $expectedTotal = null): TestResponse
    {
        $expectedTotal ??= array_sum(array_map(fn ($l) => $l[0]->price_cents * $l[1], $lines));

        return $this->postJson('/api/orders', [
            'lines' => array_map(fn ($l) => ['offer_id' => $l[0]->id, 'qty' => $l[1]], $lines),
            'expected_total_cents' => $expectedTotal,
        ]);
    }

    /** Places an order that must succeed and returns it. */
    protected function placed(Offer $offer, int $qty = 1): Order
    {
        return Order::findOrFail($this->place([[$offer, $qty]])->assertCreated()->json('data.id'));
    }

    protected function pay(Order|int $order, string $card = self::SUCCESS, string $expiry = '12/30', string $cvc = '123'): TestResponse
    {
        $id = $order instanceof Order ? $order->id : $order;

        return $this->postJson("/api/orders/{$id}/pay", [
            'card_number' => $card, 'card_expiry' => $expiry, 'card_cvc' => $cvc,
        ]);
    }

    /** @return array<string, int> status => count, for the offer's keys */
    protected function keyStatuses(Offer $offer): array
    {
        return ProductKey::where('offer_id', $offer->id)->get()->countBy('status')->sortKeys()->all();
    }

    /** Keys currently reserved for the order (linked to one of its items). */
    protected function reservedKeys(Order $order)
    {
        return ProductKey::where('status', 'reserved')
            ->whereIn('order_item_id', $order->items()->select('id'))->orderBy('id')->get();
    }

    protected function actingAsSomeoneElse(): User
    {
        $other = User::factory()->create();
        Sanctum::actingAs($other);

        return $other;
    }
}
