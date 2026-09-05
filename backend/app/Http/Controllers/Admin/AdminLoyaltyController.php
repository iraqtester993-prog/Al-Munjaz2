<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\LoyaltyEntry;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use App\Services\BranchDashboardContext;
use App\Services\BranchDashboardScope;
use App\Services\DashboardBranchFilter;
use App\Services\LoyaltyPointService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Administrative interface for the non-monetary loyalty ledger.
 *
 * Point balances are intentionally managed separately from wallets.  This
 * controller never writes a balance directly: every adjustment is delegated
 * to LoyaltyPointService so the immutable ledger remains the source of truth.
 */
class AdminLoyaltyController extends Controller
{
    public function index(Request $request, LoyaltyPointService $loyalty)
    {
        $user = $request->user();
        $scope = $this->branchScope($request);
        $branchFilter = app(DashboardBranchFilter::class);
        $selectedBranchId = $branchFilter->selectedBranchId($request, $scope);

        $courierQuery = User::withoutGlobalScopes()
            ->whereIn('role', User::COURIER_ROLES)
            ->where('status', 'active')
            ->with('loyaltyAccount:user_id,balance');
        $this->restrictCouriersToScope($courierQuery, $scope, $selectedBranchId);

        $couriers = $courierQuery
            ->orderBy('name')
            ->get(['id', 'name', 'phone', 'role', 'tenant_id'])
            ->map(fn (User $courier) => [
                'id' => $courier->id,
                'name' => $courier->name,
                'phone' => $courier->phone,
                'role' => $courier->role,
                'points_balance' => (int) ($courier->loyaltyAccount?->balance ?? 0),
            ])
            ->values();

        // The ledger deliberately includes an inactive courier's previous
        // movements.  Suspending an account must never hide audit history.
        $entryQuery = LoyaltyEntry::query()
            ->whereHas('user', function (Builder $courierUsers) use ($scope, $selectedBranchId): void {
                $courierUsers
                    ->withoutGlobalScopes()
                    ->withTrashed()
                    ->whereIn('role', User::COURIER_ROLES);
                $this->restrictCouriersToScope($courierUsers, $scope, $selectedBranchId);
            });

        $ledgerEntries = (clone $entryQuery)->count();

        $entries = $entryQuery
            ->with([
                'user' => fn ($query) => $query
                    ->withoutGlobalScopes()
                    ->withTrashed()
                    ->select(['id', 'name', 'phone', 'role']),
            ])
            ->latest('id')
            ->limit(250)
            ->get()
            ->map(fn (LoyaltyEntry $entry) => [
                'id' => $entry->id,
                'points' => (int) $entry->points,
                'balance_after' => (int) $entry->balance_after,
                'type' => $entry->type,
                'source_type' => $entry->source_type,
                'source_id' => $entry->source_id,
                'note' => $entry->note,
                'created_at' => $entry->created_at?->toIso8601String(),
                'courier' => $entry->user ? [
                    'id' => $entry->user->id,
                    'name' => $entry->user->name,
                    'phone' => $entry->user->phone,
                    'role' => $entry->user->role,
                ] : null,
            ])
            ->values();

        return Inertia::render('Admin/Loyalty', [
            'couriers' => $couriers,
            'entries' => $entries,
            'settings' => [
                'points_per_delivery' => $loyalty->pointsPerDelivery(),
            ],
            'summary' => [
                'active_couriers' => $couriers->count(),
                'points_in_circulation' => (int) $couriers->sum('points_balance'),
                'couriers_with_points' => $couriers->filter(fn (array $courier) => $courier['points_balance'] > 0)->count(),
                'ledger_entries' => ($scope->hasBranchScope() || $selectedBranchId)
                    ? $ledgerEntries
                    : LoyaltyEntry::query()->count(),
            ],
            // Delivery reward is a shared platform setting, never a branch
            // setting. A branch manager can adjust only their own couriers'
            // compensating point entries.
            'canUpdateRewardSetting' => ! $scope->hasBranchScope()
                && ! $selectedBranchId
                && $user->canUseAdminPermission('loyalty', 'update_reward_setting'),
            'canAdjustPoints' => $user->canUseAdminPermission('loyalty', 'adjust_points'),
            'branchFilter' => $branchFilter->payload($request, $scope),
        ]);
    }

