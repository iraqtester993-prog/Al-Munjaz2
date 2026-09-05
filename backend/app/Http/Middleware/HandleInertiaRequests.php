<?php

namespace App\Http\Middleware;

use App\Models\Branch;
use App\Models\Chat;
use App\Models\FinanceRequest;
use App\Models\ActivityLog;
use App\Models\Notification;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use App\Services\BranchDashboardAuthorization;
use App\Services\BranchDashboardContext;
use App\Services\BranchSettingsResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Storage;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function share(Request $request): array
    {
        $userLocale = $request->user()?->locale ?? $request->session()->get('locale');
        if (in_array($userLocale, array_keys(config('app.locales')), true)) {
            App::setLocale($userLocale);
        }

        $authenticatedUser = $request->user();
        $operatingBranch = $authenticatedUser instanceof User
            ? $this->operatingBranchForUser($authenticatedUser)
            : null;
        $branchSettings = app(BranchSettingsResolver::class);
        $storedBranding = $operatingBranch
            ? $branchSettings->branding($operatingBranch)
            : Setting::branding();
        $developerContent = $operatingBranch
            ? $this->developerContentForBranch($operatingBranch, $branchSettings)
            : Setting::developerContent();
        $logoPath = $storedBranding['logo_path'] ?? null;
        $dashboardUser = $request->user();
        $branchDashboard = null;
        $branchPermissions = null;

        if ($dashboardUser instanceof User && $dashboardUser->role === 'branch_manager') {
            $branchScope = app(BranchDashboardContext::class)->scopeFor($dashboardUser);

            if ($branchScope->hasBranchScope()) {
                $branch = $branchScope->branch();
                $branchAuthorization = app(BranchDashboardAuthorization::class);
                $branchPermissions = $branchAuthorization->effectivePermissions($dashboardUser, $branchScope);
                $branchDashboard = [
                    'active' => true,
                    'is_principal_manager' => $branchAuthorization->isBranchManager($dashboardUser),
                    'branch' => [
                        'id' => $branch->id,
                        'name' => $branch->name_ar ?: ($branch->name_en ?: $branch->name_ku),
                        'name_ar' => $branch->name_ar,
                        'name_en' => $branch->name_en,
                        'name_ku' => $branch->name_ku,
                        'province_id' => $branch->province_id,
                    ],
                ];
            }
        }

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'locale' => App::getLocale(),
            // Client controls submit a locale code. Sending the display
            // metadata objects here made the dashboard select submit JSON
            // instead of `ar`, `en`, or `ku`.
            'locales' => array_keys(config('app.locales')),
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                // An invitation link is intentionally a one-time flash value:
                // the server only keeps its hash, so it cannot be recovered
                // from the database after the administrator leaves the page.
                'invite_link' => fn () => $request->session()->get('invite_link'),
                // New branch account credentials are intentionally a one-time
                // flash value. Only the encrypted password hash remains in
                // the database after the next navigation.
                'branch_credentials' => fn () => $request->session()->get('branch_credentials'),
            ],
            'auth' => [
                'user' => $request->user() ? [
                    'id' => $request->user()->id,
                    'name' => $request->user()->name,
                    'username' => $request->user()->username,
                    'phone' => $request->user()->phone,
                    'role' => $request->user()->role,
                    'status' => $request->user()->status,
                    'is_super_admin' => $request->user()->isSuperAdmin(),
                    // UI visibility is only a convenience. Route middleware
                    // independently enforces these same capabilities.
                    'admin_permissions' => $request->user()->isAdmin() && ! $request->user()->isSuperAdmin()
                        ? ($request->user()->permissionProfile?->permissions ?? [])
                        : $branchPermissions,
                    'permission_profile_id' => $request->user()->permission_profile_id,
                    'theme' => $request->user()->theme,
                    'locale' => $request->user()->locale,
                    'shop_name' => $request->user()->shop_name,
                    'address' => $request->user()->address,
                    // This is the merchant's fixed shop/pickup point.  It is
                    // shared only with its own authenticated profile and
                    // order form, never with another account's shell data.
                    // Each order still receives its own immutable snapshot
                    // when it is created.
                    'merchant_pickup_latitude' => $request->user()->role === 'merchant'
                        ? ($request->user()->merchant_pickup_latitude === null ? null : (float) $request->user()->merchant_pickup_latitude)
                        : null,
                    'merchant_pickup_longitude' => $request->user()->role === 'merchant'
                        ? ($request->user()->merchant_pickup_longitude === null ? null : (float) $request->user()->merchant_pickup_longitude)
                        : null,
                    'merchant_pickup_location_label' => $request->user()->role === 'merchant'
                        ? $request->user()->merchant_pickup_location_label
                        : null,
                    'vehicle' => $request->user()->vehicle,
                    'courier_verified' => $request->user()->role === 'courier'
                        ? $request->user()->isCourierVerified()
                        : null,
                    'phone_verified' => $request->user()->phone_verified_at !== null,
                ] : null,
                'tenant' => $request->user()?->tenant_id ? [
                    'id' => $request->user()->tenant_id,
                    'name' => $request->user()->tenant?->name,
                    'slug' => $request->user()->tenant?->slug,
                    'plan' => $request->user()->tenant?->plan?->slug,
                    'trial_ends_at' => $request->user()->tenant?->trial_ends_at?->toDateString(),
                ] : null,
                'provinces' => $request->user()
                    ? $request->user()->provinces()->orderBy('sort_order')->get(['provinces.id', 'name_ar', 'name_en', 'name_ku'])->map(fn ($province) => [
                        'id' => $province->id,
                        'name_ar' => $province->name_ar,
                        'name_en' => $province->name_en,
                        'name_ku' => $province->name_ku,
                    ])->all()
                    : [],
            ],
            // This is presentation metadata only; BranchDashboardScope is
            // rebuilt and enforced by middleware/controllers on every
            // request. It gives the shared AdminShell a truthful branch name
            // and lets a restricted local employee hide unavailable links.
            'branchDashboard' => $branchDashboard,
            // Keep the bell badge in the mobile shell live on every app
            // screen. Chat unread counts are intentionally separate.
            'notificationUnread' => function () use ($request): int {
                $user = $request->user();

                if (! $user || ($user->role !== 'merchant' && ! $user->isCourierRole())) {
                    return 0;
                }

                return Notification::query()
                    ->where(function ($query) use ($user) {
                        $query->where('user_id', $user->id)
                            ->orWhere(function ($tenant) use ($user) {
                                $tenant->whereNull('user_id')->where('tenant_id', $user->tenant_id);
                            });
                    })
                    ->whereNull('read_at')
                    ->count();
            },
            // Dashboard navigation needs real operational badges rather than
            // static decoration. They are only evaluated for an admin on the
            // administrative host and never expose another user's messages.
            'adminBadges' => function () use ($request): array {
                $user = $request->user();

                // Counts across all tenants are an aggregate dashboard data
                // surface, so do not expose them to a limited profile.
                if (! $user || ! $user->isSuperAdmin()) {
                    return [];
                }

                return [
                    'finance' => FinanceRequest::withoutGlobalScopes()->where('status', FinanceRequest::PENDING)->count(),
                    'notifications' => Notification::withoutGlobalScopes()->whereNull('read_at')->count(),
                    'chat' => Chat::withoutGlobalScopes()
                        ->adminSupportInbox()
                        ->whereNotNull('last_at')
                        ->whereNull('admin_read_at')
                        ->count(),
                ];
            },
            // The top-bar bell is an operational activity feed for the
            // platform owner.  Unlike campaign delivery counts, it shows a
            // readable summary of the action that actually happened.
            'dashboardActivityFeed' => function () use ($request): array {
                $user = $request->user();

                if (! $user || ! $user->isSuperAdmin()) {
                    return [];
                }

                return ActivityLog::query()
                    ->with('user:id,name')
                    ->latest('id')
                    ->limit(8)
                    ->get()
                    ->map(fn (ActivityLog $activity) => [
                        'id' => $activity->id,
                        'title' => $this->activityTitle($activity->action),
                        'detail' => $this->activityDetail($activity),
                        'actor' => $activity->user?->name,
                        'created_at' => $activity->created_at?->diffForHumans(),
                    ])
                    ->all();
            },
            // A locale dictionary contains more than a thousand strings. It
            // is required for a document visit (and directly after changing
            // language), but Inertia keeps the Vue runtime alive for normal
            // navigation. Sending it with every Inertia response made every
            // tab change and refresh unnecessarily large.
            //
            // `null` is intentional here: the client keeps its current
            // dictionary when this shared prop is absent. Locale-changing
            // actions set the one-request session flag below so the next
            // Inertia response still receives the new language immediately.
            'translations' => function () use ($request): ?array {
                $shouldShare = ! $request->header('X-Inertia')
                    || (bool) $request->session()->pull('inertia.translations.refresh', false);

                return $shouldShare && app()->bound('translations')
                    ? app('translations')
                    : null;
            },
            'branding' => [
                'name' => $storedBranding['name'],
                'tagline' => $storedBranding['tagline'],
                'logo_url' => is_string($logoPath) && $logoPath !== ''
                    ? Storage::disk('public')->url($logoPath)
                    : asset('logo.png'),
                'has_custom_logo' => is_string($logoPath) && $logoPath !== '',
            ],
            // Only the short About content is shared with all pages. Privacy
            // and terms bodies are loaded by their own public pages so long
            // legal copy does not slow the installed application.
            'developer' => $developerContent,
        ];
    }

    /**
     * A user can use a branch override only when their direct operational
     * branch assignment still points to a live platform branch. This keeps a
     * suspended, deleted, tenant-owned, or stale branch id from affecting
     * presentation data after the branch has left the delivery network.
     */
    private function operatingBranchForUser(User $user): ?Branch
    {
        $branchId = (int) $user->branch_id;
        if ($branchId <= 0) {
            return null;
        }

        return Branch::withoutGlobalScopes()
            ->whereKey($branchId)
            ->whereNull('deleted_at')
            ->where('tenant_id', Tenant::platform()->id)
            ->where('is_platform_managed', true)
            ->where('is_active', true)
            ->whereNotNull('province_id')
            ->whereColumn('active_platform_province_id', 'province_id')
            ->whereHas('province', fn ($province) => $province->platform()->active())
            ->first();
    }

    private function activityTitle(string $action): string
    {
        return match (true) {
            str_contains($action, 'document') => 'مراجعة وثيقة',
            str_contains($action, 'chat') || str_contains($action, 'message') => 'رسالة أو محادثة',
            str_contains($action, 'order') => 'حركة على طلب',
            str_contains($action, 'courier') => 'حركة مندوب',
            str_contains($action, 'merchant') => 'حركة تاجر',
            str_contains($action, 'finance') || str_contains($action, 'cashbox') => 'حركة مالية',
            str_contains($action, 'branch') => 'حركة فرع',
            str_contains($action, 'notification') => 'إشعار أو رسالة مرسلة',
            str_contains($action, 'settings') || str_contains($action, 'content') => 'تحديث الإعدادات',
            default => 'حركة في النظام',
        };
    }

    private function activityDetail(ActivityLog $activity): string
    {
        $data = $activity->data ?? [];
        $parts = [];

        foreach (['document_type', 'track_no', 'reason', 'status', 'verified'] as $key) {
            if (isset($data[$key]) && $data[$key] !== '') {
                $parts[] = is_bool($data[$key]) ? ($data[$key] ? 'نعم' : 'لا') : (string) $data[$key];
            }
        }

        return $parts ? implode(' · ', $parts) : str_replace(['.', '_'], [' · ', ' '], $activity->action);
    }

    /** @return array<string, array<string, string>> */
    private function developerContentForBranch(Branch $branch, BranchSettingsResolver $settings): array
    {
        $content = $settings->publicContent($branch);

        // Shared props intentionally keep long legal documents out of every
        // page response. Their one platform-wide copy remains available only
        // from the dedicated public legal routes.
        return [
            'about_app' => $content['about_app'],
            'developer_name' => $content['developer_name'],
            'developer_description' => $content['developer_description'],
        ];
    }
}
