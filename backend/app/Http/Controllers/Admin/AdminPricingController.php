<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\PricingRule;
use App\Models\Province;
use App\Models\Scopes\TenantScope;
use App\Models\Tenant;
use App\Models\User;
use App\Services\BranchDashboardContext;
use App\Services\BranchDashboardScope;
use App\Services\DashboardBranchFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class AdminPricingController extends Controller
{
    public function index(Request $request, BranchDashboardContext $branchDashboard)
    {
        $user = $request->user();
        $scope = $branchDashboard->fromRequest($request);
        $branchFilter = app(DashboardBranchFilter::class);
        $selectedBranchId = $branchFilter->selectedBranchId($request, $scope);
        $canCreatePricing = $user->canUseAdminPermission('pricing', 'create');
        $canEditPricing = $user->canUseAdminPermission('pricing', 'edit');
        $canChangePricingStatus = $user->canUseAdminPermission('pricing', 'change_status');
        $canUpdatePricing = $canEditPricing || $canChangePricingStatus;
        $canManagePricing = $canCreatePricing || $canUpdatePricing;

        $rulesQuery = PricingRule::withoutGlobalScope(TenantScope::class)
            ->with([
                'merchant:id,name,shop_name',
                'originProvince:id,name_ar,name_en,name_ku',
                'destinationProvince:id,name_ar,name_en,name_ku',
            ])
            ->orderBy('priority')
            ->orderByDesc('id');

        $this->restrictRulesToBranchProvince($rulesQuery, $scope, $selectedBranchId, $branchFilter);

        $rules = $rulesQuery
            ->get()
            ->map(fn (PricingRule $rule) => $this->payload($rule));

        $props = [
            'rules' => $rules,
            'provinces' => Province::query()->orderBy('name_ar')->get(['id', 'name_ar', 'name_en', 'name_ku']),
            'vehicles' => ['normal', 'bike', 'sedan', 'suv', 'truck'],
            'canManagePricing' => $canManagePricing,
            'canCreatePricing' => $canCreatePricing,
            'canUpdatePricing' => $canUpdatePricing,
            'canEditPricing' => $canEditPricing,
            'canChangePricingStatus' => $canChangePricingStatus,
            'branchFilter' => $branchFilter->payload($request, $scope),
        ];

        // Pricing viewers can audit the rules, but the full merchant
        // directory (including phone numbers) is only needed to create or
        // modify a rule.
        if ($canCreatePricing || $canEditPricing) {
            $merchants = User::withoutGlobalScopes()
                ->where('role', 'merchant')
                ->where('status', 'active');

            if ($scope->requiresBranchScope()) {
                $merchants->whereNull('deleted_at');
                $scope->restrictUsers($merchants);
            } elseif ($selectedBranchId !== null) {
                $merchants->whereNull('deleted_at');
                $branchFilter->restrictByColumn($merchants, $selectedBranchId, 'users.branch_id');
            }

            $props['merchants'] = $merchants
                ->orderBy('name')
                ->get(['id', 'name', 'shop_name', 'phone'])
                ->map(fn (User $user) => ['id' => $user->id, 'name' => $user->name, 'shop_name' => $user->shop_name, 'phone' => $user->phone]);
        }

        return Inertia::render('Admin/Pricing', $props);
    }

    public function store(Request $request, BranchDashboardContext $branchDashboard)
    {
        $scope = $branchDashboard->fromRequest($request);
        $rule = PricingRule::withoutGlobalScope(TenantScope::class)->create([
            ...$this->validated($request, $scope),
            'tenant_id' => Tenant::platform()->id,
            'is_active' => true,
        ]);

        $this->activity($request, 'pricing_rule.created', $rule->id, ['name' => $rule->name_ar]);

        return back()->with('success', __('Pricing rule created.'));
    }

    public function update(Request $request, PricingRule $pricingRule, BranchDashboardContext $branchDashboard)
    {
        $scope = $branchDashboard->fromRequest($request);
        $rules = PricingRule::withoutGlobalScope(TenantScope::class);
        $this->restrictRulesToBranchProvince($rules, $scope);
        $rule = $rules->findOrFail($pricingRule->id);
        $before = $rule->only(['merchant_id', 'origin_province_id', 'destination_province_id', 'service', 'vehicle', 'min_weight_grams', 'max_weight_grams', 'base_fee', 'return_fee', 'priority', 'is_active']);
        $rule->update($this->validated($request, $scope));
        $this->activity($request, 'pricing_rule.updated', $rule->id, ['before' => $before, 'after' => $rule->fresh()->only(array_keys($before))]);

        return back()->with('success', __('Pricing rule updated.'));
    }

    public function status(Request $request, PricingRule $pricingRule, BranchDashboardContext $branchDashboard)
    {
        $data = $request->validate(['is_active' => ['required', 'boolean']]);
        $scope = $branchDashboard->fromRequest($request);
        $rules = PricingRule::withoutGlobalScope(TenantScope::class);
        $this->restrictRulesToBranchProvince($rules, $scope);
        $rule = $rules->findOrFail($pricingRule->id);
        $rule->update(['is_active' => (bool) $data['is_active']]);
        $this->activity($request, 'pricing_rule.status_updated', $rule->id, ['is_active' => $rule->is_active]);

        return back()->with('success', __('Pricing rule status updated.'));
    }

    private function validated(Request $request, BranchDashboardScope $scope): array
    {
        $data = $request->validate([
            'name_ar' => ['required', 'string', 'max:120'],
            'name_en' => ['nullable', 'string', 'max:120'],
            'name_ku' => ['nullable', 'string', 'max:120'],
            'merchant_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where(fn ($query) => $query->where('role', 'merchant'))],
            'origin_province_id' => ['nullable', 'integer', Rule::exists('provinces', 'id')],
            'destination_province_id' => ['nullable', 'integer', Rule::exists('provinces', 'id')],
            'service' => ['nullable', 'string', 'max:60'],
            'vehicle' => ['nullable', Rule::in(['normal', 'bike', 'sedan', 'suv', 'truck'])],
            'min_weight_grams' => ['required', 'integer', 'min:0', 'max:1000000'],
            'max_weight_grams' => ['nullable', 'integer', 'min:0', 'max:1000000', 'gte:min_weight_grams'],
            'base_fee' => ['required', 'integer', 'min:0', 'max:100000000'],
            'return_fee' => ['required', 'integer', 'min:0', 'max:100000000'],
            'priority' => ['required', 'integer', 'min:1', 'max:9999'],
        ]);

        foreach (['merchant_id', 'origin_province_id', 'destination_province_id', 'service', 'vehicle', 'max_weight_grams'] as $field) {
            if (blank($data[$field] ?? null)) {
                $data[$field] = null;
            }
        }

        $this->enforceBranchPricingPayload($data, $scope);

        return $data;
    }

    /**
     * A pricing rule is owned by its origin governorate because delivery
     * quoting resolves the merchant's primary province as the origin. A
     * branch manager may select another destination governorate for an
     * inter-province route, but can never create a rule for a different
     * origin or attach a merchant from another branch.
     *
     * @param  array<string, mixed>  $data
     */
    private function enforceBranchPricingPayload(array &$data, BranchDashboardScope $scope): void
    {
        if (! $scope->requiresBranchScope()) {
            return;
        }

        $provinceId = $this->branchProvinceId($scope);
        $submittedOrigin = $data['origin_province_id'] ?? null;

        if ($submittedOrigin !== null && (int) $submittedOrigin !== $provinceId) {
            throw ValidationException::withMessages([
                'origin_province_id' => [__('The pricing-rule origin must be your branch governorate.')],
            ]);
        }

        // A blank source is not a global fallback when the actor is a branch
        // manager. Bind it on the server, never by trusting the browser.
        $data['origin_province_id'] = $provinceId;

        if ($data['merchant_id'] === null) {
            return;
        }

        $merchant = User::withoutGlobalScopes()
            ->whereKey($data['merchant_id'])
            ->where('role', 'merchant')
            ->whereNull('deleted_at');
        $scope->restrictUsers($merchant);

        abort_unless($merchant->exists(), 404);
    }

    /**
     * @param  Builder<PricingRule>  $rules
     */
    private function restrictRulesToBranchProvince(Builder $rules, BranchDashboardScope $scope, ?int $selectedBranchId = null, ?DashboardBranchFilter $branchFilter = null): void
    {
        $branch = $this->pricingBranch($scope, $selectedBranchId, $branchFilter);

        if ($branch === null) {
            return;
        }

        $provinceId = $branch->province_id;
        if ($provinceId === null) {
            $rules->whereRaw('1 = 0');

            return;
        }

        $merchantIds = User::withoutGlobalScopes()
            ->select('id')
            ->where('role', 'merchant')
            ->whereNull('deleted_at');
        if ($scope->hasBranchScope()) {
            $scope->restrictUsers($merchantIds);
        } else {
            $branchFilter?->restrictByColumn($merchantIds, $selectedBranchId, 'users.branch_id');
        }

        $rules
            ->where('tenant_id', Tenant::platform()->id)
            ->where('origin_province_id', $provinceId)
            // A generic provincial rule is safe, while a merchant-specific
            // rule is visible only when that merchant belongs to this branch.
            ->where(function (Builder $rules) use ($merchantIds): void {
                $rules
                    ->whereNull('merchant_id')
                    ->orWhereIn('merchant_id', $merchantIds);
            });
    }

    private function pricingBranch(BranchDashboardScope $scope, ?int $selectedBranchId, ?DashboardBranchFilter $branchFilter): ?Branch
    {
        if ($scope->hasBranchScope()) {
            return $scope->branch();
        }

        return $selectedBranchId === null ? null : $branchFilter?->platformBranches()->find($selectedBranchId);
    }

    private function branchProvinceId(BranchDashboardScope $scope): int
    {
        $provinceId = $scope->branch()?->province_id;

        abort_unless($provinceId !== null, 403);

        return (int) $provinceId;
    }

    private function payload(PricingRule $rule): array
    {
        return [
            'id' => $rule->id,
            'name_ar' => $rule->name_ar,
            'name_en' => $rule->name_en,
            'name_ku' => $rule->name_ku,
            'merchant_id' => $rule->merchant_id,
            'merchant' => $rule->merchant ? ['id' => $rule->merchant->id, 'name' => $rule->merchant->name, 'shop_name' => $rule->merchant->shop_name] : null,
            'origin_province_id' => $rule->origin_province_id,
            'origin_province' => $rule->originProvince ? $this->provincePayload($rule->originProvince) : null,
            'destination_province_id' => $rule->destination_province_id,
            'destination_province' => $rule->destinationProvince ? $this->provincePayload($rule->destinationProvince) : null,
            'service' => $rule->service,
            'vehicle' => $rule->vehicle,
            'min_weight_grams' => (int) $rule->min_weight_grams,
            'max_weight_grams' => $rule->max_weight_grams !== null ? (int) $rule->max_weight_grams : null,
            'base_fee' => (int) $rule->base_fee,
            'return_fee' => (int) $rule->return_fee,
            'priority' => (int) $rule->priority,
            'is_active' => (bool) $rule->is_active,
        ];
    }

    private function provincePayload(Province $province): array
    {
        return ['id' => $province->id, 'name_ar' => $province->name_ar, 'name_en' => $province->name_en, 'name_ku' => $province->name_ku];
    }

    private function activity(Request $request, string $action, int $subjectId, array $data): void
    {
        ActivityLog::create([
            'tenant_id' => Tenant::platform()->id,
            'user_id' => $request->user()->id,
            'action' => $action,
            'subject_type' => 'pricing_rule',
            'subject_id' => $subjectId,
            'data' => $data,
            'ip' => $request->ip(),
        ]);
    }
}
