<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ClientRouteMapTest extends TestCase
{
    public function test_order_and_branch_route_calls_are_present_in_the_client_route_map(): void
    {
        $clientMap = (string) file_get_contents(resource_path('js/app.js'));
        $components = [
            resource_path('js/Pages/Admin/Orders.vue'),
            resource_path('js/Pages/Admin/Branches.vue'),
            resource_path('js/Pages/Admin/Roster.vue'),
            resource_path('js/Pages/Mobile/Orders.vue'),
            resource_path('js/Pages/Mobile/Wallet.vue'),
        ];

        foreach ($components as $component) {
            $source = (string) file_get_contents($component);
            preg_match_all("/route\\(\\s*'([^']+)'/", $source, $matches);

            foreach (array_unique($matches[1]) as $name) {
                $this->assertNotNull(
                    Route::getRoutes()->getByName($name),
                    "The route call [{$name}] in {$component} is not registered by Laravel."
                );

                $this->assertMatchesRegularExpression(
                    "/^\\s*'".preg_quote($name, '/')."'\\s*:\\s*\\{\\s*uri:/m",
                    $clientMap,
                    "The route call [{$name}] in {$component} is missing from the Ziggy client map."
                );
            }
        }
    }

    public function test_order_and_branch_mutation_definitions_match_the_laravel_routes(): void
    {
        $clientMap = (string) file_get_contents(resource_path('js/app.js'));
        $expected = [
            'app.orders.archive' => ['uri' => 'app/orders/{order}/archive', 'method' => 'POST'],
            'app.wallet.budget.reduce' => ['uri' => 'app/wallet/budget/reduce', 'method' => 'POST'],
            'admin.orders.update' => ['uri' => 'dashboard/orders/{order}', 'method' => 'PUT'],
            'admin.orders.destroy' => ['uri' => 'dashboard/orders/{order}', 'method' => 'DELETE'],
            'admin.branches.destroy' => ['uri' => 'dashboard/branches/{branch}', 'method' => 'DELETE'],
            'admin.users.courier-deduction.update' => ['uri' => 'dashboard/users/{user}/courier-deduction', 'method' => 'PATCH'],
        ];

        foreach ($expected as $name => $definition) {
            $serverRoute = Route::getRoutes()->getByName($name);

            $this->assertNotNull($serverRoute, "Laravel route [{$name}] is not registered.");
            $this->assertSame($definition['uri'], $serverRoute->uri());
            $this->assertContains($definition['method'], $serverRoute->methods());
            $this->assertMatchesRegularExpression(
                "/^\\s*'".preg_quote($name, '/')."'\\s*:\\s*\\{\\s*uri:\\s*'".preg_quote($definition['uri'], '/')."'\\s*,\\s*methods:\\s*\\[[^\\]]*'".$definition['method']."'/m",
                $clientMap,
                "The Ziggy definition for [{$name}] does not match Laravel."
            );
        }
    }
}
