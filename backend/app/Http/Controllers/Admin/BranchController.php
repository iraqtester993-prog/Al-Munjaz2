<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;

class BranchController extends Controller
{
    public function index(Request $request)
    {
        $tenantId = $this->tenantIdFor($request);
        $branches = Branch::withoutGlobalScopes()->where('tenant_id', $tenantId)
            ->withCount(['users', 'originOrders'])
            ->orderBy('name_ar')
            ->get()
            ->map(fn (Branch $branch) => [
                'id' => $branch->id,
                'code' => $branch->code,
                'name' => $branch->name_ar ?: $branch->name_en,
                'city' => $branch->city,
                'cash_balance' => $branch->cash_balance,
                'is_active' => $branch->is_active,
                'users_count' => $branch->users_count,
                'orders_count' => $branch->origin_orders_count,
            ]);

        return Inertia::render('Admin/Branches', ['branches' => $branches]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:20'],
            'name_ar' => ['required', 'string', 'max:120'],
            'city' => ['required', 'string', 'max:60'],
            'phone' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:255'],
        ]);

        Branch::withoutGlobalScopes()->create($data + [
            'tenant_id' => $this->tenantIdFor($request),
            'is_active' => true,
        ]);

        return back()->with('success', 'تم إنشاء الفرع بنجاح.');
    }

    private function tenantIdFor(Request $request): int
    {
        if ($request->user()?->tenant_id) {
            return (int) $request->user()->tenant_id;
        }

        return (int) Tenant::firstOrCreate(
            ['slug' => 'almunjaz-system'],
            ['name' => 'المنجز السريع', 'kind' => 'company', 'status' => 'active']
        )->id;
    }
}
