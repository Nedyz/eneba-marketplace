<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\OrderIndexRequest;
use App\Http\Requests\PayOrderRequest;
use App\Http\Requests\PlaceOrderRequest;
use App\Http\Resources\OrderResource;
use App\Http\Resources\OrderSummaryResource;
use App\Models\Order;
use App\Services\Checkout\CheckoutService;
use App\Services\Checkout\OrderExpirer;
use App\Services\Checkout\OrderHistory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Checkout endpoints (D34). Thin: the rules live in CheckoutService. Conflicts
 * (409) and declines (402) are exceptions that render themselves.
 */
class OrderController extends Controller
{
    public function __construct(private CheckoutService $checkout, private OrderExpirer $expirer) {}

    /**
     * GET /api/orders: the signed-in user's order history (D35), newest first,
     * 10 per page, no cancelled orders, no keys. Rules in OrderHistory.
     */
    public function index(OrderIndexRequest $request, OrderHistory $history): AnonymousResourceCollection
    {
        return OrderSummaryResource::collection(
            $history->page($request->user(), $request->page(), OrderIndexRequest::PER_PAGE),
        );
    }

    /** POST /api/orders: create a pending order and reserve its keys (201). */
    public function store(PlaceOrderRequest $request): JsonResponse
    {
        $order = $this->checkout->placeOrder($request->user(), $request->lines(), $request->expectedTotalCents());

        return (new OrderResource($order))->response()->setStatusCode(201);
    }

    /** GET /api/orders/{id}: the order, with its keys once paid. */
    public function show(Request $request, Order $order): OrderResource
    {
        $this->authorizeOwner($request, $order);

        return new OrderResource($this->expirer->expireIfDue($order));
    }

    /** POST /api/orders/{id}/pay: simulated payment (200 paid, 402 declined, 409 conflicts). */
    public function pay(PayOrderRequest $request, Order $order): OrderResource
    {
        $this->authorizeOwner($request, $order);

        return new OrderResource($this->checkout->pay($order, $request->card()));
    }

    /**
     * Someone else's order is a 404, not a 403: a 403 would confirm that an
     * order with this id exists.
     */
    private function authorizeOwner(Request $request, Order $order): void
    {
        abort_unless($order->user_id === $request->user()->id, 404);
    }
}
