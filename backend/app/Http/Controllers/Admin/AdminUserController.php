<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Document;
use App\Models\Notification;
use App\Models\Order;
use App\Models\Scopes\TenantScope;
use App\Models\User;
use App\Services\BranchDashboardContext;
use App\Services\BranchDashboardScope;
use App\Services\DashboardBranchFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class AdminUserController extends Controller
{
    /**
     * Roster rows include account, document-review, wallet, and operational
     * summaries. Keep that useful review payload bounded instead of loading
     * every account (and every document) into one dashboard response.
     */
    private const ROSTER_PER_PAGE = 24;

    /**
     * Every courier completes these five documents during registration.
     * Keeping the requirement here makes dashboard review use the same
     * standard as registration, including for older accounts created before
     * the current registration form existed.
     */
    private const REQUIRED_COURIER_DOCUMENT_TYPES = [
        'residence',
        'id_front',
        'id_back',
        'license_front',
        'license_back',
    ];

    /** @var array<int, string> */
    private const REQUIRED_MERCHANT_DOCUMENT_TYPES = [
        'id_front',
        'id_back',
        'residence',
        'residence_back',
    ];

    public function merchants(Request $request)
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in(['all', 'active', 'pending', 'suspended'])],
        ]);
        $search = trim((string) ($data['search'] ?? ''));
        $status = $data['status'] ?? 'all';

        // Merchants are operational accounts, not merely tenant records.  A
        // tenant can legitimately have more than one merchant operator, so
        // the dashboard must list and manage the actual accounts directly.
        $dashboardScope = app(BranchDashboardContext::class)->fromRequest($request);
        $scope = $this->branchScope($request, $dashboardScope);
        $branchFilter = app(DashboardBranchFilter::class);
        $selectedBranchId = $branchFilter->selectedBranchId(
            $request,
            $dashboardScope,
        );
        $base = User::query()->where('role', 'merchant');
        $this->restrictRosterForDashboard($base, $scope, $selectedBranchId, $branchFilter);
        $filters = $this->statusFilters($base);

        $query = clone $base;
        $this->applyRosterSearch($query, $search);
        if ($status !== 'all') {
            $query->where('status', $status);
        }

        $user = $request->user();
        $canViewDocuments = $user->canUseAdminPermission('merchants', 'documents_view');
        $canReviewDocuments = $user->canUseAdminPermission('merchants', 'documents_review');
        $canAccessDocumentRecords = $canViewDocuments || $canReviewDocuments;
        $canViewFinanceBalances = $user->canUseAdminPermission('finance', 'view_balances');
        $relations = [
            'tenant.plan',
            'provinces',
            'merchantVerifiedBy:id,name',
        ];
        if ($canAccessDocumentRecords) {
            $relations['documents'] = fn ($query) => $query->latest('id');
        }
        if ($canViewFinanceBalances) {
            $relations[] = 'wallet';
        }

        $paginator = $query
            ->with($relations)
            ->orderBy('name')
            ->paginate(self::ROSTER_PER_PAGE)
            ->withQueryString();

        $users = $paginator->getCollection();
        $statsByUser = $this->merchantStatsByUser(
            $users,
            $canViewFinanceBalances,
            $scope,
            $selectedBranchId,
            $branchFilter,
        );
        $rows = $users->map(fn (User $merchant) => $this->merchantRow(
            $merchant,
            $statsByUser[$merchant->id],
            $canViewDocuments,
            $canAccessDocumentRecords,
            $canViewFinanceBalances,
        ));

        return Inertia::render('Admin/Roster', [
            'role' => 'merchant',
            'rows' => $rows->values(),
            'filters' => $filters,
            'query' => [
                'search' => $search,
                'status' => $status,
            ],
            'pagination' => $this->paginationMeta($paginator),
            'branchFilter' => $branchFilter->payload(
                $request,
                $dashboardScope,
            ),
            // The route middleware is the authorization source of truth. These
            // flags only keep the client from offering buttons that would
            // inevitably respond with a 403 for a restricted employee.
            'canUpdateUsers' => $user->canUseAdminPermission('merchants', 'edit')
                || $user->canUseAdminPermission('merchants', 'change_status')
                || $user->canUseAdminPermission('merchants', 'verify')
                || $canReviewDocuments,
            'canEditUsers' => $user->canUseAdminPermission('merchants', 'edit'),
            'canChangeUserStatus' => $user->canUseAdminPermission('merchants', 'change_status'),
            'canVerifyUsers' => $user->canUseAdminPermission('merchants', 'verify'),
            'canViewDocuments' => $canViewDocuments,
            'canReviewDocuments' => $canReviewDocuments,
            'canViewDocumentRecords' => $canAccessDocumentRecords,
            'canDeleteUsers' => $user->canUseAdminPermission('merchants', 'delete'),
            'canViewFinanceBalances' => $canViewFinanceBalances,
            'canViewLoyalty' => false,
            'canUpdateCourierDeduction' => false,
        ]);
    }

    public function couriers(Request $request)
    {
        $data = $request->validate([
            // The direct-order roster has one accountable courier from
            // collection through delivery. Legacy specialist accounts remain
            // preserved in history, but are not an operational directory.
            // Keep old PWA bookmarks valid during rollout. The value is
            // deliberately ignored below, so a stale specialist tab simply
            // converges to the one-courier operational roster.
            'role' => ['nullable', Rule::in(array_merge(['all'], User::COURIER_ROLES))],
            'search' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in(['all', 'active', 'pending', 'suspended'])],
        ]);
        $search = trim((string) ($data['search'] ?? ''));
        $status = $data['status'] ?? 'all';

        $dashboardScope = app(BranchDashboardContext::class)->fromRequest($request);
        $scope = $this->branchScope($request, $dashboardScope);
        $branchFilter = app(DashboardBranchFilter::class);
        $selectedBranchId = $branchFilter->selectedBranchId(
            $request,
            $dashboardScope,
        );
        $base = User::query()->where('role', 'courier');
        $this->restrictRosterForDashboard($base, $scope, $selectedBranchId, $branchFilter);
        $query = clone $base;

        $filters = $this->statusFilters($query);
        $this->applyRosterSearch($query, $search);
        if ($status !== 'all') {
            $query->where('status', $status);
        }

        $user = $request->user();
        $canViewDocuments = $user->canUseAdminPermission('couriers', 'documents_view');
        $canReviewDocuments = $user->canUseAdminPermission('couriers', 'documents_review');
        $canAccessDocumentRecords = $canViewDocuments || $canReviewDocuments;
        $canViewFinanceBalances = $user->canUseAdminPermission('finance', 'view_balances');
        $canViewLoyalty = $user->canUseAdminPermission('loyalty', 'view');
        $canUpdateCourierDeduction = $user->canUseAdminPermission('couriers', 'update_deduction');
        $relations = [
            'tenant.plan',
            'provinces',
            'courierVerifiedBy:id,name',
        ];
        if ($canAccessDocumentRecords) {
            $relations['documents'] = fn ($query) => $query->latest('id');
        }
        if ($canViewLoyalty) {
            $relations[] = 'loyaltyAccount:user_id,balance';
        }
        if ($canViewFinanceBalances) {
            $relations[] = 'wallet';
        }

        $paginator = $query
            ->with($relations)
            ->orderBy('name')
            ->paginate(self::ROSTER_PER_PAGE)
            ->withQueryString();

        $users = $paginator->getCollection();
        $statsByUser = $this->courierStatsByUser(
            $users,
            $canViewFinanceBalances,
            $scope,
            $selectedBranchId,
            $branchFilter,
        );
        $rows = $users->map(fn (User $courier) => $this->courierRow(
            $courier,
            $statsByUser[$courier->id],
            $canViewDocuments,
            $canAccessDocumentRecords,
            $canViewFinanceBalances,
            $canViewLoyalty,
            $canUpdateCourierDeduction,
        ));

        return Inertia::render('Admin/Roster', [
            'role' => 'courier',
            'rows' => $rows->values(),
            'filters' => $filters,
            'query' => [
                'search' => $search,
                'status' => $status,
            ],
            'pagination' => $this->paginationMeta($paginator),
            'branchFilter' => $branchFilter->payload(
                $request,
                $dashboardScope,
            ),
            'canUpdateUsers' => $user->canUseAdminPermission('couriers', 'edit')
                || $user->canUseAdminPermission('couriers', 'change_status')
                || $user->canUseAdminPermission('couriers', 'verify')
                || $canReviewDocuments,
            'canEditUsers' => $user->canUseAdminPermission('couriers', 'edit'),
            'canChangeUserStatus' => $user->canUseAdminPermission('couriers', 'change_status'),
            'canVerifyUsers' => $user->canUseAdminPermission('couriers', 'verify'),
            'canViewDocuments' => $canViewDocuments,
            'canReviewDocuments' => $canReviewDocuments,
            'canViewDocumentRecords' => $canAccessDocumentRecords,
            'canDeleteUsers' => $user->canUseAdminPermission('couriers', 'delete'),
            'canViewFinanceBalances' => $canViewFinanceBalances,
            'canViewLoyalty' => $canViewLoyalty,
            'canUpdateCourierDeduction' => $canUpdateCourierDeduction,
        ]);
    }

    /**
     * @return array<string, int>
     */
    private function statusFilters($query): array
    {
        $counts = (clone $query)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return [
            'all' => (int) $counts->sum(),
            'active' => (int) ($counts['active'] ?? 0),
            'pending' => (int) ($counts['pending'] ?? 0),
            'suspended' => (int) ($counts['suspended'] ?? 0),
        ];
    }

    /**
     * Search on the server so operators can find an account that is not on
     * the current page. Identity numbers stay intentionally out of this
     * query because that field is encrypted at rest.
     */
    private function applyRosterSearch($query, string $search): void
    {
        if ($search === '') {
            return;
        }

        $like = '%'.$search.'%';
        $query->where(function ($users) use ($like): void {
            $users
                ->where('name', 'like', $like)
                ->orWhere('phone', 'like', $like)
                ->orWhere('username', 'like', $like)
                ->orWhere('email', 'like', $like)
                ->orWhere('shop_name', 'like', $like)
                ->orWhere('address', 'like', $like)
                ->orWhereHas('provinces', function ($provinces) use ($like): void {
                    $provinces
                        ->where('name_ar', 'like', $like)
                        ->orWhere('name_en', 'like', $like)
                        ->orWhere('name_ku', 'like', $like);
                });
        });
    }

    /** @return array{currentPage:int,lastPage:int,perPage:int,from:int|null,to:int|null,total:int} */
    private function paginationMeta($paginator): array
    {
        return [
            'currentPage' => $paginator->currentPage(),
            'lastPage' => $paginator->lastPage(),
            'perPage' => $paginator->perPage(),
            'from' => $paginator->firstItem(),
            'to' => $paginator->lastItem(),
            'total' => $paginator->total(),
        ];
    }

    /**
     * Build every direct-courier roster total in one grouped query rather
     * than running five order queries for each row rendered on the page.
     *
     * @param  Collection<int, User>  $users
     * @return array<int, array<string, int>>
     */
    private function courierStatsByUser(
        $users,
        bool $includeCollectedAmount,
        ?BranchDashboardScope $scope,
        ?int $selectedBranchId,
        DashboardBranchFilter $branchFilter,
    ): array
    {
        $empty = [
            'assigned' => 0,
            'delivered' => 0,
            'returned' => 0,
            'in_progress' => 0,
        ];
        if ($includeCollectedAmount) {
            $empty['collected'] = 0;
        }
        $stats = $users->mapWithKeys(fn (User $user) => [$user->id => $empty])->all();
        $ids = $users->pluck('id')->all();
        if ($ids === []) {
            return $stats;
        }

        $totals = Order::withoutGlobalScope(TenantScope::class)
            ->whereIn('courier_id', $ids);
        $this->restrictOrdersForDashboard($totals, $scope, $selectedBranchId, $branchFilter);

        $totals = $totals
            ->selectRaw('courier_id as user_id')
            ->selectRaw('COUNT(*) as assigned')
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as delivered', ['delivered'])
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as returned', ['returned'])
            ->selectRaw('SUM(CASE WHEN status IN (?, ?) THEN 1 ELSE 0 END) as in_progress', ['approved', 'courier']);
        if ($includeCollectedAmount) {
            $totals->selectRaw('COALESCE(SUM(CASE WHEN status = ? THEN price ELSE 0 END), 0) as collected', ['delivered']);
        }
        $totals = $totals->groupBy('courier_id')->get();

        foreach ($totals as $total) {
            $row = [
                'assigned' => (int) $total->assigned,
                'delivered' => (int) $total->delivered,
                'returned' => (int) $total->returned,
                'in_progress' => (int) $total->in_progress,
            ];
            if ($includeCollectedAmount) {
                $row['collected'] = (int) $total->collected;
            }
            $stats[(int) $total->user_id] = $row;
        }

        return $stats;
    }

    /**
     * Build merchant order totals for the rendered page in two grouped
     * queries, not four queries per merchant. An order that contains the
     * same merchant in both modern `merchant_id` and historic `created_by`
     * fields is counted once, just like the old OR query.
     *
     * @param  Collection<int, User>  $users
     * @return array<int, array<string, int>>
     */
    private function merchantStatsByUser(
        $users,
        bool $includeCollectedAmount,
        ?BranchDashboardScope $scope,
        ?int $selectedBranchId,
        DashboardBranchFilter $branchFilter,
    ): array
    {
        $empty = [
            'orders' => 0,
            'delivered' => 0,
            'returned' => 0,
        ];
        if ($includeCollectedAmount) {
            $empty['collected'] = 0;
        }
        $stats = $users->mapWithKeys(fn (User $user) => [$user->id => $empty])->all();
        $ids = $users->pluck('id')->all();

        if ($ids === []) {
            return $stats;
        }

        foreach ([['merchant_id', false], ['created_by', true]] as [$column, $historicFallback]) {
            $orders = Order::withoutGlobalScope(TenantScope::class)
                ->whereIn($column, $ids);
            $this->restrictOrdersForDashboard($orders, $scope, $selectedBranchId, $branchFilter);

            if ($historicFallback) {
                $orders->where(function ($query): void {
                    $query
                        ->whereNull('merchant_id')
                        ->orWhereColumn('merchant_id', '!=', 'created_by');
                });
            }

            $totals = $orders
                ->selectRaw("{$column} as user_id")
                ->selectRaw('COUNT(*) as orders')
                ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as delivered', ['delivered'])
                ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as returned', ['returned']);
            if ($includeCollectedAmount) {
                $totals->selectRaw('COALESCE(SUM(CASE WHEN status = ? THEN price ELSE 0 END), 0) as collected', ['delivered']);
            }
            $totals = $totals->groupBy($column)->get();

            foreach ($totals as $total) {
                $id = (int) $total->user_id;
            $row = [
                'orders' => ($stats[$id]['orders'] ?? 0) + (int) $total->orders,
                'delivered' => ($stats[$id]['delivered'] ?? 0) + (int) $total->delivered,
                'returned' => ($stats[$id]['returned'] ?? 0) + (int) $total->returned,
            ];
            if ($includeCollectedAmount) {
                $row['collected'] = ($stats[$id]['collected'] ?? 0) + (int) $total->collected;
            }
            $stats[$id] = $row;
            }
        }

        return $stats;
    }

    /**
     * Administrators can correct operational account data without changing a
     * user's role or bypassing the separate activation/review workflow.
     */
    public function update(Request $request, User $user)
    {
        abort_unless($user->role === 'merchant' || $user->isCourierRole(), 404);
        $user = $this->scopedOperationalUser($request, $user);

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

        ActivityLog::create([
            'tenant_id' => $user->tenant_id,
            'user_id' => $request->user()->id,
            'action' => 'user.profile_updated_by_admin',
            'subject_type' => User::class,
            'subject_id' => $user->id,
            'data' => [
                'role' => $user->role,
                'fields' => ['name', 'username', 'email', 'phone', 'shop_name', 'address', 'vehicle'],
            ],
            'ip' => $request->ip(),
        ]);

        return back()->with('success', __('Account data updated successfully.'));
    }

    /**
     * A courier's per-order administration deduction changes payout terms,
     * so it must never piggyback on the general profile-edit endpoint.
     */
    public function updateCourierDeduction(Request $request, User $user)
    {
        abort_unless($user->role === 'courier', 404);
        $user = $this->scopedOperationalUser($request, $user);

        $data = $request->validate([
            'admin_deduction_per_order' => ['required', 'integer', 'min:0', 'max:1000000'],
        ]);

        $previousDeduction = (int) $user->admin_deduction_per_order;
        $deduction = (int) $data['admin_deduction_per_order'];

        $user->update(['admin_deduction_per_order' => $deduction]);

        ActivityLog::create([
            'tenant_id' => $user->tenant_id,
            'user_id' => $request->user()->id,
            'action' => 'courier.admin_deduction_updated',
            'subject_type' => User::class,
            'subject_id' => $user->id,
            'data' => [
                'previous_admin_deduction_per_order' => $previousDeduction,
                'admin_deduction_per_order' => $deduction,
            ],
            'ip' => $request->ip(),
        ]);

        return back()->with('success', 'تم تحديث استقطاع الإدارة لكل طلب للمندوب.');
    }

    public function status(Request $request, User $user)
    {
        abort_unless($user->role === 'merchant' || $user->isCourierRole(), 404);
        $user = $this->scopedOperationalUser($request, $user);

        $request->validate([
            'status' => ['required', Rule::in(['active', 'suspended', 'pending', 'rejected'])],
        ]);

        $status = $request->input('status');
        $user->update([
            'status' => $status,
            // A suspended or rejected courier must not remain visible as
            // online in dispatcher lists or on the live-location map.
            'is_online' => $status === 'active' ? $user->is_online : false,
        ]);
        // Account moderation is deliberately account-scoped.  A tenant may
        // have several merchant operators or couriers, so suspending one
        // person must not silently suspend every other account in that
        // organisation.  Organisation-level suspension remains a separate
        // platform/company operation.

        Notification::create([
            'tenant_id' => $user->tenant_id,
            'user_id' => $user->id,
            'type' => 'account',
            'title_ar' => __('notifications.account_status'),
            'title_en' => 'Account status',
            'body_ar' => __('notifications.account_status_body'),
            'body_en' => 'Your account status was updated by the platform.',
        ]);

        ActivityLog::create([
            'tenant_id' => $user->tenant_id,
            'user_id' => $request->user()->id,
            'action' => 'user.status',
            'subject_type' => 'user',
            'subject_id' => $user->id,
            'data' => ['status' => $status],
            'ip' => $request->ip(),
        ]);

        return back()->with('success', __('admin.user_updated'));
    }

    /**
     * The public verification mark is deliberately separate from a document
     * review.  It is set only by an administrator after the submitted
     * merchant documents have been checked, and never applies to couriers.
     */
    public function merchantVerification(Request $request, User $user)
    {
        abort_unless($user->role === 'merchant', 404);
        $user = $this->scopedOperationalUser($request, $user);

        $data = $request->validate([
            'verified' => ['required', 'boolean'],
        ]);

        if ($data['verified']) {
            $documents = $user->documents()->get(['id', 'type', 'status']);
            $review = $this->merchantDocumentReview($documents);
            if ($review['missing'] > 0) {
                return back()->withErrors(['verification' => 'يجب أن يرفع التاجر صور الهوية والسكن المطلوبة كاملة قبل منح علامة التوثيق.']);
            }

            if (! $review['complete']) {
                return back()->withErrors(['verification' => 'اعتمد جميع وثائق التاجر أو ارفضها قبل منح علامة التوثيق.']);
            }
        }

        $user->update([
            'merchant_verified_at' => $data['verified'] ? now() : null,
            'merchant_verified_by' => $data['verified'] ? $request->user()->id : null,
        ]);

        ActivityLog::create([
            'tenant_id' => $user->tenant_id,
            'user_id' => $request->user()->id,
            'action' => $data['verified'] ? 'merchant.verification_granted' : 'merchant.verification_removed',
            'subject_type' => User::class,
            'subject_id' => $user->id,
            'data' => ['verified' => (bool) $data['verified']],
            'ip' => $request->ip(),
        ]);

        return back()->with('success', $data['verified'] ? 'تم منح علامة توثيق التاجر.' : 'تمت إزالة علامة توثيق التاجر.');
    }

    /**
     * A courier's operational approval is deliberately separate from account
     * activation. A newly registered courier may sign in and finish their
     * profile, but cannot take a new order until an administrator has checked
     * all required documents and explicitly grants this approval.
     */
    public function courierVerification(Request $request, User $user)
    {
        abort_unless($user->role === 'courier', 404);
        $user = $this->scopedOperationalUser($request, $user);

        $data = $request->validate([
            'verified' => ['required', 'boolean'],
        ]);

        if ($data['verified']) {
            $documents = $user->documents()->get(['id', 'type', 'status']);
            $review = $this->courierDocumentReview($documents);

            if ($review['missing'] > 0) {
                return back()->withErrors([
                    'verification' => 'يجب أن يرفع المندوب جميع المستمسكات الخمسة المطلوبة قبل توثيق الحساب.',
                ]);
            }

            if (! $review['complete']) {
                return back()->withErrors([
                    'verification' => 'اعتمد جميع مستمسكات المندوب قبل توثيق الحساب والسماح له باستلام الطلبات.',
                ]);
            }
        }

        $verified = (bool) $data['verified'];
        $user->update([
            'courier_verified' => $verified,
            'courier_verified_at' => $verified ? now() : null,
            'courier_verified_by' => $verified ? $request->user()->id : null,
            // Removing operational approval must remove the live availability
            // signal too, so the courier cannot appear dispatchable anywhere.
            'is_online' => $verified ? $user->is_online : false,
        ]);

        Notification::create([
            'tenant_id' => $user->tenant_id,
            'user_id' => $user->id,
            'type' => 'account',
            'title_ar' => 'توثيق حساب المندوب',
            'title_en' => 'Courier account verification',
            'title_ku' => 'پشتڕاستکردنەوەی هەژماری گەیەنەر',
            'body_ar' => $verified
                ? 'تمت مراجعة مستمسكاتك وتوثيق حسابك. يمكنك الآن تفعيل حالة الاستلام وأخذ الطلبات.'
                : 'تم إيقاف توثيق الحساب. لا يمكنك استلام طلبات جديدة حتى تعيد الإدارة توثيقه.',
            'body_en' => $verified
                ? 'Your documents were reviewed and your account is verified. You can now go online and accept orders.'
                : 'Your account verification was removed. You cannot accept new orders until it is verified again.',
            'body_ku' => $verified
                ? 'بەڵگەنامەکانت پشکنران و هەژمارەکەت پشتڕاست کرایەوە. ئێستا دەتوانیت سەرهێڵ بیت و داواکاری وەربگریت.'
                : 'پشتڕاستکردنەوەی هەژمارەکەت لابرا. ناتوانیت داواکاریی نوێ وەربگریت تاوەکو دووبارە پشتڕاست بکرێتەوە.',
        ]);

        ActivityLog::create([
            'tenant_id' => $user->tenant_id,
            'user_id' => $request->user()->id,
            'action' => $verified ? 'courier.verification_granted' : 'courier.verification_removed',
            'subject_type' => User::class,
            'subject_id' => $user->id,
            'data' => ['verified' => $verified],
            'ip' => $request->ip(),
        ]);

        return back()->with('success', $verified
            ? 'تم توثيق حساب المندوب، ويمكنه الآن استلام الطلبات.'
            : 'تم إلغاء توثيق حساب المندوب وإيقاف استلام الطلبات الجديدة.');
    }

    /**
     * Keep "delete" recoverable and auditable.  Active delivery work cannot
     * lose its assigned identity, so an operator must first settle or
     * reassign open orders; completed history remains linked through the
     * model's soft-delete relations.
     */
    public function destroy(Request $request, User $user)
    {
        abort_unless($user->role === 'merchant' || $user->isCourierRole(), 404);
        $user = $this->scopedOperationalUser($request, $user);
        abort_if($user->is($request->user()), 422, 'لا يمكن حذف الحساب الذي تستخدمه حالياً.');

        $activeOrders = Order::withoutGlobalScope(TenantScope::class)
            ->whereNotIn('status', Order::TERMINAL_STATUSES)
            ->where(function ($orders) use ($user): void {
                if ($user->role === 'merchant') {
                    $orders->where('merchant_id', $user->id)->orWhere('created_by', $user->id);

                    return;
                }

                $orders->where('courier_id', $user->id)
                    ->orWhere('pickup_courier_id', $user->id)
                    ->orWhere('delivery_courier_id', $user->id);
            });
        $this->restrictOrdersToBranch($activeOrders, $this->branchScope($request));
        $activeOrders = $activeOrders->exists();

        if ($activeOrders) {
            return back()->withErrors(['delete' => 'لا يمكن حذف حساب مرتبط بطلبات مفتوحة. عطّل الحساب وأعد تعيين الطلبات أولاً.']);
        }

        $subjectId = $user->id;
        $subjectRole = $user->role;
        $subjectName = $user->name;
        $subjectTenantId = $user->tenant_id;
        $user->forceFill(['status' => 'suspended', 'is_online' => false])->save();
        $user->tokens()->delete();
        $user->delete();

        ActivityLog::create([
            'tenant_id' => $subjectTenantId,
            'user_id' => $request->user()->id,
            'action' => 'user.soft_deleted',
            'subject_type' => User::class,
            'subject_id' => $subjectId,
            'data' => ['role' => $subjectRole, 'name' => $subjectName],
            'ip' => $request->ip(),
        ]);

        return back()->with('success', 'تم حذف الحساب بأمان. يمكن استعادته من النسخة الاحتياطية عند الحاجة.');
    }

    /** @param array<string, int> $stats */
    private function courierRow(
        User $user,
        array $stats,
        bool $canViewDocuments,
        bool $canAccessDocumentRecords,
        bool $canViewFinanceBalances,
        bool $canViewLoyalty,
        bool $canUpdateCourierDeduction,
    ): array
    {
        $documents = $canAccessDocumentRecords ? $this->documentsFor($user) : collect();
        $documentReview = $canAccessDocumentRecords ? $this->courierDocumentReview($documents) : null;

        $row = [
            'id' => $user->id,
            'tenant_id' => $user->tenant_id,
            'name' => $user->name,
            'slug' => $user->tenant?->slug,
            'status' => $user->status,
            'role' => $user->role,
            'plan' => $user->tenant?->plan?->slug,
            'trial_ends_at' => $user->tenant?->trial_ends_at?->toDateString(),
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'phone' => $user->phone,
                'username' => $user->username,
                'status' => $user->status,
                'role' => $user->role,
                'vehicle' => $user->vehicle,
                'email' => $user->email,
                'shop_name' => $user->shop_name,
                'address' => $user->address,
                'is_online' => $user->is_online,
                'created_at' => $user->created_at?->toDateString(),
                'provinces' => $user->provinces->map(fn ($province) => [
                    'id' => $province->id,
                    'name_ar' => $province->name_ar,
                ])->values(),
            ],
            'verification' => [
                'status' => $user->isCourierVerified()
                    ? 'verified'
                    : ($canAccessDocumentRecords && $documentReview['complete'] ? 'pending' : ($documentReview['status'] ?? 'pending')),
                'verified' => $user->isCourierVerified(),
                'verified_at' => $user->courier_verified_at?->toIso8601String(),
                'verified_by' => $user->courierVerifiedBy?->name,
                'ready_to_verify' => $canAccessDocumentRecords && $documentReview['complete'],
            ],
            ...$stats,
        ];

        if ($canAccessDocumentRecords) {
            $row['document_review'] = $documentReview;
            $row['docs'] = $documentReview['pending'];
            $row['pendingDocs'] = $documents->where('status', 'pending')->pluck('id')->values();
            $row['documents'] = $documents->map(fn (Document $document) => [
                'id' => $document->id,
                'type' => $document->type,
                'status' => $document->status,
                'url' => $canViewDocuments ? route('admin.users.documents.show', [$user->id, $document->id]) : null,
            ])->values();
        }

        if ($canViewFinanceBalances) {
            $row['wallet_balance'] = $user->wallet?->balance ?? $user->tenant?->wallet_balance ?? 0;
            $row['cash_budget'] = $user->wallet?->budget ?? 0;
            $row['cash_budget_balance'] = $user->wallet?->budget_balance ?? 0;
        }

        if ($canViewLoyalty) {
            $row['points_balance'] = (int) ($user->loyaltyAccount?->balance ?? 0);
        }

        if ($canViewDocuments) {
            $row['user']['identity_number'] = $user->identity_number;
        }

        // The dedicated deduction editor needs the existing value, but a
        // courier-directory viewer must never receive it merely to render an
        // account card.
        if ($canViewFinanceBalances || $canUpdateCourierDeduction) {
            $row['admin_deduction_per_order'] = (int) $user->admin_deduction_per_order;
        }

        return $row;
    }

    /** @param array<string, int> $stats */
    private function merchantRow(
        User $user,
        array $stats,
        bool $canViewDocuments,
        bool $canAccessDocumentRecords,
        bool $canViewFinanceBalances,
    ): array
    {
        $documents = $canAccessDocumentRecords ? $this->documentsFor($user) : collect();
        $documentReview = $canAccessDocumentRecords ? $this->merchantDocumentReview($documents) : null;

        $row = [
            'id' => $user->id,
            'tenant_id' => $user->tenant_id,
            'name' => $user->name,
            'slug' => $user->tenant?->slug,
            'status' => $user->status,
            'role' => $user->role,
            'plan' => $user->tenant?->plan?->slug,
            'trial_ends_at' => $user->tenant?->trial_ends_at?->toDateString(),
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'phone' => $user->phone,
                'username' => $user->username,
                'email' => $user->email,
                'status' => $user->status,
                'role' => $user->role,
                'shop_name' => $user->shop_name,
                'address' => $user->address,
                'is_online' => $user->is_online,
                'created_at' => $user->created_at?->toDateString(),
                'provinces' => $user->provinces->map(fn ($province) => [
                    'id' => $province->id,
                    'name_ar' => $province->name_ar,
                ])->values(),
            ],
            'verification' => [
                'status' => $user->isMerchantVerified()
                    ? 'verified'
                    : ($documentReview['status'] ?? 'pending'),
                'verified' => $user->isMerchantVerified(),
                'verified_at' => $user->merchant_verified_at?->toIso8601String(),
                'verified_by' => $user->merchantVerifiedBy?->name,
                'ready_to_verify' => $canAccessDocumentRecords && $documentReview['complete'],
            ],
            ...$stats,
        ];

        if ($canAccessDocumentRecords) {
            $row['document_review'] = $documentReview;
            $row['docs'] = $documents->where('status', 'pending')->count();
            $row['pendingDocs'] = $documents->where('status', 'pending')->pluck('id')->values();
            $row['documents'] = $documents->map(fn (Document $document) => [
                'id' => $document->id,
                'type' => $document->type,
                'status' => $document->status,
                'url' => $canViewDocuments ? route('admin.users.documents.show', [$user->id, $document->id]) : null,
            ])->values();
        }

        if ($canViewFinanceBalances) {
            $row['wallet_balance'] = $user->wallet?->balance ?? 0;
            $row['cash_budget'] = $user->wallet?->budget ?? 0;
            $row['cash_budget_balance'] = $user->wallet?->budget_balance ?? 0;
        }

        if ($canViewDocuments) {
            $row['user']['identity_number'] = $user->identity_number;
        }

        return $row;
    }

    public function reviewDocument(Request $request, User $user, Document $document)
    {
        abort_unless($user->role === 'merchant' || $user->isCourierRole(), 404);
        $user = $this->scopedOperationalUser($request, $user);
        $document = $this->scopedDocument($document, $user);

        $request->validate([
            'status' => ['required', Rule::in(['approved', 'rejected'])],
        ]);

        $status = $request->input('status');

        // Rejecting a document for an already verified account revokes its
        // public/operational verification. Document review alone must not
        // silently grant that separate account-control authority.
        $requiresVerificationAuthority = $status === 'rejected'
            && (($user->role === 'merchant' && $user->merchant_verified_at)
                || ($user->role === 'courier' && $user->isCourierVerified()));
        if ($requiresVerificationAuthority) {
            $module = $user->role === 'merchant' ? 'merchants' : 'couriers';
            abort_unless($request->user()->canUseAdminPermission($module, 'verify'), 403);
        }

        $document->update(['status' => $status]);

        $courierVerificationRevoked = false;

        if ($user->role === 'merchant' && $status === 'rejected' && $user->merchant_verified_at) {
            $user->update([
                'merchant_verified_at' => null,
                'merchant_verified_by' => null,
            ]);
        }

        if ($user->role === 'courier' && $status === 'rejected' && $user->isCourierVerified()) {
            $user->update([
                'courier_verified' => false,
                'courier_verified_at' => null,
                'courier_verified_by' => null,
                'is_online' => false,
            ]);
            $courierVerificationRevoked = true;

            ActivityLog::create([
                'tenant_id' => $user->tenant_id,
                'user_id' => $request->user()->id,
                'action' => 'courier.verification_revoked',
                'subject_type' => User::class,
                'subject_id' => $user->id,
                'data' => [
                    'reason' => 'document_rejected',
                    'document_id' => $document->id,
                    'document_type' => $document->type,
                ],
                'ip' => $request->ip(),
            ]);
        }

        Notification::create([
            'tenant_id' => $user->tenant_id,
            'user_id' => $user->id,
            'type' => 'account',
            'title_ar' => 'مراجعة مستمسك',
            'title_en' => 'Document review',
            'body_ar' => $status === 'approved'
                ? 'تم اعتماد أحد مستمسكات حسابك.'
                : ($courierVerificationRevoked
                    ? 'تم رفض أحد مستمسكاتك، وأوقفت صلاحية استلام الطلبات حتى تحدّثه وتعتمده الإدارة.'
                    : 'تم رفض أحد مستمسكات حسابك. يرجى مراجعته وتحديثه.'),
            'body_en' => $status === 'approved'
                ? 'One of your account documents was approved.'
                : ($courierVerificationRevoked
                    ? 'One of your documents was rejected, so accepting new orders is paused until it is updated and approved.'
                    : 'One of your account documents was rejected. Please review and update it.'),
        ]);

        ActivityLog::create([
            'tenant_id' => $user->tenant_id,
            'user_id' => $request->user()->id,
            'action' => 'user.document_reviewed',
            'subject_type' => Document::class,
            'subject_id' => $document->id,
            'data' => [
                'account_id' => $user->id,
                'account_role' => $user->role,
                'document_type' => $document->type,
                'status' => $status,
            ],
            'ip' => $request->ip(),
        ]);

        return back()->with('success', __('admin.document_reviewed'));
    }

    /** @return Collection<int, Document> */
    private function documentsFor(User $user)
    {
        if ($user->relationLoaded('documents')) {
            return $user->documents->sortByDesc('id')->values();
        }

        return Document::query()
            ->where('user_id', $user->id)
            ->latest('id')
            ->get();
    }

    /**
     * This is an internal document-review state, not the public merchant
     * verification badge. It describes document completeness only; account
     * activation remains a separate admin decision because registration is
     * intentionally allowed after OTP confirmation.
     *
     * @param  Collection<int, Document>  $documents
     * @return array{status:string,total:int,approved:int,pending:int,rejected:int,missing:int,complete:bool}
     */
    private function courierDocumentReview($documents): array
    {
        $documentTypes = $documents->pluck('type')->unique()->all();
        $missing = array_values(array_diff(self::REQUIRED_COURIER_DOCUMENT_TYPES, $documentTypes));
        $approved = $documents->where('status', 'approved')->count();
        $pending = $documents->where('status', 'pending')->count();
        $rejected = $documents->where('status', 'rejected')->count();
        $complete = $missing === [] && $pending === 0 && $rejected === 0 && $documents->isNotEmpty();

        return [
            'status' => $missing !== [] ? 'unsubmitted' : ($rejected > 0 ? 'rejected' : ($pending > 0 ? 'pending' : 'approved')),
            'total' => $documents->count(),
            'approved' => $approved,
            'pending' => $pending,
            'rejected' => $rejected,
            'missing' => count($missing),
            'complete' => $complete,
        ];
    }

    /**
     * The public merchant badge remains a deliberate admin action. This
     * payload tells the UI exactly why that action is unavailable instead of
     * leaving a silent validation error after the button is pressed.
     *
     * @param  Collection<int, Document>  $documents
     * @return array{status:string,total:int,approved:int,pending:int,rejected:int,missing:int,complete:bool}
     */
    private function merchantDocumentReview($documents): array
    {
        $documentTypes = $documents->pluck('type')->unique()->all();
        $missing = array_values(array_diff(self::REQUIRED_MERCHANT_DOCUMENT_TYPES, $documentTypes));
        $approved = $documents->where('status', 'approved')->count();
        $pending = $documents->where('status', 'pending')->count();
        $rejected = $documents->where('status', 'rejected')->count();
        $complete = $missing === [] && $pending === 0 && $rejected === 0 && $documents->isNotEmpty();

        return [
            'status' => $missing !== [] ? 'unsubmitted' : ($rejected > 0 ? 'rejected' : ($pending > 0 ? 'pending' : 'approved')),
            'total' => $documents->count(),
            'approved' => $approved,
            'pending' => $pending,
            'rejected' => $rejected,
            'missing' => count($missing),
            'complete' => $complete,
        ];
    }

    public function showDocument(Request $request, User $user, Document $document)
    {
        abort_unless($user->role === 'merchant' || $user->isCourierRole(), 404);
        $user = $this->scopedOperationalUser($request, $user);
        $document = $this->scopedDocument($document, $user);
        abort_unless(Storage::disk('public')->exists($document->path), 404);

        return Storage::disk('public')->response($document->path, $document->type.'.'.pathinfo($document->path, PATHINFO_EXTENSION));
    }

    /**
     * Branch managers see only users registered against their one server-side
     * branch. Platform administrators retain the existing unscoped queries.
     */
    private function branchScope(Request $request, ?BranchDashboardScope $resolvedScope = null): ?BranchDashboardScope
    {
        $scope = $resolvedScope ?? app(BranchDashboardContext::class)->fromRequest($request);

        if (! $scope->requiresBranchScope()) {
            return null;
        }

        abort_unless($scope->hasBranchScope(), 403);

        return $scope;
    }

    /** @param Builder<User> $users */
    private function restrictRosterToBranch(Builder $users, ?BranchDashboardScope $scope): void
    {
        if ($scope !== null) {
            $scope->restrictUsers($users);
        }
    }

    /** @param Builder<User> $users */
    private function restrictRosterForDashboard(
        Builder $users,
        ?BranchDashboardScope $scope,
        ?int $selectedBranchId,
        DashboardBranchFilter $branchFilter,
    ): void {
        if ($scope !== null) {
            $scope->restrictUsers($users);

            return;
        }

        $branchFilter->restrictByColumn(
            $users,
            $selectedBranchId,
            $users->getModel()->qualifyColumn('branch_id'),
        );
    }

    /** @param Builder<Order> $orders */
    private function restrictOrdersToBranch(Builder $orders, ?BranchDashboardScope $scope): void
    {
        if ($scope !== null) {
            $scope->restrictOrders($orders);
        }
    }

    /** @param Builder<Order> $orders */
    private function restrictOrdersForDashboard(
        Builder $orders,
        ?BranchDashboardScope $scope,
        ?int $selectedBranchId,
        DashboardBranchFilter $branchFilter,
    ): void {
        if ($scope !== null) {
            $scope->restrictOrders($orders);

            return;
        }

        $branchFilter->restrictOrders($orders, $selectedBranchId);
    }

    /**
     * Do not trust a route-bound user model for a branch account. It may have
     * been loaded before this controller ran, so re-read it with the branch
     * predicate in SQL before any mutation or document lookup.
     */
    private function scopedOperationalUser(Request $request, User $user): User
    {
        $scope = $this->branchScope($request);

        if ($scope === null) {
            return $user;
        }

        return $scope->restrictUsers(User::query())
            ->whereKey($user->getKey())
            ->where(function (Builder $people): void {
                $people
                    ->where('role', 'merchant')
                    ->orWhereIn('role', User::COURIER_ROLES);
            })
            ->firstOrFail();
    }

    /**
     * Documents have no branch column of their own. Their parent account is
     * therefore resolved under the branch scope first, then the document is
     * re-read by its id and that account id in one SQL query.
     */
    private function scopedDocument(Document $document, User $user): Document
    {
        return Document::query()
            ->whereKey($document->getKey())
            ->where('user_id', $user->getKey())
            ->firstOrFail();
    }
}
