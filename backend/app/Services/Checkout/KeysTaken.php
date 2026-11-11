<?php

namespace App\Services\Checkout;

use RuntimeException;

/**
 * Internal signal: the reserve query found fewer free keys than requested,
 * because another checkout holds them (locked right now, or reserved since the
 * quote). Thrown inside the reservation savepoint to roll it back; checkout
 * turns it into a 409 out_of_stock.
 */
final class KeysTaken extends RuntimeException {}
