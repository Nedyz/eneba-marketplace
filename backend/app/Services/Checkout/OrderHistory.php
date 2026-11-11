<?php

namespace App\Services\Checkout;

use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * A buyer's order history (D35): newest first, without cancelled orders.
 */
class OrderHistory
{
    public function __construct(private OrderExpirer $expirer) {}

    /** @return LengthAwarePaginator<int, Order> */
    public function page(User $user, int $page, int $perPage): LengthAwarePaginator
    {
        // Lazy expiry (D10) before reading, so no order is listed as pending
        // after its reservation ended. Never waits for a payment in progress.
        $this->expirer->expireAllDue($user);

        return Order::query()
            ->whereBelongsTo($user)
            // Filtered here, not by the frontend, so every page is full and
            // `total` counts only what is shown. A cancelled order is the side
            // effect of checking out again (D34), not something the buyer did.
            ->where('status', '!=', OrderStatus::Cancelled)
            // id breaks ties between orders created in the same second, so a
            // page never shows an order twice or skips one.
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            // Fixed number of queries per page, whatever its size.
            ->with(['items.offer.product.platform', 'items.offer.product.region'])
            ->paginate(perPage: $perPage, page: $page);
    }
}
