<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\PricingRule;
use App\Models\Province;
use App\Models\Scopes\TenantScope;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class AdminPricingController extends Controller
{
    public function index()
    {
        $rules = PricingRule::withoutGlobalScope(TenantScope::class)
            ->with([
                'merchant:id,name,shop_name',
                'originProvince:id,name_ar,name_en,name_ku',
                'destinationProvince:id,name_ar,name_en,name_ku',
            ])
            ->orderBy('priority')
            ->orderByDesc('id')
            ->get()
            ->map(fn (PricingRule $rule) => $this->payload($rule));

        return Inertia::render('Admin/Pricing', [
            'rules' => $rules,
            'merchants' => User::withoutGlobalScopes()
                ->where('role', 'merchant')
                ->where('status', 'active')
                ->orderBy('name')
                ->get(['id', 'name', 'shop_name', 'phone'])
                ->map(fn (User $user) => ['id' => $user->id, 'name' => $user->name, 'shop_name' => $user->shop_name, 'phone' => $user->phone]),
            'provinces' => Province::query()->orderBy('name_ar')->get(['id', 'name_ar', 'name_en', 'name_ku']),
            'vehicles' => ['normal', 'bike', 'sedan', 'suv', 'truck'],
        ]);
    }

    public function store(Request $request)
    {
        $rule = PricingRule::withoutGlobalScope(TenantScope::class)->create([
            ...$this->validated($request),
            'tenant_id' => Tenant::platform()->id,
            'is_active' => true,
        ]);

        $this->activity($request, 'pricing_rule.created', $rule->id, ['name' => $rule->name_ar]);

        return back()->with('success', __('Pricing rule created.'));
    }

    public function update(Request $request, PricingRule $pricingRule)
    {
        $rule = PricingRule::withoutGlobalScope(TenantScope::class)->findOrFail($pricingRule->id);
        $before = $rule->only(['merchant_id', 'origin_province_id', 'destination_province_id', 'service', 'vehicle', 'min_weight_grams', 'max_weight_grams', 'base_fee', 'return_fee', 'priority', 'is_active']);
        $rule->update($this->validated($request));
        $this->activity($request, 'pricing_rule.updated', $rule->id, ['before' => $before, 'after' => $rule->fresh()->only(array_keys($before))]);

        return back()->with('success', __('Pricing rule updated.'));
    }

    public function status(Request $request, PricingRule $pricingRule)
    {
        $data = $request->validate(['is_active' => ['required', 'boolean']]);
        $rule = PricingRule::withoutGlobalScope(TenantScope::class)->findOrFail($pricingRule->id);
        $rule->update(['is_active' => (bool) $data['is_active']]);
        $this->activity($request, 'pricing_rule.status_updated', $rule->id, ['is_active' => $rule->is_active]);

        return back()->with('success', __('Pricing rule status updated.'));
    }

    private function validated(Request $request): array
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

        return $data;
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
