<?php

namespace App\Services;

use App\Models\Order;
use App\Models\User;

/**
 * Keeps the API response aligned with the application order cards.
 *
 * The platform policy is to show the customer phone for every order status to
 * every participant who has already passed the order access check.  Access
 * itself remains enforced by the order controllers; this service deliberately
 * does not add a second, contradictory presentation-only restriction.
 */
class CustomerContactVisibility
{
    public function canReveal(Order $order, User $viewer): bool
    {
        return true;
    }
}
