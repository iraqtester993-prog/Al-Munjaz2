<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class AdminSettingsController extends Controller
{
    public function index()
    {
        return Inertia::render('Admin/Settings', [
            'branding' => $this->brandingPayload(),
            'settings' => $this->settingsPayload(),
        ]);
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

    /** @return array<string, string|int|null> */
    private function settingsPayload(): array
    {
        return [
            'support_phone' => Setting::get('support_phone', ''),
            'support_email' => Setting::get('support_email', ''),
            'currency' => Setting::get('currency', 'IQD'),
            'delivery_fee' => (int) Setting::get('delivery_fee', 0),
            'order_expiry_minutes' => (int) Setting::get('order_expiry_minutes', 30),
            'pickup_eta_minutes' => (int) Setting::get('pickup_eta_minutes', 30),
        ];
    }
}
