<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ClientRouteMapTest extends TestCase
{
    public function test_dashboard_actions_used_by_vue_are_present_in_the_client_route_map(): void
    {
        $source = file_get_contents(resource_path('js/app.js'));

        $routes = [
            'admin.orders.reoffer-overdue-pickup',
            'admin.users.merchant-verification',
            'admin.users.destroy',
            'admin.branch.orders.status',
            'admin.branch.orders.courier',
            'admin.branch.orders.reoffer-overdue-pickup',
            'admin.branch.users.update',
            'admin.branch.users.status',
            'admin.branch.users.merchant-verification',
            'admin.branch.users.documents.review',
            'admin.branch.users.destroy',
        ];

        foreach ($routes as $name) {
            $this->assertTrue(Route::has($name), "Laravel route [{$name}] is missing.");
            $this->assertStringContainsString("'{$name}':", $source, "Client route [{$name}] is missing.");
        }
    }

    public function test_dashboard_order_actions_only_offer_the_five_live_delivery_states(): void
    {
        $source = file_get_contents(resource_path('js/Pages/Admin/Orders.vue'));

        $this->assertStringContainsString(
            "const statusOptions = ['pending', 'approved', 'courier', 'delivered', 'returned']",
            $source,
        );
        $this->assertStringNotContainsString(
            "const statusOptions = ['pending', 'approved', 'courier', 'delivered', 'returned', 'cancelled'",
            $source,
        );
    }
}
