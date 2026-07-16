<?php

namespace App\Exceptions;

/**
 * Thrown when an order can't be placed as-is (out of stock, price changed,
 * product unpublished). The message is customer-facing; the checkout controller
 * bounces the visitor back to the cart with it.
 */
class CheckoutException extends \RuntimeException {}
