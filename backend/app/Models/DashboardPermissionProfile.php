<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A named, platform-wide dashboard permission set.
 *
 * The application's existing `role` field identifies the kind of account
 * (admin, merchant, courier, etc.).  It is intentionally not overloaded for
 * per-screen privileges: an admin account receives exactly one of these
 * profiles, while a super administrator remains the explicitly audited
 * break-glass account.
 */
class DashboardPermissionProfile extends Model
{
    /**
     * This is the complete allow-list of browser-dashboard capabilities.
     * Keep the action list truthful to a real route: the UI should never
     * render a checkbox that cannot affect an action in the application.
     *
     * @var array<string, array{name_ar: string, name_en: string, name_ku: string, actions: array<int, string>}>
     */
    public const MODULES = [
        'orders' => ['name_ar' => 'الطلبات', 'name_en' => 'Orders', 'name_ku' => 'داواکاریەکان', 'actions' => ['view', 'update']],
        'branches' => ['name_ar' => 'الفروع', 'name_en' => 'Branches', 'name_ku' => 'لقەکان', 'actions' => ['view', 'create', 'update']],
        'merchants' => ['name_ar' => 'التجار', 'name_en' => 'Merchants', 'name_ku' => 'بازرگانەکان', 'actions' => ['view', 'update', 'delete']],
        'couriers' => ['name_ar' => 'المندوبون', 'name_en' => 'Couriers', 'name_ku' => 'گەیەنەرەکان', 'actions' => ['view', 'update', 'delete']],
        'courier_locations' => ['name_ar' => 'مواقع المندوبين', 'name_en' => 'Courier locations', 'name_ku' => 'شوێنی گەیەنەرەکان', 'actions' => ['view']],
        'finance' => ['name_ar' => 'المالية', 'name_en' => 'Finance', 'name_ku' => 'دارایی', 'actions' => ['view', 'update']],
        'cashboxes' => ['name_ar' => 'الصناديق', 'name_en' => 'Cashboxes', 'name_ku' => 'سندوقەکان', 'actions' => ['view', 'create', 'update']],
        'pricing' => ['name_ar' => 'التسعير', 'name_en' => 'Pricing', 'name_ku' => 'نرخدانان', 'actions' => ['view', 'create', 'update']],
        'reports' => ['name_ar' => 'التقارير', 'name_en' => 'Reports', 'name_ku' => 'ڕاپۆرتەکان', 'actions' => ['view']],
        'platform' => ['name_ar' => 'إدارة المنصة', 'name_en' => 'Platform', 'name_ku' => 'بەڕێوەبردنی پلاتفۆرم', 'actions' => ['view', 'create', 'update']],
        'notifications' => ['name_ar' => 'الإشعارات', 'name_en' => 'Notifications', 'name_ku' => 'ئاگادارکردنەوەکان', 'actions' => ['view', 'create']],
        'settings' => ['name_ar' => 'الإعدادات', 'name_en' => 'Settings', 'name_ku' => 'ڕێکخستنەکان', 'actions' => ['view', 'update']],
        'content' => ['name_ar' => 'محتوى التطبيق', 'name_en' => 'Mobile content', 'name_ku' => 'ناوەڕۆکی ئەپ', 'actions' => ['view', 'create', 'update', 'delete']],
        'loyalty' => ['name_ar' => 'نقاط المندوبين', 'name_en' => 'Courier points', 'name_ku' => 'خاڵەکانی گەیەنەر', 'actions' => ['view', 'update']],
        'chat' => ['name_ar' => 'المحادثات', 'name_en' => 'Chat', 'name_ku' => 'گفتوگۆکان', 'actions' => ['view', 'create']],
        'transfers' => ['name_ar' => 'التحويلات', 'name_en' => 'Transfers', 'name_ku' => 'گواستنەوەکان', 'actions' => ['view', 'create', 'update']],
    ];

    /** @var array<string, array{ar: string, en: string, ku: string}> */
    public const ACTION_LABELS = [
        'view' => ['ar' => 'إظهار', 'en' => 'View', 'ku' => 'پیشاندان'],
        'create' => ['ar' => 'إنشاء', 'en' => 'Create', 'ku' => 'دروستکردن'],
        'update' => ['ar' => 'تعديل', 'en' => 'Update', 'ku' => 'دەستکاری'],
        'delete' => ['ar' => 'حذف', 'en' => 'Delete', 'ku' => 'سڕینەوە'],
    ];

    protected $fillable = [
        'name',
        'permissions',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'permissions' => 'array',
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'permission_profile_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Human-facing catalog for the dashboard.  Names are returned together
     * so the frontend does not need to hard-code an authorization matrix.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function catalog(): array
    {
        return collect(self::MODULES)
            ->map(function (array $module, string $key): array {
                return [
                    'key' => $key,
                    'name' => $module['name_ar'],
                    'name_ar' => $module['name_ar'],
                    'name_en' => $module['name_en'],
                    'name_ku' => $module['name_ku'],
                    'actions' => $module['actions'],
                    'action_labels' => collect($module['actions'])
                        ->mapWithKeys(fn (string $action) => [$action => self::ACTION_LABELS[$action]])
                        ->all(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Reduce arbitrary request input to the finite, server-owned permission
     * vocabulary.  It accepts both an action list (`['view', 'update']`) and
     * a checkbox map (`['view' => true, 'update' => false]`).
     *
     * @param  array<string, mixed>  $permissions
     * @return array<string, array<int, string>>
     */
    public static function normalizePermissions(array $permissions): array
    {
        $normalized = [];

        foreach ($permissions as $module => $actions) {
            if (! is_string($module) || ! isset(self::MODULES[$module]) || ! is_array($actions)) {
                continue;
            }

            $allowedActions = self::MODULES[$module]['actions'];
            $selected = [];

            foreach ($actions as $key => $value) {
                $action = is_int($key) ? $value : $key;
                $enabled = is_int($key) || filter_var($value, FILTER_VALIDATE_BOOLEAN);

                if (is_string($action) && $enabled && in_array($action, $allowedActions, true)) {
                    $selected[] = $action;
                }
            }

            if ($selected !== []) {
                $normalized[$module] = array_values(array_unique($selected));
            }
        }

        return $normalized;
    }

    public function allows(string $module, string $action): bool
    {
        if (! isset(self::MODULES[$module]) || ! in_array($action, self::MODULES[$module]['actions'], true)) {
            return false;
        }

        return in_array($action, $this->permissions[$module] ?? [], true);
    }
}
