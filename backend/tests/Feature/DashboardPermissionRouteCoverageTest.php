<?php

namespace Tests\Feature;

use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class DashboardPermissionRouteCoverageTest extends TestCase
{
    /**
     * Every delegable write must name the exact capability it consumes. This
     * makes it difficult for a future route to accidentally re-introduce a
     * broad `update` permission after the dashboard matrix was split.
     */
    public function test_delegable_dashboard_mutations_use_their_exact_granular_capability(): void
    {
        $expected = [
            'admin.orders.update' => 'dashboard.permission:orders.edit',
            'admin.orders.destroy' => 'dashboard.permission:orders.delete',
            'admin.orders.status' => 'dashboard.permission:orders.change_status',
            'admin.orders.courier' => 'dashboard.permission:orders.assign_courier',
            'admin.orders.reoffer-overdue-pickup' => 'dashboard.permission:orders.reoffer_overdue_pickup',
            'admin.orders.branches' => 'dashboard.permission:orders.assign_branches',
            'admin.orders.restore' => 'dashboard.permission:orders.restore',

            'admin.branches.store' => 'dashboard.permission:branches.create',
            'admin.branches.update' => 'dashboard.permission:branches.edit',
            'admin.branches.destroy' => 'dashboard.permission:branches.delete',
            'admin.branches.status' => 'dashboard.permission:branches.change_status',
            'admin.branches.access.store' => 'dashboard.permission:branches.manage_access',

            'admin.users.update' => 'dashboard.user-permission:edit',
            'admin.users.courier-deduction.update' => 'dashboard.user-permission:update_deduction',
            'admin.users.status' => 'dashboard.user-permission:change_status',
            'admin.users.merchant-verification' => 'dashboard.user-permission:verify',
            'admin.users.courier-verification' => 'dashboard.user-permission:verify',
            'admin.users.destroy' => 'dashboard.user-permission:delete',
            'admin.users.documents.show' => 'dashboard.user-permission:documents_view',
            'admin.users.documents.review' => 'dashboard.user-permission:documents_review',

            'admin.finance.approve' => 'dashboard.permission:finance.approve',
            'admin.finance.reject' => 'dashboard.permission:finance.reject',
            'admin.finance.settlements.store' => 'dashboard.permission:finance.record_settlement',

            'admin.cashboxes.store' => 'dashboard.permission:cashboxes.create',
            'admin.cashboxes.voucher' => 'dashboard.permission:cashboxes.transfer',
            'admin.cashboxes.transfer' => 'dashboard.permission:cashboxes.transfer',
            'admin.cashboxes.status' => 'dashboard.permission:cashboxes.change_status',

            'admin.pricing.store' => 'dashboard.permission:pricing.create',
            'admin.pricing.update' => 'dashboard.permission:pricing.edit',
            'admin.pricing.status' => 'dashboard.permission:pricing.change_status',

            'admin.platform.companies.store' => 'dashboard.permission:platform.companies_create',
            'admin.platform.companies.update' => 'dashboard.permission:platform.companies_edit',
            'admin.platform.plans.store' => 'dashboard.permission:platform.plans_create',
            'admin.platform.plans.update' => 'dashboard.permission:platform.plans_edit',
            'admin.platform.subscriptions.store' => 'dashboard.permission:platform.subscriptions_create',
            'admin.platform.subscriptions.status' => 'dashboard.permission:platform.subscriptions_change_status',
            'admin.platform.invoices.store' => 'dashboard.permission:platform.invoices_create',
            'admin.platform.invoices.status' => 'dashboard.permission:platform.invoices_change_status',

            'admin.notifications.store' => 'dashboard.permission:notifications.send',

            'admin.settings.branding.update' => 'dashboard.permission:settings.update_branding',
            'admin.settings.support.update' => 'dashboard.permission:settings.update_support',
            'admin.settings.financial-defaults.update' => 'dashboard.permission:settings.update_financial_defaults',
            'admin.settings.courier-deduction-default.update' => 'dashboard.permission:settings.update_courier_deduction_default',
            'admin.settings.timing.update' => 'dashboard.permission:settings.update_timing',
            'admin.settings.public-content.update' => 'dashboard.permission:settings.update_public_content',
            'admin.provinces.store' => 'dashboard.permission:provinces.create',
            'admin.provinces.update' => 'dashboard.permission:provinces.edit',
            'admin.provinces.status' => 'dashboard.permission:provinces.change_status',
            'admin.settings.slides.store' => 'dashboard.permission:content.create',
            'admin.settings.slides.update' => 'dashboard.permission:content.edit',
            'admin.settings.slides.destroy' => 'dashboard.permission:content.delete',

            'admin.loyalty.settings' => 'dashboard.permission:loyalty.update_reward_setting',
            'admin.loyalty.adjust' => 'dashboard.permission:loyalty.adjust_points',
            'admin.chat.send' => 'dashboard.permission:chat.reply',

            'admin.transfers.store' => 'dashboard.permission:transfers.create',
            'admin.transfers.dispatch' => 'dashboard.permission:transfers.dispatch',
            'admin.transfers.receive' => 'dashboard.permission:transfers.receive',
        ];

        foreach ($expected as $name => $middleware) {
            $this->assertRouteAuthorization($name, $middleware);
        }
    }

    public function test_legacy_combined_settings_endpoint_remains_explicitly_compatibility_gated(): void
    {
        // Older compiled browser assets may still submit this route. New
        // settings tabs use the scoped routes asserted above; keeping the
        // old endpoint named here prevents it from silently becoming open.
        $this->assertRouteAuthorization('admin.settings.update', 'dashboard.permission:settings.update');
    }

    public function test_platform_security_routes_remain_super_admin_only_while_branch_management_uses_local_capabilities(): void
    {
        $superAdminRoutes = [
            'admin.dashboard',
            'admin.platform.invitations.store',
        ];

        foreach ($superAdminRoutes as $name) {
            $this->assertRouteAuthorization($name, 'dashboard.super-admin');
        }

        // A branch principal manager uses these exact screens to create,
        // suspend, delete, and profile local system employees. Controllers
        // and BranchDashboardAuthorization enforce the one-branch boundary;
        // route coverage keeps every action tied to its granular capability.
        $branchManagementRoutes = [
            'admin.employees' => 'dashboard.permission:employees.view',
            'admin.employees.store' => 'dashboard.permission:employees.create',
            'admin.employees.invitations.store' => 'dashboard.permission:employees.create',
            'admin.employees.update' => 'dashboard.permission:employees.edit',
            'admin.employees.status' => 'dashboard.permission:employees.change_status',
            'admin.employees.destroy' => 'dashboard.permission:employees.delete',
            'admin.permissions' => 'dashboard.permission:permissions.view',
            'admin.permissions.store' => 'dashboard.permission:permissions.create',
            'admin.permissions.update' => 'dashboard.permission:permissions.edit',
            'admin.permissions.destroy' => 'dashboard.permission:permissions.delete',
            'admin.permissions.assignments.update' => 'dashboard.permission:permissions.assign',
        ];

        foreach ($branchManagementRoutes as $name => $middleware) {
            $this->assertRouteAuthorization($name, $middleware);
        }
    }

    private function assertRouteAuthorization(string $name, string $expectedMiddleware): void
    {
        $route = Route::getRoutes()->getByName($name);

        $this->assertInstanceOf(LaravelRoute::class, $route, "Dashboard route [{$name}] is missing.");

        $authorizationMiddleware = array_values(array_filter(
            $route->gatherMiddleware(),
            static fn (string $middleware): bool => str_starts_with($middleware, 'dashboard.permission:')
                || str_starts_with($middleware, 'dashboard.user-permission:')
                || $middleware === 'dashboard.super-admin',
        ));

        $this->assertSame(
            [$expectedMiddleware],
            $authorizationMiddleware,
            "Dashboard route [{$name}] must use only [{$expectedMiddleware}] as its dashboard authorization gate.",
        );
    }
}
