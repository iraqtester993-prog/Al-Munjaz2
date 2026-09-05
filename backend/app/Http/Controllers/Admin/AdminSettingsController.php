<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\MobileSlide;
use App\Models\Province;
use App\Models\Setting;
use App\Models\Tenant;
use App\Services\BranchDashboardAuthorization;
use App\Services\BranchDashboardContext;
use App\Services\BranchDashboardScope;
use App\Services\BranchSettingsResolver;
use App\Services\LoyaltyPointService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class AdminSettingsController extends Controller
{
    public function index(Request $request)
    {
        $scope = app(BranchDashboardContext::class)->fromRequest($request);
        if ($scope->requiresBranchScope()) {
            abort_unless($scope->hasBranchScope(), 403);

            return $this->branchIndex($request, $scope, $this->settingsBranch($request));
        }

        if ($branch = $this->selectedPlatformSettingsBranch($request)) {
            return $this->branchIndex($request, $scope, $branch);
        }

        $user = $request->user();
        $canViewSettings = $user->canUseAdminPermission('settings', 'view');
        $canViewContent = $user->canUseAdminPermission('content', 'view');
        $canViewLoyalty = $user->canUseAdminPermission('loyalty', 'view');
        $canViewProvinces = $user->canUseAdminPermission('provinces', 'view');
        $canUpdateFinancialDefaults = $user->canUseAdminPermission('settings', 'update_financial_defaults');
        $canUpdateCourierDeductionDefault = $user->canUseAdminPermission('settings', 'update_courier_deduction_default');

        $props = [
            // A content-only operator may open this page directly on the
            // Slider tab. Keep the rest of Settings out of that payload.
            'branding' => $canViewSettings ? $this->brandingPayload() : [
                'name' => '', 'tagline' => '', 'logo_url' => '', 'has_custom_logo' => false,
            ],
            'settings' => $canViewSettings ? $this->settingsPayload($canViewLoyalty, $canUpdateCourierDeductionDefault) : [
                'support_phone' => '',
                'support_email' => '',
                'currency' => 'IQD',
                'delivery_fee' => 0,
                'order_expiry_minutes' => 30,
                'pickup_eta_minutes' => 30,
                'public_content' => Setting::normalizePublicContent([]),
            ],
            'canViewSettings' => $canViewSettings,
            'canUpdateSettings' => $user->canUseAdminPermission('settings', 'update_branding')
                || $user->canUseAdminPermission('settings', 'update_support')
                || $canUpdateFinancialDefaults
                || $canUpdateCourierDeductionDefault
                || $user->canUseAdminPermission('settings', 'update_timing')
                || $user->canUseAdminPermission('settings', 'update_public_content'),
            'canUpdateBranding' => $user->canUseAdminPermission('settings', 'update_branding'),
            'canUpdateSupport' => $user->canUseAdminPermission('settings', 'update_support'),
            'canUpdateFinancialDefaults' => $canUpdateFinancialDefaults,
            'canUpdateCourierDeductionDefault' => $canUpdateCourierDeductionDefault,
            'canUpdateTiming' => $user->canUseAdminPermission('settings', 'update_timing'),
            'canUpdatePublicContent' => $user->canUseAdminPermission('settings', 'update_public_content'),
            // The legal documents are a platform responsibility. Other
            // platform operators may receive the public-content grant for
            // About/Developer text, but never the authority to change the
            // privacy policy or terms.
            'canManageLegalContent' => $user->isSuperAdmin(),
            // Governorates are a separate capability even though their tab
            // lives inside Settings.
            'provinces' => $canViewProvinces ? $this->provincesPayload() : [],
            'canViewProvinces' => $canViewProvinces,
            'canCreateProvinces' => $user->canUseAdminPermission('provinces', 'create'),
            'canUpdateProvinces' => $user->canUseAdminPermission('provinces', 'edit'),
            'canChangeProvinceStatus' => $user->canUseAdminPermission('provinces', 'change_status'),
            'canViewContent' => $canViewContent,
            'canCreateContent' => $user->canUseAdminPermission('content', 'create'),
            'canUpdateContent' => $user->canUseAdminPermission('content', 'edit'),
            'canDeleteContent' => $user->canUseAdminPermission('content', 'delete'),
            'canViewLoyalty' => $canViewLoyalty,
            'canUpdateLoyalty' => $user->canUseAdminPermission('loyalty', 'update_reward_setting'),
            'settingsScope' => [
                'type' => 'platform',
                'branch_id' => null,
                'branch_name' => null,
                'overridden_keys' => [],
            ],
            'canSelectSettingsBranch' => $user->isSuperAdmin(),
            'settingsBranches' => $user->isSuperAdmin() ? $this->settingsBranchesPayload() : [],
        ];

        // Slides deliberately remain first-class records rather than a JSON
        // setting. Do not expose their titles, schedules, or branch links to
        // a settings-only operator: they belong to the content module.
        if ($canViewContent) {
            $props['slides'] = MobileSlide::query()
                ->with('branch:id,name_ar,name_en,name_ku,city')
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get()
                ->map(fn (MobileSlide $slide) => $slide->dashboardPayload())
                ->values();
            $props['branches'] = $this->sliderBranchesPayload();
        }

        return Inertia::render('Admin/Settings', $props);
    }

    /**
     * The branch uses the same screen, but every mutable setting is resolved
     * through a branch-owned override. The platform record remains the
     * fallback and legal pages deliberately remain platform-wide.
     */
    private function branchIndex(Request $request, BranchDashboardScope $scope, ?Branch $selectedBranch = null)
    {
        $user = $request->user();
        $authorization = app(BranchDashboardAuthorization::class);
        $resolver = app(BranchSettingsResolver::class);
        $branch = $selectedBranch ?? $this->branchFromSettingsScope($scope);
        $isPlatformSelection = $scope->isSuperAdmin() && $selectedBranch instanceof Branch;
        $can = function (string $module, string $action) use ($authorization, $isPlatformSelection, $scope, $user): bool {
            return $isPlatformSelection
                ? $user->canUseAdminPermission($module, $action)
                : $authorization->allows($user, $scope, $module, $action);
        };
        $canViewSettings = $can('settings', 'view');
        $canViewContent = $can('content', 'view');
        $canUpdateFinancialDefaults = $can('settings', 'update_financial_defaults');
        $canUpdateCourierDeductionDefault = $can('settings', 'update_courier_deduction_default');

        $props = [
            'branding' => $canViewSettings ? $this->branchBrandingPayload($branch, $resolver) : [
                'name' => '', 'tagline' => '', 'logo_url' => '', 'has_custom_logo' => false,
            ],
            'settings' => $canViewSettings ? $this->branchSettingsPayload($branch, $resolver, $canUpdateCourierDeductionDefault) : [
                'support_phone' => '',
                'support_email' => '',
                'currency' => 'IQD',
                'delivery_fee' => 0,
                'order_expiry_minutes' => 30,
                'pickup_eta_minutes' => 30,
                'public_content' => $this->emptyBranchPublicContent(),
            ],
            'canViewSettings' => $canViewSettings,
            'canUpdateSettings' => $can('settings', 'update_branding')
                || $can('settings', 'update_support')
                || $canUpdateFinancialDefaults
                || $canUpdateCourierDeductionDefault
                || $can('settings', 'update_timing')
                || $can('settings', 'update_public_content'),
            'canUpdateBranding' => $can('settings', 'update_branding'),
            'canUpdateSupport' => $can('settings', 'update_support'),
            'canUpdateFinancialDefaults' => $canUpdateFinancialDefaults,
            'canUpdateCourierDeductionDefault' => $canUpdateCourierDeductionDefault,
            'canUpdateTiming' => $can('settings', 'update_timing'),
            'canUpdatePublicContent' => $can('settings', 'update_public_content'),
            'canManageLegalContent' => false,
            'canViewProvinces' => false,
            'canCreateProvinces' => false,
            'canUpdateProvinces' => false,
            'canChangeProvinceStatus' => false,
            'canViewLoyalty' => false,
            'canUpdateLoyalty' => false,
            'provinces' => [],
            'canViewContent' => $canViewContent,
            'canCreateContent' => $can('content', 'create'),
            'canUpdateContent' => $can('content', 'edit'),
            'canDeleteContent' => $can('content', 'delete'),
            'slides' => $canViewContent
                ? ($isPlatformSelection
                    ? MobileSlide::query()->where('branch_id', $branch->id)
                    : $scope->restrict(MobileSlide::query()))
                    ->with('branch:id,name_ar,name_en,name_ku,city')
                    ->orderBy('sort_order')
                    ->orderBy('id')
                    ->get()
                    ->map(fn (MobileSlide $slide) => $slide->dashboardPayload())
                    ->values()
                : [],
            'branches' => [[
                'id' => $branch->id,
                'name_ar' => $branch->name_ar,
                'name_en' => $branch->name_en,
                'name_ku' => $branch->name_ku,
                'city' => $branch->city,
            ]],
            'settingsScope' => [
                'type' => 'branch',
                'branch_id' => $branch->id,
                'branch_name' => $branch->name_ar ?: ($branch->name_en ?: $branch->name_ku),
                'overridden_keys' => $canViewSettings ? $resolver->overriddenKeys($branch) : [],
            ],
            'canSelectSettingsBranch' => $scope->isSuperAdmin(),
            'settingsBranches' => $scope->isSuperAdmin() ? $this->settingsBranchesPayload() : [],
        ];

        return Inertia::render('Admin/Settings', $props);
    }

    public function update(Request $request)
    {
        // Compatibility for a browser bundle deployed before the split
        // routes. The route itself accepts only an old `settings.update`
        // grant; new profiles use one of the scoped methods below.
        $branch = $this->settingsBranch($request);
        if ($branch) {
            // A pre-split browser bundle posts the legal fields together with
            // the editable content. Drop them before validating; they can
            // never become branch data or overwrite the platform documents.
            $this->removeLegalContentFromRequest($request);
        } elseif (! $request->user()?->isSuperAdmin()) {
            $this->preservePlatformLegalContentInRequest($request);
        }

        $data = $request->validate(array_merge(
            $this->brandingRules(),
            $this->supportRules(),
            $this->financialDefaultsRules(),
            $this->timingRules(),
            $branch ? $this->branchPublicContentRules() : $this->publicContentRules(),
        ));

        if ($branch) {
            $this->saveBranchBranding($request, $branch, $data);
            $this->saveBranchSupport($branch, $data);
            $this->saveBranchFinancialDefaults($branch, $data);
            $this->saveBranchTiming($branch, $data);
            $this->saveBranchPublicContent($branch, $data);

            return back()->with('success', __('Settings saved successfully.'));
        }

        $this->saveBranding($request, $data);
        $this->saveSupport($data);
        $this->saveFinancialDefaults($data);
        $this->saveTiming($data);
        $this->savePublicContent($data);

        return back()->with('success', __('Settings saved successfully.'));
    }

    public function updateBranding(Request $request)
    {
        $data = $request->validate($this->brandingRules());
        $branch = $this->settingsBranch($request);
        if ($branch) {
            $this->saveBranchBranding($request, $branch, $data);

            return back()->with('success', __('Settings saved successfully.'));
        }

        $this->saveBranding($request, $data);

        return back()->with('success', __('Settings saved successfully.'));
    }

    public function updateSupport(Request $request)
    {
        $data = $request->validate($this->supportRules());
        $branch = $this->settingsBranch($request);
        if ($branch) {
            $this->saveBranchSupport($branch, $data);

            return back()->with('success', __('Settings saved successfully.'));
        }

        $this->saveSupport($data);

        return back()->with('success', __('Settings saved successfully.'));
    }

    public function updateFinancialDefaults(Request $request)
    {
        $data = $request->validate($this->financialDefaultsRules());
        $branch = $this->settingsBranch($request);
        if ($branch) {
            $this->saveBranchFinancialDefaults($branch, $data);

            return back()->with('success', __('Settings saved successfully.'));
        }

        $this->saveFinancialDefaults($data);

        return back()->with('success', __('Settings saved successfully.'));
    }

    public function updateCourierDeductionDefault(Request $request)
    {
        $data = $request->validate($this->courierDeductionDefaultRules());
        $branch = $this->settingsBranch($request);
        if ($branch) {
            $this->saveBranchCourierDeductionDefault($branch, $data);

            return back()->with('success', __('Settings saved successfully.'));
        }

        $this->saveCourierDeductionDefault($data);

        return back()->with('success', __('Settings saved successfully.'));
    }

    public function updateTiming(Request $request)
    {
        $data = $request->validate($this->timingRules());
        $branch = $this->settingsBranch($request);
        if ($branch) {
            $this->saveBranchTiming($branch, $data);

            return back()->with('success', __('Settings saved successfully.'));
        }

        $this->saveTiming($data);

        return back()->with('success', __('Settings saved successfully.'));
    }

    public function updatePublicContent(Request $request)
    {
        $branch = $this->settingsBranch($request);
        if (! $branch && ! $request->user()?->isSuperAdmin()) {
            // This is repeated server-side so a hand-crafted request cannot
            // turn a public-content permission into legal-document control.
            // Preserve the current legal text instead of leaving missing
            // fields to be normalised as empty strings.
            $this->preservePlatformLegalContentInRequest($request);
        }
        $data = $request->validate($branch ? $this->branchPublicContentRules() : $this->publicContentRules());
        if ($branch) {
            $this->saveBranchPublicContent($branch, $data);

            return back()->with('success', __('Settings saved successfully.'));
        }

        $this->savePublicContent($data);

        return back()->with('success', __('Settings saved successfully.'));
    }

    /** @return array<string, array<int, string>> */
    private function brandingRules(): array
    {
        return [
            'brand_name' => ['required', 'string', 'max:80'],
            'brand_tagline' => ['nullable', 'string', 'max:120'],
            'logo' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:3072'],
        ];
    }

    /** @return array<string, array<int, string>> */
    private function supportRules(): array
    {
        return [
            'support_phone' => ['nullable', 'string', 'max:30'],
            'support_email' => ['nullable', 'email', 'max:120'],
            'currency' => ['required', 'string', 'max:10'],
        ];
    }

    /** @return array<string, array<int, string>> */
    private function financialDefaultsRules(): array
    {
        return [
            'delivery_fee' => ['required', 'integer', 'min:0', 'max:1000000'],
        ];
    }

    /** @return array<string, array<int, string>> */
    private function courierDeductionDefaultRules(): array
    {
        return [
            'admin_deduction_fee' => ['required', 'integer', 'min:0', 'max:1000000'],
        ];
    }

    /** @return array<string, array<int, string>> */
    private function timingRules(): array
    {
        return [
            'order_expiry_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'pickup_eta_minutes' => ['required', 'integer', 'min:5', 'max:240'],
        ];
    }

    /** @return array<string, array<int, string>> */
    private function publicContentRules(): array
    {
        return [
            'public_content' => ['nullable', 'array'],
            'public_content.about_app' => ['nullable', 'array'],
            'public_content.developer_name' => ['nullable', 'array'],
            'public_content.developer_description' => ['nullable', 'array'],
            'public_content.privacy_policy' => ['nullable', 'array'],
            'public_content.terms_of_use' => ['nullable', 'array'],
            'public_content.about_app.ar' => ['nullable', 'string', 'max:2000'],
            'public_content.about_app.en' => ['nullable', 'string', 'max:2000'],
            'public_content.about_app.ku' => ['nullable', 'string', 'max:2000'],
            'public_content.developer_name.ar' => ['nullable', 'string', 'max:160'],
            'public_content.developer_name.en' => ['nullable', 'string', 'max:160'],
            'public_content.developer_name.ku' => ['nullable', 'string', 'max:160'],
            'public_content.developer_description.ar' => ['nullable', 'string', 'max:2000'],
            'public_content.developer_description.en' => ['nullable', 'string', 'max:2000'],
            'public_content.developer_description.ku' => ['nullable', 'string', 'max:2000'],
            'public_content.privacy_policy.ar' => ['nullable', 'string', 'max:20000'],
            'public_content.privacy_policy.en' => ['nullable', 'string', 'max:20000'],
            'public_content.privacy_policy.ku' => ['nullable', 'string', 'max:20000'],
            'public_content.terms_of_use.ar' => ['nullable', 'string', 'max:20000'],
            'public_content.terms_of_use.en' => ['nullable', 'string', 'max:20000'],
            'public_content.terms_of_use.ku' => ['nullable', 'string', 'max:20000'],
        ];
    }

    /**
     * Privacy policy and terms are intentionally absent from the branch
     * schema. They are public legal documents, so one platform copy remains
     * the source of truth for all governorates and branches.
     *
     * @return array<string, array<int, string>>
     */
    private function branchPublicContentRules(): array
    {
        return [
            'public_content' => ['nullable', 'array'],
            'public_content.about_app' => ['nullable', 'array'],
            'public_content.developer_name' => ['nullable', 'array'],
            'public_content.developer_description' => ['nullable', 'array'],
            'public_content.privacy_policy' => ['prohibited'],
            'public_content.terms_of_use' => ['prohibited'],
            'public_content.about_app.ar' => ['nullable', 'string', 'max:2000'],
            'public_content.about_app.en' => ['nullable', 'string', 'max:2000'],
            'public_content.about_app.ku' => ['nullable', 'string', 'max:2000'],
            'public_content.developer_name.ar' => ['nullable', 'string', 'max:160'],
            'public_content.developer_name.en' => ['nullable', 'string', 'max:160'],
            'public_content.developer_name.ku' => ['nullable', 'string', 'max:160'],
            'public_content.developer_description.ar' => ['nullable', 'string', 'max:2000'],
            'public_content.developer_description.en' => ['nullable', 'string', 'max:2000'],
            'public_content.developer_description.ku' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /** @param array<string, mixed> $data */
    private function saveBranding(Request $request, array $data): void
    {
        $branding = Setting::branding();
        $branding['name'] = trim((string) $data['brand_name']);
        $branding['tagline'] = trim((string) ($data['brand_tagline'] ?? ''));

        if ($request->hasFile('logo')) {
            $oldPath = $branding['logo_path'] ?? null;
            $branding['logo_path'] = $request->file('logo')->store('branding', 'public');

            // Never delete the bundled /public/logo.png fallback. Only remove
            // a previous administrator-uploaded branding file after the new
            // file has been written successfully.
            if (is_string($oldPath) && str_starts_with($oldPath, 'branding/')) {
                Storage::disk('public')->delete($oldPath);
            }
        }

        Setting::set(Setting::BRANDING_KEY, $branding);
    }

    /** @param array<string, mixed> $data */
    private function saveSupport(array $data): void
    {
        foreach (['support_phone', 'support_email', 'currency'] as $key) {
            Setting::set($key, $data[$key] ?? null);
        }
    }

    /** @param array<string, mixed> $data */
    private function saveFinancialDefaults(array $data): void
    {
        Setting::set('delivery_fee', $data['delivery_fee']);
    }

    /** @param array<string, mixed> $data */
    private function saveCourierDeductionDefault(array $data): void
    {
        Setting::set('admin_deduction_fee', $data['admin_deduction_fee']);
    }

    /** @param array<string, mixed> $data */
    private function saveTiming(array $data): void
    {
        Setting::set('order_expiry_minutes', $data['order_expiry_minutes']);
        Setting::set('pickup_eta_minutes', $data['pickup_eta_minutes']);
    }

    /** @param array<string, mixed> $data */
    private function savePublicContent(array $data): void
    {
        // A missing key must not overwrite the currently configured text.
        if (! array_key_exists('public_content', $data)) {
            return;
        }

        Setting::set(
            Setting::PUBLIC_CONTENT_KEY,
            Setting::normalizePublicContent($data['public_content'] ?? []),
        );
    }

    /** @param array<string, mixed> $data */
    private function saveBranchBranding(Request $request, Branch $branch, array $data): void
    {
        $resolver = app(BranchSettingsResolver::class);
        $branding = $resolver->hasOverride($branch, Setting::BRANDING_KEY)
            ? $resolver->get($branch, Setting::BRANDING_KEY, [])
            : [];
        $branding = is_array($branding) ? $branding : [];

        $branding['name'] = trim((string) $data['brand_name']);
        $branding['tagline'] = trim((string) ($data['brand_tagline'] ?? ''));

        if ($request->hasFile('logo')) {
            $oldPath = $branding['logo_path'] ?? null;
            $branding['logo_path'] = $request->file('logo')->store('branch-branding/'.$branch->getKey(), 'public');

            // A branch can only clean up its own upload. A fallback platform
            // logo is never a candidate for deletion here.
            $branchLogoPrefix = 'branch-branding/'.$branch->getKey().'/';
            if (is_string($oldPath) && str_starts_with($oldPath, $branchLogoPrefix)) {
                Storage::disk('public')->delete($oldPath);
            }
        }

        $resolver->set($branch, Setting::BRANDING_KEY, $branding);
    }

    /** @param array<string, mixed> $data */
    private function saveBranchSupport(Branch $branch, array $data): void
    {
        $resolver = app(BranchSettingsResolver::class);

        foreach (['support_phone', 'support_email', 'currency'] as $key) {
            $resolver->set($branch, $key, $data[$key] ?? null);
        }
    }

    /** @param array<string, mixed> $data */
    private function saveBranchFinancialDefaults(Branch $branch, array $data): void
    {
        app(BranchSettingsResolver::class)->set($branch, 'delivery_fee', $data['delivery_fee']);
    }

    /** @param array<string, mixed> $data */
    private function saveBranchCourierDeductionDefault(Branch $branch, array $data): void
    {
        app(BranchSettingsResolver::class)->set($branch, 'admin_deduction_fee', $data['admin_deduction_fee']);
    }

    /** @param array<string, mixed> $data */
    private function saveBranchTiming(Branch $branch, array $data): void
    {
        $resolver = app(BranchSettingsResolver::class);
        $resolver->set($branch, 'order_expiry_minutes', $data['order_expiry_minutes']);
        $resolver->set($branch, 'pickup_eta_minutes', $data['pickup_eta_minutes']);
    }

    /** @param array<string, mixed> $data */
    private function saveBranchPublicContent(Branch $branch, array $data): void
    {
        if (! array_key_exists('public_content', $data)) {
            return;
        }

        $resolver = app(BranchSettingsResolver::class);
        $content = $resolver->normalizeLocalPublicContent($data['public_content'] ?? []);

        if ($content === []) {
            $resolver->forget($branch, Setting::PUBLIC_CONTENT_KEY);

            return;
        }

        $resolver->set($branch, Setting::PUBLIC_CONTENT_KEY, $content);
    }

    /**
     * Resolve a branch-settings target exclusively from a server-owned
     * branch membership, or from a super administrator's validated branch
     * selection. A branch id submitted by any other account is never a
     * source of authority.
     */
    private function settingsBranch(Request $request): ?Branch
    {
        $scope = app(BranchDashboardContext::class)->fromRequest($request);

        if ($scope->requiresBranchScope()) {
            abort_unless($scope->hasBranchScope(), 403);

            $branch = $this->branchFromSettingsScope($scope);
            if ($request->filled('branch_id')) {
                $requestedBranchId = $request->input('branch_id');
                abort_unless(
                    is_scalar($requestedBranchId)
                        && ctype_digit((string) $requestedBranchId)
                        && (int) $requestedBranchId === (int) $branch->id,
                    403,
                );
            }

            return $branch;
        }

        return $this->selectedPlatformSettingsBranch($request);
    }

    private function selectedPlatformSettingsBranch(Request $request): ?Branch
    {
        if (! $request->filled('branch_id')) {
            return null;
        }

        abort_unless($request->user()?->isSuperAdmin(), 403);
        $branchId = $request->input('branch_id');
        abort_unless(is_scalar($branchId) && ctype_digit((string) $branchId) && (int) $branchId > 0, 404);

        return Branch::withoutGlobalScopes()
            ->whereKey((int) $branchId)
            ->where('tenant_id', Tenant::platform()->id)
            ->where('is_platform_managed', true)
            ->whereNull('deleted_at')
            ->firstOrFail();
    }

    private function branchFromSettingsScope(BranchDashboardScope $scope): Branch
    {
        $branch = $scope->branch();
        abort_unless($branch instanceof Branch, 403);

        return $branch;
    }

    private function removeLegalContentFromRequest(Request $request): void
    {
        $content = $request->input('public_content');
        if (! is_array($content)) {
            return;
        }

        unset($content['privacy_policy'], $content['terms_of_use']);
        $request->merge(['public_content' => $content]);
    }

    /**
     * A delegated platform operator may update About/Developer content but
     * not legal documents. Replace any browser-supplied legal values with
     * the authoritative stored copy before validation and persistence.
     */
    private function preservePlatformLegalContentInRequest(Request $request): void
    {
        $content = $request->input('public_content');
        if (! is_array($content)) {
            return;
        }

        $current = Setting::publicContent();
        $content['privacy_policy'] = $current['privacy_policy'];
        $content['terms_of_use'] = $current['terms_of_use'];
        $request->merge(['public_content' => $content]);
    }

    /** @return array{name:string,tagline:string,logo_url:string,has_custom_logo:bool} */
    private function brandingPayload(): array
    {
        $branding = Setting::branding();
        $logoPath = $branding['logo_path'] ?? null;

        return [
            'name' => $branding['name'],
            'tagline' => $branding['tagline'],
            'logo_url' => is_string($logoPath) && $logoPath !== ''
                ? Storage::disk('public')->url($logoPath)
                : asset('logo.png'),
            'has_custom_logo' => is_string($logoPath) && $logoPath !== '',
        ];
    }

    /** @return array{name:string,tagline:string,logo_url:string,has_custom_logo:bool} */
    private function branchBrandingPayload(Branch $branch, BranchSettingsResolver $resolver): array
    {
        $branding = $resolver->branding($branch);
        $logoPath = $branding['logo_path'] ?? null;

        return [
            'name' => $branding['name'],
            'tagline' => $branding['tagline'],
            'logo_url' => is_string($logoPath) && $logoPath !== ''
                ? Storage::disk('public')->url($logoPath)
                : asset('logo.png'),
            'has_custom_logo' => is_string($logoPath) && $logoPath !== '',
        ];
    }

    /** @return array<string, mixed> */
    private function settingsPayload(bool $canViewLoyalty, bool $canUpdateCourierDeductionDefault): array
    {
        $settings = [
            'support_phone' => Setting::get('support_phone', ''),
            'support_email' => Setting::get('support_email', ''),
            'currency' => Setting::get('currency', 'IQD'),
            'delivery_fee' => (int) Setting::get('delivery_fee', 0),
            'order_expiry_minutes' => (int) Setting::get('order_expiry_minutes', 30),
            'pickup_eta_minutes' => (int) Setting::get('pickup_eta_minutes', 30),
            'public_content' => Setting::publicContent(),
        ];

        if ($canViewLoyalty) {
            $settings['points_per_delivery'] = max(0, (int) Setting::get(LoyaltyPointService::POINTS_PER_DELIVERY_KEY, 10));
        }

        // This amount affects each courier's settlement. It is intentionally
        // omitted even from a general settings viewer unless the dedicated
        // mutation capability was explicitly granted.
        if ($canUpdateCourierDeductionDefault) {
            $settings['admin_deduction_fee'] = (int) Setting::get('admin_deduction_fee', 0);
        }

        return $settings;
    }

    /** @return array<string, mixed> */
    private function branchSettingsPayload(Branch $branch, BranchSettingsResolver $resolver, bool $canUpdateCourierDeductionDefault): array
    {
        $publicContent = $resolver->publicContent($branch);
        // Do not serialise platform legal documents into a branch dashboard
        // response. They remain visible and editable only in the platform
        // settings scope.
        unset($publicContent['privacy_policy'], $publicContent['terms_of_use']);

        $settings = [
            'support_phone' => $resolver->get($branch, 'support_phone', ''),
            'support_email' => $resolver->get($branch, 'support_email', ''),
            'currency' => $resolver->get($branch, 'currency', 'IQD'),
            'delivery_fee' => (int) $resolver->get($branch, 'delivery_fee', 0),
            'order_expiry_minutes' => (int) $resolver->get($branch, 'order_expiry_minutes', 30),
            'pickup_eta_minutes' => (int) $resolver->get($branch, 'pickup_eta_minutes', 30),
            'public_content' => $publicContent,
        ];

        if ($canUpdateCourierDeductionDefault) {
            $settings['admin_deduction_fee'] = (int) $resolver->get($branch, 'admin_deduction_fee', 0);
        }

        return $settings;
    }

    /** @return array<string, array<string, string>> */
    private function emptyBranchPublicContent(): array
    {
        $content = [];

        foreach (BranchSettingsResolver::LOCAL_PUBLIC_CONTENT_FIELDS as $field) {
            $content[$field] = array_fill_keys(BranchSettingsResolver::CONTENT_LOCALES, '');
        }

        return $content;
    }

    /** @return array<int, array<string, int|string|bool|null>> */
    private function provincesPayload(): array
    {
        return Province::platform()
            ->withCount('branches')
            ->orderBy('sort_order')
            ->orderBy('name_ar')
            ->get(['id', 'name_ar', 'name_en', 'name_ku', 'sort_order', 'is_active'])
            ->map(fn (Province $province) => [
                'id' => $province->id,
                'name_ar' => $province->name_ar,
                'name_en' => $province->name_en,
                'name_ku' => $province->name_ku,
                'sort_order' => (int) $province->sort_order,
                'is_active' => (bool) $province->is_active,
                'branches_count' => (int) $province->branches_count,
            ])
            ->values()
            ->all();
    }

    /** @return array<int, array{id:int, name_ar:string, name_en:?string, name_ku:?string, city:?string}> */
    private function sliderBranchesPayload(): array
    {
        return Branch::withoutGlobalScopes()
            ->where('is_platform_managed', true)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->orderBy('name_ar')
            ->get(['id', 'name_ar', 'name_en', 'name_ku', 'city'])
            ->map(fn (Branch $branch) => [
                'id' => $branch->id,
                'name_ar' => $branch->name_ar,
                'name_en' => $branch->name_en,
                'name_ku' => $branch->name_ku,
                'city' => $branch->city,
            ])
            ->values()
            ->all();
    }

    /** @return array<int, array{id:int, name_ar:string, name_en:?string, name_ku:?string, is_active:bool}> */
    private function settingsBranchesPayload(): array
    {
        return Branch::withoutGlobalScopes()
            ->where('tenant_id', Tenant::platform()->id)
            ->where('is_platform_managed', true)
            // Disabled branches remain available for a super-admin audit,
            // but a soft-deleted branch is never a valid settings target.
            ->whereNull('deleted_at')
            ->orderByDesc('is_active')
            ->orderBy('name_ar')
            ->get(['id', 'name_ar', 'name_en', 'name_ku', 'is_active'])
            ->map(fn (Branch $branch) => [
                'id' => $branch->id,
                'name_ar' => $branch->name_ar,
                'name_en' => $branch->name_en,
                'name_ku' => $branch->name_ku,
                'is_active' => (bool) $branch->is_active,
            ])
            ->values()
            ->all();
    }
}
