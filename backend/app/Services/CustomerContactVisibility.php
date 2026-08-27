<?php

namespace App\Services;

use App\Models\Order;
use App\Models\User;

/**
 * Centralises the customer-contact privacy boundary.
 *
 * Customer numbers must not be treated as presentation-only data: a courier
 * receives them from the server only after the parcel has been recorded as
 * physically collected.  Merchants and dashboard users already own or
 * administer the order, so this restriction applies solely to courier roles.
 */
class CustomerContactVisibility
{
    public function canReveal(Order $order, User $viewer): bool
    {
        if (! $viewer->isCourierRole()) {
            return true;
        }

        return (int) $order->courier_id === (int) $viewer->id
            && $order->picked_at !== null;
    }
}
