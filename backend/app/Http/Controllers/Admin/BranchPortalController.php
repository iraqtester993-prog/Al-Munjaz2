<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\BranchMembership;
use App\Models\Document;
use App\Models\Notification;
use App\Models\Order;
use App\Models\Scopes\TenantScope;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CourierLocationService;
use App\Services\OrderOperationalAssignmentService;
use App\Services\OrderPickupRecoveryService;
use App\Services\OrderWorkflowService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Operational branch portal for a branch owner or branch manager.
 *
 * This controller is intentionally separate from the platform-wide dashboard.
 * Its branch list is derived from explicit `branch_memberships` records, not
 * from a request-supplied branch id or the user's legacy `branch_id` alone.
 * Every write below repeats the same membership boundary before changing a
 * record, so opening a branch URL never grants access to another branch.
 */
class BranchPortalController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        abort_unless(
            $user instanceof User && in_array($user->role, ['owner', 'branch_manager'], true),
            403,
        );

        $branches = $this->allowedBranches($user);
        $branchIds = $branches->pluck('id')->map(fn ($id) => (int) $id)->all();
        $branchLookup = $branches->keyBy('id');
        $metrics = $this->branchMetrics($branchIds);
        $peopleMetrics = $this->branchPeopleMetrics($branchIds);
        $recentOrders = $this->recentOrders($branchIds);

        $branchPayload = $branches->map(function (Branch $branch) use ($metrics, $peopleMetrics): array {
            $metric = $metrics->get($branch->id);
            $people = $peopleMetrics->get($branch->id, [
                'merchants' => 0,
                'couriers' => 0,
                'online_couriers' => 0,
            ]);
            $membership = $branch->memberships->first();

            return [
                'id' => $branch->id,
                'code' => $branch->code,
                'name' => $this->branchName($branch),
                'name_ar' => $branch->name_ar,
                'name_en' => $branch->name_en,
                'name_ku' => $branch->name_ku,
                'city' => $branch->city,
                'province_id' => $branch->province_id ? (int) $branch->province_id : null,
                'province' => $branch->province ? [
                    'id' => $branch->province->id,
                    'name_ar' => $branch->province->name_ar,
                    'name_en' => $branch->province->name_en,
                    'name_ku' => $branch->province->name_ku,
                ] : null,
                'phone' => $branch->phone,
                'address' => $branch->address,
                'is_active' => $branch->is_active,
                'cash_balance' => (int) $branch->cash_balance,
                'access_role' => $membership?->access_role,
                'orders' => [
                    'total' => (int) ($metric?->total_orders ?? 0),
                    'active' => (int) ($metric?->active_orders ?? 0),
                    'delivered' => (int) ($metric?->delivered_orders ?? 0),
                    'today' => (int) ($metric?->today_orders ?? 0),
                ],
                'people' => [
                    'merchants' => (int) ($people['merchants'] ?? 0),
                    'couriers' => (int) ($people['couriers'] ?? 0),
                    'online_couriers' => (int) ($people['online_couriers'] ?? 0),
                ],
            ];
        })->values();

        return Inertia::render('Admin/BranchPortal', [
            'branches' => $branchPayload,
            'recentOrders' => $recentOrders,
            // Operational lists can contain hundreds of records and their
            // related documents. Keep the first portal response focused on
            // the overview, then resolve each list only when its tab asks for
            // it through an Inertia partial reload. Every callback closes over
            // the same membership-derived branch ids, so a browser request
            // cannot widen its operational boundary.
            'orders' => Inertia::optional(fn () => $this->orders($branchIds)),
            'orderCouriers' => Inertia::optional(fn () => $this->orderCouriers($branchIds)),
            'merchants' => Inertia::optional(fn () => $this->merchants($branchIds, $branchLookup)),
            'couriers' => Inertia::optional(fn () => $this->couriers($branchIds, $branchLookup)),
            'courierLocations' => Inertia::optional(fn () => $this->courierLocations($branchIds)),
            'summary' => [
                'branches' => $branchPayload->count(),
                'activeBranches' => $branchPayload->where('is_active', true)->count(),
                'orders' => (int) $branchPayload->sum('orders.total'),
                'activeOrders' => (int) $branchPayload->sum('orders.active'),
                'deliveredOrders' => (int) $branchPayload->sum('orders.delivered'),
                'todayOrders' => (int) $branchPayload->sum('orders.today'),
                'merchants' => (int) $branchPayload->sum('people.merchants'),
                'couriers' => (int) $branchPayload->sum('people.couriers'),
                'onlineCouriers' => (int) $branchPayload->sum('people.online_couriers'),
            ],
        ]);
    }

    /**
     * Correct an order from the operational view, only after resolving the
     * order through the signed-in account's explicit branch memberships.
     */
    public function statusOrder(Request $request, Order $order)
    {
        $actor = $this->operator($request, 'orders');
        $data = $request->validate([
            'status' => ['required', Rule::in(Order::STATUSES)],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $order = $this->scopedOrderFor($order, $this->allowedBranchIds($actor));

        app(OrderWorkflowService::class)->changeStatus(
            $order,
            $data['status'],
            $actor,
            $data['note'] ?? null,
        );

        return back()->with('success', __('orders.status_changed'));
    }

    /**
     * A branch can recover an overdue pickup in the same audited way as the
     * main operations desk. The recovery service releases the old budget hold
     * before making the order available again.
     */
    public function reofferOverduePickup(Request $request, Order $order)
    {
        $actor = $this->operator($request, 'orders');
        $data = $request->validate(['note' => ['nullable', 'string', 'max:255']]);
        $order = $this->scopedOrderFor($order, $this->allowedBranchIds($actor));

        app(OrderPickupRecoveryService::class)->reoffer($order, $actor, $data['note'] ?? null);

        return back()->with('success', 'تمت إعادة طرح الطلب، وأُعيدت الميزانية المحجوزة للمندوب السابق.');
    }

    /**
     * Assign only a courier from the same authorised operating branches. A
     * branch operator can therefore never use this endpoint to expose or
     * attach a courier belonging to a different branch.
     */
    public function assignCourier(Request $request, Order $order)
    {
        $actor = $this->operator($request, 'orders');
        $data = $request->validate([
            'courier_id' => ['required', 'integer', 'exists:users,id'],
            'assignment_role' => ['nullable', Rule::in(OrderOperationalAssignmentService::ASSIGNMENT_ROLES)],
        ]);
        $branchIds = $this->allowedBranchIds($actor);
        $order = $this->scopedOrderFor($order, $branchIds);
        $courier = User::query()
            ->whereKey($data['courier_id'])
            ->whereIn('branch_id', $branchIds)
            ->whereIn('role', User::DIRECT_ORDER_COURIER_ROLES)
            ->where('status', 'active')
            ->firstOrFail();

        app(OrderOperationalAssignmentService::class)->assign(
            $order,
            $courier,
            $actor,
            $data['assignment_role'] ?? null,
            'تم تعيين المندوب من لوحة الفرع.',
        );

        return back()->with('success', __('orders.courier_assigned'));
    }

    /**
     * Update a merchant or courier profile inside this branch portal.
     * Roles, branch ownership, and verification data intentionally remain
     * outside this form so a local edit cannot escalate an account.
     */
    public function updateUser(Request $request, User $user)
    {
        $permission = $user->role === 'merchant' ? 'merchants' : 'couriers';
        $actor = $this->operator($request, $permission);
        $user = $this->scopedOperationalUser($user, $this->allowedBranchIds($actor));

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'username' => ['required', 'string', 'max:60', Rule::unique('users', 'username')->ignore($user->id)],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => ['required', 'string', 'max:30', Rule::unique('users', 'phone')->ignore($user->id)],
            'shop_name' => ['nullable', 'string', 'max:120'],
            'address' => ['nullable', 'string', 'max:255'],
            'vehicle' => ['nullable', Rule::in(['bike', 'sedan', 'suv', 'truck'])],
        ]);

        $user->update([
            'name' => $data['name'],
            'username' => $data['username'],
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'],
            'shop_name' => $user->role === 'merchant' ? ($data['shop_name'] ?? null) : null,
            'address' => $data['address'] ?? null,
            'vehicle' => $user->isCourierRole() ? ($data['vehicle'] ?? null) : null,
        ]);

        $this->recordUserAction($request, $user, 'branch.user.profile_updated', [
            'fields' => ['name', 'username', 'email', 'phone', 'shop_name', 'address', 'vehicle'],
        ]);

        return back()->with('success', __('Account data updated successfully.'));
    }

    public function statusUser(Request $request, User $user)
    {
        $permission = $user->role === 'merchant' ? 'merchants' : 'couriers';
        $actor = $this->operator($request, $permission);
        $data = $request->validate(['status' => ['required', Rule::in(['active', 'suspended', 'pending', 'rejected'])]]);
        $user = $this->scopedOperationalUser($user, $this->allowedBranchIds($actor));

        $user->update([
            'status' => $data['status'],
            'is_online' => $data['status'] === 'active' ? $user->is_online : false,
        ]);

        Notification::create([
            'tenant_id' => $user->tenant_id,
            'user_id' => $user->id,
            'type' => 'account',
            'title_ar' => __('notifications.account_status'),
            'title_en' => 'Account status',
            'body_ar' => __('notifications.account_status_body'),
            'body_en' => 'Your account status was updated by the branch.',
        ]);
        $this->recordUserAction($request, $user, 'branch.user.status', ['status' => $data['status']]);

        return back()->with('success', __('admin.user_updated'));
    }

    /**
     * Public verification is merchant-only. Document approval itself is
     * available for both operational roles, but a courier never receives the
     * public merchant trust mark.
     */
    public function merchantVerification(Request $request, User $user)
    {
        $actor = $this->operator($request, 'merchants');
        $user = $this->scopedOperationalUser($user, $this->allowedBranchIds($actor));
        abort_unless($user->role === 'merchant', 404);
        $data = $request->validate(['verified' => ['required', 'boolean']]);

        if ($data['verified']) {
            $documents = $user->documents()->get(['id', 'type', 'status']);
            $requiredTypes = ['id_front', 'id_back', 'residence', 'residence_back'];
            if ($documents->isEmpty() || collect($requiredTypes)->diff($documents->pluck('type'))->isNotEmpty()) {
                return back()->withErrors(['verification' => 'يجب أن يرفع التاجر صور الهوية والسكن المطلوبة كاملة قبل منح علامة التوثيق.']);
            }
            if ($documents->contains(fn (Document $document) => $document->status !== 'approved')) {
                return back()->withErrors(['verification' => 'اعتمد جميع وثائق التاجر أو ارفضها قبل منح علامة التوثيق.']);
            }
        }

        $user->update([
            'merchant_verified_at' => $data['verified'] ? now() : null,
            'merchant_verified_by' => $data['verified'] ? $actor->id : null,
        ]);
        $this->recordUserAction($request, $user, $data['verified'] ? 'branch.merchant.verification_granted' : 'branch.merchant.verification_removed', [
            'verified' => (bool) $data['verified'],
        ]);

        return back()->with('success', $data['verified'] ? 'تم منح علامة توثيق التاجر.' : 'تمت إزالة علامة توثيق التاجر.');
    }

    public function reviewDocument(Request $request, User $user, Document $document)
    {
        $permission = $user->role === 'merchant' ? 'merchants' : 'couriers';
        $actor = $this->operator($request, $permission);
        $user = $this->scopedOperationalUser($user, $this->allowedBranchIds($actor));
        abort_unless((int) $document->user_id === (int) $user->id, 404);
        $data = $request->validate(['status' => ['required', Rule::in(['approved', 'rejected'])]]);

        $document->update(['status' => $data['status']]);
        if ($user->role === 'merchant' && $data['status'] === 'rejected' && $user->merchant_verified_at) {
            $user->update(['merchant_verified_at' => null, 'merchant_verified_by' => null]);
        }
        $this->recordUserAction($request, $user, 'branch.user.document_reviewed', [
            'document_id' => $document->id,
            'status' => $data['status'],
        ]);

        return back()->with('success', __('admin.document_reviewed'));
    }

    public function showDocument(Request $request, User $user, Document $document)
    {
        $permission = $user->role === 'merchant' ? 'merchants' : 'couriers';
        $actor = $this->operator($request, $permission);
        $user = $this->scopedOperationalUser($user, $this->allowedBranchIds($actor));
        abort_unless((int) $document->user_id === (int) $user->id, 404);
        abort_unless(Storage::disk('public')->exists($document->path), 404);

        return Storage::disk('public')->response($document->path, $document->type.'.'.pathinfo($document->path, PATHINFO_EXTENSION));
    }

    /**
     * Keep account deletion recoverable. We refuse to delete a person that
     * is still attached to an open delivery so the operational record never
     * loses its actor half way through a job.
     */
    public function destroyUser(Request $request, User $user)
    {
        $permission = $user->role === 'merchant' ? 'merchants' : 'couriers';
        $actor = $this->operator($request, $permission);
        $user = $this->scopedOperationalUser($user, $this->allowedBranchIds($actor));
        abort_if($user->is($actor), 422, 'لا يمكن حذف الحساب الذي تستخدمه حالياً.');

        $hasOpenOrders = Order::withoutGlobalScope(TenantScope::class)
            ->whereNotIn('status', Order::TERMINAL_STATUSES)
            ->where(function (Builder $orders) use ($user): void {
                if ($user->role === 'merchant') {
                    $orders->where('merchant_id', $user->id)->orWhere('created_by', $user->id);
                    return;
                }

                $orders->where('courier_id', $user->id)
                    ->orWhere('pickup_courier_id', $user->id)
                    ->orWhere('delivery_courier_id', $user->id);
            })
            ->exists();
        if ($hasOpenOrders) {
            return back()->withErrors(['delete' => 'لا يمكن حذف حساب مرتبط بطلبات مفتوحة. عطّل الحساب وأعد تعيين الطلبات أولاً.']);
        }

        $snapshot = ['role' => $user->role, 'name' => $user->name];
        $user->forceFill(['status' => 'suspended', 'is_online' => false])->save();
        $user->tokens()->delete();
        $user->delete();
        $this->recordUserAction($request, $user, 'branch.user.soft_deleted', $snapshot);

        return back()->with('success', 'تم حذف الحساب بأمان. يمكن استعادته من النسخة الاحتياطية عند الحاجة.');
    }

    private function operator(Request $request, string $permission): User
    {
        $user = $request->user();
        abort_unless(
            $user instanceof User && in_array($user->role, ['owner', 'branch_manager'], true),
            403,
        );
        abort_unless($user->canUseDashboardPermission($permission), 403);

        return $user;
    }

    /** @return array<int, int> */
    private function allowedBranchIds(User $user): array
    {
        return $this->allowedBranches($user)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    /**
     * Resolve an individual record through the same SQL boundary used for the
     * portal response. A primary-key route parameter alone is never trusted.
     *
     * @param array<int, int> $branchIds
     */
    private function scopedOrderFor(Order $order, array $branchIds): Order
    {
        abort_unless($branchIds !== [], 403);

        return $this->scopedOrders($branchIds)
            ->whereKey($order->id)
            ->firstOrFail();
    }

    /**
     * @param array<int, int> $branchIds
     */
    private function scopedOperationalUser(User $user, array $branchIds): User
    {
        abort_unless($branchIds !== [], 403);

        return User::query()
            ->whereKey($user->id)
            ->whereIn('branch_id', $branchIds)
            ->where(function (Builder $people): void {
                $people->where('role', 'merchant')->orWhereIn('role', User::COURIER_ROLES);
            })
            ->firstOrFail();
    }

    /** @param array<string, mixed> $data */
    private function recordUserAction(Request $request, User $user, string $action, array $data): void
    {
        ActivityLog::create([
            'tenant_id' => $user->tenant_id,
            'user_id' => $request->user()->id,
            'action' => $action,
            'subject_type' => User::class,
            'subject_id' => $user->id,
            'data' => $data,
            'ip' => $request->ip(),
        ]);
    }

    /**
     * @return Collection<int, Branch>
     */
    private function allowedBranches(User $user): Collection
    {
        $requiredAccessRole = $user->role === 'owner'
            ? BranchMembership::OWNER
            : BranchMembership::MANAGER;
        $platformTenantId = Tenant::platform()->id;

        return Branch::withoutGlobalScope(TenantScope::class)
            ->where('branches.tenant_id', $platformTenantId)
            ->where('branches.is_platform_managed', true)
            // Disabling a branch must revoke its portal boundary as well as
            // hide it from operational lists. Otherwise a previous manager
            // could keep working through a bookmarked dashboard URL.
            ->where('branches.is_active', true)
            ->where(function (Builder $eligible) use ($user, $requiredAccessRole): void {
                $eligible->whereHas('memberships', function (Builder $memberships) use ($user, $requiredAccessRole): void {
                    $memberships
                        ->where('user_id', $user->id)
                        ->where('access_role', $requiredAccessRole);
                });
            })
            ->with([
                'province:id,name_ar,name_en,name_ku',
                'memberships' => fn ($memberships) => $memberships
                    ->where('user_id', $user->id)
                    ->where('access_role', $requiredAccessRole),
            ])
            ->orderBy('name_ar')
            ->orderBy('id')
            ->get();
    }

    /**
     * Build metrics with a SQL union so a cross-branch route contributes once
     * to each permitted endpoint but never leaks an unauthorised endpoint.
     *
     * @param array<int, int> $branchIds
     * @return Collection<int, object>
     */
    private function branchMetrics(array $branchIds): Collection
    {
        if ($branchIds === []) {
            return collect();
        }

        $origin = DB::table('orders')
            ->whereNull('deleted_at')
            ->whereIn('origin_branch_id', $branchIds)
            ->selectRaw('origin_branch_id as branch_id, status, date');

        $destination = DB::table('orders')
            ->whereNull('deleted_at')
            ->whereIn('destination_branch_id', $branchIds)
            // A route that begins and ends at one branch should contribute
            // once, while a genuine cross-branch route belongs to both.
            ->where(function ($orders): void {
                $orders
                    ->whereNull('origin_branch_id')
                    ->orWhereColumn('origin_branch_id', '!=', 'destination_branch_id');
            })
            ->selectRaw('destination_branch_id as branch_id, status, date');

        $legacy = DB::table('orders')
            ->whereNull('deleted_at')
            ->whereIn('branch_id', $branchIds)
            // Older orders only have `branch_id`. Do not double-count it if
            // the modern route fields already point at the same branch.
            ->where(function ($orders): void {
                $orders
                    ->whereNull('origin_branch_id')
                    ->orWhereColumn('origin_branch_id', '!=', 'branch_id');
            })
            ->where(function ($orders): void {
                $orders
                    ->whereNull('destination_branch_id')
                    ->orWhereColumn('destination_branch_id', '!=', 'branch_id');
            })
            ->selectRaw('branch_id as branch_id, status, date');

        $terminalPlaceholders = implode(', ', array_fill(0, count(Order::TERMINAL_STATUSES), '?'));
        $links = $origin->unionAll($destination)->unionAll($legacy);

        return DB::query()
            ->fromSub($links, 'branch_order_links')
            ->select('branch_id')
            ->selectRaw('COUNT(*) as total_orders')
            ->selectRaw(
                "SUM(CASE WHEN status NOT IN ({$terminalPlaceholders}) THEN 1 ELSE 0 END) as active_orders",
                Order::TERMINAL_STATUSES,
            )
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as delivered_orders', ['delivered'])
            ->selectRaw('SUM(CASE WHEN date = ? THEN 1 ELSE 0 END) as today_orders', [today()->toDateString()])
            ->groupBy('branch_id')
            ->get()
            ->keyBy(fn (object $row) => (int) $row->branch_id);
    }

    /**
     * Counts people from their operational branch assignment rather than from
     * the tenant that happens to own an order.  A merchant and a courier have
     * independent tenants, so using tenant ids here would leak or omit people
     * in a shared operations network.
     *
     * @param array<int, int> $branchIds
     * @return Collection<int, array{merchants:int,couriers:int,online_couriers:int}>
     */
    private function branchPeopleMetrics(array $branchIds): Collection
    {
        if ($branchIds === []) {
            return collect();
        }

        $courierRolePlaceholders = implode(', ', array_fill(0, count(User::COURIER_ROLES), '?'));
        $counts = User::query()
            ->whereIn('branch_id', $branchIds)
            ->select('branch_id')
            ->selectRaw('SUM(CASE WHEN role = ? THEN 1 ELSE 0 END) as merchants', ['merchant'])
            ->selectRaw(
                "SUM(CASE WHEN role IN ({$courierRolePlaceholders}) THEN 1 ELSE 0 END) as couriers",
                User::COURIER_ROLES,
            )
            ->selectRaw(
                "SUM(CASE WHEN role IN ({$courierRolePlaceholders}) AND status = ? AND is_online = ? THEN 1 ELSE 0 END) as online_couriers",
                [...User::COURIER_ROLES, 'active', true],
            )
            ->groupBy('branch_id')
            ->get()
            ->keyBy(fn (User $user) => (int) $user->branch_id);

        return collect($branchIds)->mapWithKeys(fn (int $branchId) => [
            $branchId => [
                'merchants' => (int) ($counts->get($branchId)?->merchants ?? 0),
                'couriers' => (int) ($counts->get($branchId)?->couriers ?? 0),
                'online_couriers' => (int) ($counts->get($branchId)?->online_couriers ?? 0),
            ],
        ]);
    }

    /**
     * @param array<int, int> $branchIds
     * @return Collection<int, array<string, mixed>>
     */
    private function recentOrders(array $branchIds): Collection
    {
        if ($branchIds === []) {
            return collect();
        }

        return $this->scopedOrders($branchIds)
            ->with([
                'originBranch:id,code,name_ar,name_en,name_ku',
                'destinationBranch:id,code,name_ar,name_en,name_ku',
                'branch:id,code,name_ar,name_en,name_ku',
            ])
            ->latest('id')
            ->limit(30)
            ->get([
                'id', 'track_no', 'customer_name_ar', 'customer_name_en', 'status', 'workflow_stage', 'price', 'date',
                'origin_branch_id', 'destination_branch_id', 'branch_id',
            ])
            ->map(function (Order $order) use ($branchIds): array {
                $visibleBranches = collect([
                    $order->originBranch,
                    $order->destinationBranch,
                    $order->branch,
                ])
                    ->filter(fn (?Branch $branch) => $branch && in_array((int) $branch->id, $branchIds, true))
                    ->unique('id')
                    ->map(fn (Branch $branch) => [
                        'id' => $branch->id,
                        'code' => $branch->code,
                        'name' => $this->branchName($branch),
                    ])
                    ->values();

                return [
                    'id' => $order->id,
                    'track_no' => $order->track_no,
                    'customer_name' => $order->customer_name_ar ?: $order->customer_name_en,
                    'status' => $order->status,
                    'workflow_stage' => $order->workflow_stage,
                    'price' => (int) $order->price,
                    'date' => $order->date?->toDateString(),
                    'branches' => $visibleBranches,
                ];
            })
            ->values();
    }

    /**
     * The operating list intentionally limits itself to the 100 newest
     * orders. This keeps the first branch dashboard load responsive while all
     * data is still scoped in SQL before it is serialised to the browser.
     *
     * @param array<int, int> $branchIds
     * @return Collection<int, array<string, mixed>>
     */
    private function orders(array $branchIds): Collection
    {
        if ($branchIds === []) {
            return collect();
        }

        return $this->scopedOrders($branchIds)
            ->with([
                'merchant:id,name,shop_name,phone,address,status,branch_id',
                'courier:id,name,phone,vehicle,status,branch_id,is_online',
                'pickupCourier:id,name,phone,vehicle,status,branch_id,is_online',
                'deliveryCourier:id,name,phone,vehicle,status,branch_id,is_online',
                'originBranch:id,code,name_ar,name_en,name_ku,city',
                'destinationBranch:id,code,name_ar,name_en,name_ku,city',
                'branch:id,code,name_ar,name_en,name_ku,city',
            ])
            ->latest('id')
            ->limit(100)
            ->get([
                'id', 'track_no', 'status', 'customer_name_ar', 'customer_name_en', 'phone', 'address_ar', 'address_en',
                'notes', 'vehicle_note', 'price', 'fee', 'pickup_deadline_at', 'created_at', 'updated_at',
                'origin_branch_id', 'destination_branch_id', 'branch_id', 'merchant_id', 'courier_id',
                'pickup_courier_id', 'delivery_courier_id',
            ])
            ->map(function (Order $order) use ($branchIds): array {
                $visibleBranches = $this->visibleBranchesForOrder($order, $branchIds);
                $courier = $order->courier ?: $order->pickupCourier ?: $order->deliveryCourier;

                return [
                    'id' => $order->id,
                    'track_no' => $order->track_no,
                    'status' => $order->status,
                    'customer_name' => $order->customer_name_ar ?: $order->customer_name_en,
                    'phone' => $order->phone,
                    'address' => $order->address_ar ?: $order->address_en,
                    'notes' => $order->notes,
                    'vehicle_note' => $order->vehicle_note,
                    'price' => (int) $order->price,
                    'fee' => $order->fee === null ? null : (int) $order->fee,
                    'pickup_deadline_at' => $order->pickup_deadline_at?->toIso8601String(),
                    'created_at' => $order->created_at?->toIso8601String(),
                    'updated_at' => $order->updated_at?->toIso8601String(),
                    'branch_ids' => $visibleBranches->pluck('id')->map(fn ($id) => (int) $id)->values(),
                    'branches' => $visibleBranches,
                    'merchant' => $this->visibleRelatedUser($order->merchant, $branchIds, false),
                    'courier' => $this->visibleRelatedUser($courier, $branchIds, true),
                ];
            })
            ->values();
    }

    /**
     * The order assignment form only needs a small, active courier directory.
     * Keep it separate from the courier-management tab, which includes
     * documents and location metadata for review.
     *
     * @param array<int, int> $branchIds
     * @return Collection<int, array{id:int,branch_id:int,name:string,role:string,status:string}>
     */
    private function orderCouriers(array $branchIds): Collection
    {
        if ($branchIds === []) {
            return collect();
        }

        return User::query()
            ->whereIn('branch_id', $branchIds)
            ->whereIn('role', User::DIRECT_ORDER_COURIER_ROLES)
            ->where('status', 'active')
            ->orderBy('name')
            ->limit(200)
            ->get(['id', 'branch_id', 'name', 'role', 'status'])
            ->map(fn (User $courier) => [
                'id' => $courier->id,
                'branch_id' => (int) $courier->branch_id,
                'name' => $courier->name,
                'role' => $courier->role,
                'status' => $courier->status,
            ])
            ->values();
    }

    /**
     * @param array<int, int> $branchIds
     * @return Collection<int, array<string, mixed>>
     */
    private function merchants(array $branchIds, Collection $branchLookup): Collection
    {
        if ($branchIds === []) {
            return collect();
        }

        return User::query()
            ->whereIn('branch_id', $branchIds)
            ->where('role', 'merchant')
            ->with([
                'documents' => fn ($documents) => $documents->latest('id'),
                'merchantVerifiedBy:id,name',
            ])
            ->orderBy('name')
            ->limit(200)
            ->get([
                'id', 'tenant_id', 'branch_id', 'name', 'username', 'email', 'phone', 'role', 'shop_name', 'address', 'identity_number',
                'status', 'is_online', 'merchant_verified_at', 'merchant_verified_by', 'created_at',
            ])
            ->map(fn (User $merchant) => $this->merchantPayload($merchant, $branchLookup))
            ->values();
    }

    /**
     * @param array<int, int> $branchIds
     * @return Collection<int, array<string, mixed>>
     */
    private function couriers(array $branchIds, Collection $branchLookup): Collection
    {
        if ($branchIds === []) {
            return collect();
        }

        return User::query()
            ->whereIn('branch_id', $branchIds)
            ->whereIn('role', User::COURIER_ROLES)
            ->with(['documents' => fn ($documents) => $documents->latest('id')])
            ->orderBy('name')
            ->limit(200)
            ->get([
                'id', 'tenant_id', 'branch_id', 'name', 'username', 'email', 'phone', 'role', 'vehicle', 'address', 'identity_number',
                'status', 'is_online', 'last_active_at', 'current_latitude', 'current_longitude', 'location_accuracy_meters', 'location_updated_at', 'created_at',
            ])
            ->map(fn (User $courier) => $this->courierPayload($courier, $branchLookup))
            ->values();
    }

    /**
     * The map needs neither identity documents nor the full courier profile.
     * This compact payload is fetched only when the locations tab is opened.
     *
     * @param array<int, int> $branchIds
     * @return Collection<int, array<string, mixed>>
     */
    private function courierLocations(array $branchIds): Collection
    {
        if ($branchIds === []) {
            return collect();
        }

        return User::query()
            ->whereIn('branch_id', $branchIds)
            ->whereIn('role', User::COURIER_ROLES)
            ->orderBy('name')
            ->limit(200)
            ->get([
                'id', 'branch_id', 'name', 'phone', 'role', 'status', 'is_online', 'address',
                'current_latitude', 'current_longitude', 'location_accuracy_meters', 'location_updated_at',
            ])
            ->map(fn (User $courier) => [
                'id' => $courier->id,
                'branch_id' => (int) $courier->branch_id,
                'name' => $courier->name,
                'phone' => $courier->phone,
                'role' => $courier->role,
                'status' => $courier->status,
                'is_online' => (bool) $courier->is_online,
                'location' => $this->freshCourierLocationPayload($courier),
            ])
            ->values();
    }

    /**
     * Central branch boundary for every order the portal serialises.  Keep the
     * `OR` group in SQL; filtering only after loading the collection would let
     * a branch account receive another branch's records in the response.
     *
     * @param array<int, int> $branchIds
     * @return Builder<Order>
     */
    private function scopedOrders(array $branchIds): Builder
    {
        return Order::withoutGlobalScope(TenantScope::class)
            ->where(function (Builder $orders) use ($branchIds): void {
                $orders
                    ->whereIn('origin_branch_id', $branchIds)
                    ->orWhereIn('destination_branch_id', $branchIds)
                    ->orWhereIn('branch_id', $branchIds);
            });
    }

    /**
     * @param array<int, int> $branchIds
     * @return Collection<int, array{id:int,code:string,name:string}>
     */
    private function visibleBranchesForOrder(Order $order, array $branchIds): Collection
    {
        return collect([
            $order->originBranch,
            $order->destinationBranch,
            $order->branch,
        ])
            ->filter(fn (?Branch $branch) => $branch && in_array((int) $branch->id, $branchIds, true))
            ->unique('id')
            ->map(fn (Branch $branch) => [
                'id' => $branch->id,
                'code' => $branch->code,
                'name' => $this->branchName($branch),
            ])
            ->values();
    }

    /**
     * An order can connect two branches.  Do not serialise a merchant or
     * courier profile merely because it belongs to that order: the profile
     * itself must belong to one of the portal account's authorised branches.
     * This stops a cross-branch order from revealing contact details for the
     * other branch's staff.
     *
     * @param array<int, int> $branchIds
     * @return array<string, mixed>|null
     */
    private function visibleRelatedUser(?User $user, array $branchIds, bool $isCourier): ?array
    {
        if (! $user || ! in_array((int) $user->branch_id, $branchIds, true)) {
            return null;
        }

        return $isCourier
            ? [
                'id' => $user->id,
                'name' => $user->name,
                'phone' => $user->phone,
                'vehicle' => $user->vehicle,
                'status' => $user->status,
                'is_online' => (bool) $user->is_online,
            ]
            : [
                'id' => $user->id,
                'name' => $user->name,
                'shop_name' => $user->shop_name,
                'phone' => $user->phone,
                'status' => $user->status,
            ];
    }

    /**
     * @param Collection<int, Branch> $branchLookup
     * @return array<string, mixed>
     */
    private function merchantPayload(User $merchant, Collection $branchLookup): array
    {
        $documents = $merchant->documents;

        return [
            'id' => $merchant->id,
            'branch_id' => (int) $merchant->branch_id,
            'name' => $merchant->name,
            'username' => $merchant->username,
            'email' => $merchant->email,
            'shop_name' => $merchant->shop_name,
            'phone' => $merchant->phone,
            'address' => $merchant->address,
            'identity_number' => $merchant->identity_number,
            'status' => $merchant->status,
            'is_online' => (bool) $merchant->is_online,
            'created_at' => $merchant->created_at?->toIso8601String(),
            'branch' => $this->branchPayload($branchLookup->get((int) $merchant->branch_id)),
            'verification' => [
                'status' => $merchant->isMerchantVerified()
                    ? 'verified'
                    : ($documents->contains('status', 'rejected') ? 'rejected' : ($documents->isNotEmpty() ? 'pending' : 'unsubmitted')),
                'verified' => $merchant->isMerchantVerified(),
                'verified_at' => $merchant->merchant_verified_at?->toIso8601String(),
                'verified_by' => $merchant->merchantVerifiedBy?->name,
            ],
            'documents' => $this->documentsPayload($merchant),
        ];
    }

    /**
     * @param Collection<int, Branch> $branchLookup
     * @return array<string, mixed>
     */
    private function courierPayload(User $courier, Collection $branchLookup): array
    {
        return [
            'id' => $courier->id,
            'branch_id' => (int) $courier->branch_id,
            'name' => $courier->name,
            'username' => $courier->username,
            'email' => $courier->email,
            'phone' => $courier->phone,
            'role' => $courier->role,
            'vehicle' => $courier->vehicle,
            'address' => $courier->address,
            'identity_number' => $courier->identity_number,
            'status' => $courier->status,
            'is_online' => (bool) $courier->is_online,
            'last_active_at' => $courier->last_active_at?->toIso8601String(),
            'created_at' => $courier->created_at?->toIso8601String(),
            'branch' => $this->branchPayload($branchLookup->get((int) $courier->branch_id)),
            // A branch portal receives an operational pin only for an
            // assigned courier with a recently consented location. It never
            // receives a coordinate history or an old point presented as live.
            'location' => $this->freshCourierLocationPayload($courier),
            // Courier documents are reviewed internally. They never grant
            // the public merchant verification mark.
            'documents' => $this->documentsPayload($courier),
        ];
    }

    /**
     * The branch-scoped courier list already applies the membership-derived
     * `branch_id` boundary in `couriers()`. This helper only decides whether
     * the current, last-known point is recent enough to expose for that
     * already-authorised courier.
     *
     * @return array{latitude:float,longitude:float,accuracy_meters:int|null,updated_at:string,address_label:string|null}|null
     */
    private function freshCourierLocationPayload(User $courier): ?array
    {
        if (
            $courier->current_latitude === null
            || $courier->current_longitude === null
            || $courier->location_updated_at === null
            || $courier->location_updated_at->lessThan(
                now()->subMinutes(CourierLocationService::OPERATIONAL_FRESHNESS_MINUTES),
            )
        ) {
            return null;
        }

        $addressLabel = trim((string) $courier->address);

        return [
            'latitude' => (float) $courier->current_latitude,
            'longitude' => (float) $courier->current_longitude,
            'accuracy_meters' => $courier->location_accuracy_meters === null
                ? null
                : (int) $courier->location_accuracy_meters,
            'updated_at' => $courier->location_updated_at->toIso8601String(),
            'address_label' => $addressLabel === '' ? null : $addressLabel,
        ];
    }

    /** @return Collection<int, array<string, mixed>> */
    private function documentsPayload(User $user): Collection
    {
        return $user->documents
            ->map(fn (Document $document) => [
                'id' => $document->id,
                'type' => $document->type,
                'status' => $document->status,
                'url' => route('admin.branch.users.documents.show', [$user->id, $document->id]),
            ])
            ->values();
    }

    private function branchPayload(?Branch $branch): ?array
    {
        return $branch ? [
            'id' => $branch->id,
            'code' => $branch->code,
            'name' => $this->branchName($branch),
            'city' => $branch->city,
        ] : null;
    }

    private function branchName(Branch $branch): string
    {
        return $branch->name_ar ?: $branch->name_en ?: $branch->name_ku ?: $branch->code ?: __('Unnamed branch');
    }
}
