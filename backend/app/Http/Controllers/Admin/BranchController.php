<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\Cashbox;
use App\Models\Order;
use App\Models\Scopes\TenantScope;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class BranchController extends Controller
{
    public function index(Request $request)
    {
        $branches = $this->networkBranches()
            ->withCount([
                'users',
                'originOrders as outbound_orders_count',
                'destinationOrders as inbound_orders_count',
            ])
            ->orderBy('name_ar')
            ->get()
            ->map(fn (Branch $branch) => [
                'id' => $branch->id,
                'code' => $branch->code,
                'name_ar' => $branch->name_ar,
                'name_en' => $branch->name_en,
                'name_ku' => $branch->name_ku,
                'city' => $branch->city,
                'phone' => $branch->phone,
                'address' => $branch->address,
                'cash_balance' => $branch->cash_balance,
                'is_active' => $branch->is_active,
                'users_count' => $branch->users_count,
                // The route may contain a branch at either end. Keep these
                // counts separate so operations never mistake arrivals for
                // departures (or count a cross-branch route twice).
                'outbound_orders_count' => $branch->outbound_orders_count,
                'inbound_orders_count' => $branch->inbound_orders_count,
            ]);

        return Inertia::render('Admin/Branches', ['branches' => $branches]);
    }

    public function store(Request $request)
    {
        $platformTenant = Tenant::platform();
        $branch = Branch::withoutGlobalScope(TenantScope::class)->create($this->validatedBranchData($request) + [
            'tenant_id' => $platformTenant->id,
            'is_platform_managed' => true,
            'is_active' => true,
        ]);

        $this->record($request, $branch, 'branch.created', ['is_active' => true]);

        return back()->with('success', __('Branch created successfully.'));
    }

    public function update(Request $request, int $branch)
    {
        $branch = $this->findNetworkBranch($branch);
        $before = $branch->only(['code', 'name_ar', 'name_en', 'name_ku', 'city', 'phone', 'address']);

        $branch->update($this->validatedBranchData($request, $branch));

        $this->record($request, $branch, 'branch.updated', [
            'before' => $before,
            'after' => $branch->only(array_keys($before)),
        ]);

        return back()->with('success', __('Branch updated successfully.'));
    }

    public function status(Request $request, int $branch)
    {
        $data = $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);

        $branch = $this->findNetworkBranch($branch);
        $isActive = (bool) $data['is_active'];

        if (! $isActive && $branch->is_active && $this->hasOpenRouteOrders($branch)) {
            return back()->withErrors([
                'is_active' => __('An active order is still assigned to this branch. Reassign it before deactivating the branch.'),
            ]);
        }

        $branch->update(['is_active' => $isActive]);
        // A branch cashbox cannot be operated while its branch is inactive.
        // Keep this in step with the branch even when the cashbox was created
        // by an earlier release.
        Cashbox::withoutGlobalScope(TenantScope::class)
            ->where('branch_id', $branch->id)
            ->update(['is_active' => $isActive]);
        $this->record($request, $branch, 'branch.status_updated', ['is_active' => $isActive]);

        return back()->with('success', __('Branch status updated successfully.'));
    }

    /** @return Builder<Branch> */
    private function networkBranches(): Builder
    {
        return Branch::withoutGlobalScope(TenantScope::class)
            ->where('is_platform_managed', true);
    }

    private function findNetworkBranch(int $id): Branch
    {
        return $this->networkBranches()->findOrFail($id);
    }

    /** @return array<string, mixed> */
    private function validatedBranchData(Request $request, ?Branch $branch = null): array
    {
        foreach (['code', 'name_ar', 'name_en', 'name_ku', 'city', 'phone', 'address'] as $field) {
            if (is_string($request->input($field))) {
                $value = trim((string) $request->input($field));
                $request->merge([$field => $field === 'code' ? strtoupper($value) : $value]);
            }
        }

        $platformTenant = Tenant::platform();
        $uniqueCode = Rule::unique('branches', 'code')
            ->where('tenant_id', $platformTenant->id);

        if ($branch) {
            $uniqueCode->ignore($branch->id);
        }

        $data = $request->validate([
            'code' => ['required', 'string', 'max:20', $uniqueCode],
            'name_ar' => ['required', 'string', 'max:120'],
            'name_en' => ['nullable', 'string', 'max:120'],
            'name_ku' => ['nullable', 'string', 'max:120'],
            'city' => ['required', 'string', 'max:60'],
            'phone' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:255'],
        ]);

        return $data;
    }

    private function hasOpenRouteOrders(Branch $branch): bool
    {
        return Order::withoutGlobalScope(TenantScope::class)
            ->whereNotIn('status', ['delivered', 'returned', 'cancelled', 'damaged'])
            ->where(function (Builder $orders) use ($branch): void {
                $orders
                    ->where('origin_branch_id', $branch->id)
                    ->orWhere('destination_branch_id', $branch->id);
            })
            ->exists();
    }

    /** @param array<string, mixed> $data */
    private function record(Request $request, Branch $branch, string $action, array $data): void
    {
        ActivityLog::create([
            'tenant_id' => $branch->tenant_id,
            'user_id' => $request->user()->id,
            'action' => $action,
            'subject_type' => 'branch',
            'subject_id' => $branch->id,
            'data' => $data,
            'ip' => $request->ip(),
        ]);
    }
}