    /**
     * Save the platform-wide award for a completed delivery.
     */
    public function store(Request $request, LoyaltyPointService $loyalty)
    {
        $scope = $this->branchScope($request);
        abort_if($scope->hasBranchScope(), 403);

        $data = $request->validate([
            'points_per_delivery' => ['required', 'integer', 'min:0', 'max:1000000'],
        ]);

        $previous = $loyalty->pointsPerDelivery();
        $points = (int) $data['points_per_delivery'];
        Setting::set(LoyaltyPointService::POINTS_PER_DELIVERY_KEY, $points);
        $setting = Setting::query()->where('key', LoyaltyPointService::POINTS_PER_DELIVERY_KEY)->first();

        ActivityLog::create([
            'tenant_id' => Tenant::platform()->id,
            'user_id' => $request->user()->id,
            'action' => 'loyalty.delivery_reward_updated',
            'subject_type' => Setting::class,
            'subject_id' => $setting?->id,
            'data' => [
                'key' => LoyaltyPointService::POINTS_PER_DELIVERY_KEY,
                'previous' => $previous,
                'current' => $points,
            ],
            'ip' => $request->ip(),
        ]);

        return back()->with('success', __('Courier point setting saved.'));
    }

    /**
     * Add a compensating ledger entry for an active courier.
     */
    public function adjust(Request $request, LoyaltyPointService $loyalty)
    {
        $scope = $this->branchScope($request);
        $data = $request->validate([
            'courier_id' => [
                'required',
                'integer',
                Rule::exists('users', 'id')->where(fn ($query) => $query
                    ->whereIn('role', User::COURIER_ROLES)
                    ->where('status', 'active')
                    ->whereNull('deleted_at')),
            ],
            'operation' => ['required', Rule::in(['credit', 'debit'])],
            'points' => ['required', 'integer', 'min:1', 'max:1000000'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $reason = trim($data['reason']);
        if ($reason === '') {
            throw ValidationException::withMessages([
                'reason' => [__('A reason is required for every points adjustment.')],
            ]);
        }

        $courierQuery = User::withoutGlobalScopes()
            ->whereIn('role', User::COURIER_ROLES)
            ->where('status', 'active')
            ->whereKey($data['courier_id']);
        $this->restrictCouriersToScope($courierQuery, $scope);
        $courier = $courierQuery->first();

        if (! $courier) {
            throw ValidationException::withMessages([
                // Do not reveal whether a courier in another branch exists.
                'courier_id' => [__('Choose an active courier from your branch.')],
            ]);
        }

        $points = (int) $data['points'];
        $entry = $data['operation'] === 'credit'
            ? $loyalty->credit($courier, $points, 'admin_credit', null, null, $reason)
            : $loyalty->debit($courier, $points, 'admin_debit', null, null, $reason);

        ActivityLog::create([
            'tenant_id' => $courier->tenant_id ?? Tenant::platform()->id,
            'user_id' => $request->user()->id,
            'action' => $data['operation'] === 'credit' ? 'loyalty.points_credited' : 'loyalty.points_debited',
            'subject_type' => LoyaltyEntry::class,
            'subject_id' => $entry->id,
            'data' => [
                'courier_id' => $courier->id,
                'points' => (int) $entry->points,
                'balance_after' => (int) $entry->balance_after,
                'reason' => $reason,
                'entry_type' => $entry->type,
            ],
            'ip' => $request->ip(),
        ]);

        return back()->with('success', $data['operation'] === 'credit'
            ? __('Courier points added successfully.')
            : __('Courier points deducted successfully.'));
    }

    private function branchScope(Request $request): BranchDashboardScope
    {
        $scope = app(BranchDashboardContext::class)->fromRequest($request);

        abort_if($scope->requiresBranchScope() && ! $scope->hasBranchScope(), 403);

        return $scope;
    }

    /** @param Builder<User> $couriers */
    private function restrictCouriersToScope(Builder $couriers, BranchDashboardScope $scope, ?int $selectedBranchId = null): void
    {
        if ($scope->hasBranchScope()) {
            $scope->restrictUsers($couriers);
        } elseif ($selectedBranchId) {
            $couriers->where($couriers->getModel()->qualifyColumn('branch_id'), $selectedBranchId);
        }
    }
}
