<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MobileSlide;
use App\Models\Province;
use App\Models\Setting;
use App\Services\LoyaltyPointService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class AdminSettingsController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $canViewContent = $user->canUseAdminPermission('content', 'view');
        $canViewLoyalty = $user->canUseAdminPermission('loyalty', 'view');
        $canViewProvinces = $user->canUseAdminPermission('settings', 'view');
        $canManageProvinces = $user->canUseAdminPermission('settings', 'update');

        $props = [
            'branding' => $this->brandingPayload(),
            'settings' => $this->settingsPayload($canViewLoyalty),
            'canUpdateSettings' => $user->canUseAdminPermission('settings', 'update'),
            // Governorates are reference data owned by Settings. Listing
            // them is safe for a Settings viewer, while mutations use the
            // existing Settings update permission on their own routes.
            'provinces' => $canViewProvinces ? $this->provincesPayload() : [],
            'canViewProvinces' => $canViewProvinces,
            'canCreateProvinces' => $canManageProvinces,
            'canUpdateProvinces' => $canManageProvinces,
            'canViewContent' => $canViewContent,
            'canViewLoyalty' => $canViewLoyalty,
            'canUpdateLoyalty' => $user->canUseAdminPermission('loyalty', 'update'),
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
        }

        return Inertia::render('Admin/Settings', $props);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'brand_name' => ['required', 'string', 'max:80'],
            'brand_tagline' => ['nullable', 'string', 'max:120'],
            'logo' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:3072'],
            'support_phone' => ['nullable', 'string', 'max:30'],
            'support_email' => ['nullable', 'email', 'max:120'],
            'currency' => ['required', 'string', 'max:10'],
            'delivery_fee' => ['required', 'integer', 'min:0', 'max:1000000'],
            'order_expiry_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'pickup_eta_minutes' => ['required', 'integer', 'min:5', 'max:240'],
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
        ]);

        $branding = Setting::branding();
        $branding['name'] = trim($data['brand_name']);
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

        foreach ([
            'support_phone',
            'support_email',
            'currency',
            'delivery_fee',
            'order_expiry_minutes',
            'pickup_eta_minutes',
        ] as $key) {
            Setting::set($key, $data[$key] ?? null);
        }

        // Existing API callers can continue saving the operational settings
        // without this field.  The dashboard always submits it, while a
        // missing key must not overwrite prior public content.
        if (array_key_exists('public_content', $data)) {
            Setting::set(
                Setting::PUBLIC_CONTENT_KEY,
                Setting::normalizePublicContent($data['public_content'] ?? []),
            );
        }

        return back()->with('success', __('Settings saved successfully.'));
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

    /** @return array<string, mixed> */
    private function settingsPayload(bool $canViewLoyalty): array
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

        return $settings;
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
}
