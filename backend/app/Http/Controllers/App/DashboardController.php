<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\MobileSlide;
use App\Models\Order;
use App\Models\User;
use App\Services\CourierOrderAccess;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function app(Request $request)
    {
        $request->validate([
            // The courier home intentionally receives only a small first
            // page. Additional available jobs are fetched on demand instead
            // of serialising the entire regional queue on every app visit.
            'available_cursor' => ['nullable', 'string', 'max:1024'],
        ]);

        $user = $request->user();
        $isCourier = $user->isCourierRole();
        $available = $isCourier
            ? $this->availableOrders($user, $request->input('available_cursor'))
            : ['orders' => [], 'pagination' => ['next_cursor' => null, 'has_more' => false]];

        if ($request->expectsJson()) {
            abort_unless($isCourier, 403);

            return response()->json([
                'availableOrders' => $available['orders'],
                'availablePagination' => $available['pagination'],
            ]);
        }

        return Inertia::render($isCourier ? 'Mobile/CourierHome' : 'Mobile/MerchantHome', [
            'stats' => $this->statsFor($user, $isCourier),
            'recentOrders' => $this->recentOrders($user, $isCourier),
            'availableOrders' => $available['orders'],
            'availablePagination' => $available['pagination'],
            'heroSlides' => $this->heroSlides(
                $isCourier,
                (int) ($request->session()->get('operating_branch_id') ?: $user->branch_id),
            ),
        ]);
    }

    public function duty(Request $request)
    {
        abort_unless($request->user()->isCourierRole(), 403);

        $data = $request->validate(['is_online' => ['required', 'boolean']]);
        $request->user()->forceFill([
            'is_online' => $data['is_online'],
            'last_active_at' => now(),
        ])->save();

        return back()->with('success', $data['is_online'] ? 'تم تفعيل حالة الاستلام.' : 'تم إيقاف حالة الاستلام.');
    }

    protected function statsFor(User $user, bool $isCourier): array
    {
        if ($isCourier) {
            $today = today()->toDateString();
            $mine = app(CourierOrderAccess::class)->assigned($user);
            // Keep the home screen to one aggregate query.  Loading every
            // delivered order just to total price minus fee becomes very
            // expensive for an active courier as their history grows.
            $assignedStats = (clone $mine)
                ->selectRaw('COUNT(*) as total')
                ->selectRaw(
                    'COALESCE(SUM(CASE WHEN status = ? AND date = ? THEN CASE WHEN COALESCE(price, 0) > COALESCE(fee, 0) THEN COALESCE(price, 0) - COALESCE(fee, 0) ELSE 0 END ELSE 0 END), 0) as collected_today',
                    ['delivered', $today],
                )
                ->selectRaw(
                    'COALESCE(SUM(CASE WHEN status = ? AND date = ? THEN 1 ELSE 0 END), 0) as delivered_today',
                    ['delivered', $today],
                )
                ->selectRaw(
                    'COALESCE(SUM(CASE WHEN status IN (?, ?) THEN 1 ELSE 0 END), 0) as with_me',
                    ['approved', 'courier'],
                )
                ->selectRaw('COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END), 0) as delivered', ['delivered'])
                ->selectRaw('COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END), 0) as returned', ['returned'])
                ->toBase()
                ->first();
            $wallet = $user->wallet;

            return [
                // A courier collection is the delivery value after the
                // platform fee. The Qi wallet itself is debited at delivery
                // by the workflow service, while this figure reflects cash
                // the courier actually retains from completed jobs.
                'collectedToday' => (int) ($assignedStats->collected_today ?? 0),
                'deliveredToday' => (int) ($assignedStats->delivered_today ?? 0),
                'onDuty' => (bool) $user->is_online,
                'available' => app(CourierOrderAccess::class)->available($user)->count(),
                'withMe' => (int) ($assignedStats->with_me ?? 0),
                'delivered' => (int) ($assignedStats->delivered ?? 0),
                'returned' => (int) ($assignedStats->returned ?? 0),
                'walletBalance' => $wallet?->balance ?? 0,
                'budget' => $wallet?->budget ?? 0,
            ];
        }

        // Merchant status figures are displayed together, so calculate them
        // together instead of issuing one full count query per card.
        $today = today()->toDateString();
        $merchantStats = Order::query()
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END), 0) as pending', ['pending'])
            ->selectRaw('COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END), 0) as approved', ['approved'])
            ->selectRaw('COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END), 0) as courier', ['courier'])
            ->selectRaw('COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END), 0) as delivered', ['delivered'])
            ->selectRaw('COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END), 0) as returned', ['returned'])
            ->selectRaw('COALESCE(SUM(CASE WHEN date = ? THEN 1 ELSE 0 END), 0) as today', [$today])
            ->toBase()
            ->first();

        return [
            'total' => (int) ($merchantStats->total ?? 0),
            'pending' => (int) ($merchantStats->pending ?? 0),
            'approved' => (int) ($merchantStats->approved ?? 0),
            'courier' => (int) ($merchantStats->courier ?? 0),
            'delivered' => (int) ($merchantStats->delivered ?? 0),
            'returned' => (int) ($merchantStats->returned ?? 0),
            'today' => (int) ($merchantStats->today ?? 0),
            // Per-user wallet is the canonical merchant balance.  The old
            // tenant column is retained only for backwards-compatible data
            // exports and must never override an approved payout balance.
            'walletBalance' => $user->wallet?->balance ?? 0,
        ];
    }

    protected function recentOrders(User $user, bool $isCourier): array
    {
        $query = $isCourier
            ? app(CourierOrderAccess::class)->assigned($user)
            : Order::query();

        return $query->with([
            'courier:id,name,phone,vehicle,role',
            'pickupCourier:id,name,phone,vehicle,role',
            'deliveryCourier:id,name,phone,vehicle,role',
            'merchant:id,name,phone,address,shop_name,merchant_verified_at,role',
            'tenant:id,name',
        ])->latest('id')->limit(5)->get()->map(function (Order $o) use ($isCourier, $user): array {
            return [
            'id' => $o->id,
            'track_no' => $o->track_no,
            ...$this->localizedOrderCardText($o),
            'phone' => $o->phone,
            'phone_revealed' => true,
            'delivery_vehicle' => $o->delivery_vehicle,
            'price' => $o->price,
            'status' => $o->status,
            'date' => $o->date->toDateString(),
            'notes' => $o->notes,
            'vehicle_note' => $o->vehicle_note,
            'pickup_latitude' => $o->pickup_latitude === null ? null : (float) $o->pickup_latitude,
            'pickup_longitude' => $o->pickup_longitude === null ? null : (float) $o->pickup_longitude,
            'pickup_location_label' => $o->pickup_location_label,
            'pickup_deadline_at' => $o->pickup_deadline_at?->toIso8601String(),
            'courier' => $o->courier ? $this->courierPayload($o->courier) : null,
            'assigned_courier' => $this->assignedCourierPayload($o),
            'merchant' => $o->merchant
                ? [
                    'name' => $o->merchant->name,
                    'shop_name' => $o->merchant->shop_name,
                    'phone' => $o->merchant->phone,
                    'address' => $o->merchant->address,
                    'verified' => $o->merchant->isMerchantVerified(),
                ]
                : ($o->tenant ? ['name' => $o->tenant->name, 'phone' => null, 'address' => null] : null),
            ];
        })->all();
    }

    protected function availableOrders(User $user, ?string $cursor = null): array
    {
        $paginator = app(CourierOrderAccess::class)
            ->available($user)
            ->with(['tenant:id,name', 'merchant:id,name,phone,address,shop_name,merchant_verified_at,role'])
            ->latest('id')
            ->cursorPaginate(10, ['*'], 'available_cursor', $cursor);

        return [
            'orders' => $paginator->getCollection()
            ->map(fn (Order $order) => [
                'id' => $order->id,
                'track_no' => $order->track_no,
                ...$this->localizedOrderCardText($order),
                'phone' => $order->phone,
                'phone_revealed' => true,
                'order_type' => $order->order_type,
                'delivery_vehicle' => $order->delivery_vehicle ?: 'normal',
                'vehicle_note' => $order->vehicle_note,
                'price' => $order->price,
                'fee' => $order->fee,
                'notes' => $order->notes,
                'pickup_latitude' => $order->pickup_latitude === null ? null : (float) $order->pickup_latitude,
                'pickup_longitude' => $order->pickup_longitude === null ? null : (float) $order->pickup_longitude,
                'pickup_location_label' => $order->pickup_location_label,
                'merchant' => $order->merchant
                    ? [
                        'name' => $order->merchant->name,
                        'shop_name' => $order->merchant->shop_name,
                        'phone' => $order->merchant->phone,
                        'address' => $order->merchant->address,
                        'verified' => $order->merchant->isMerchantVerified(),
                    ]
                    : ($order->tenant ? ['name' => $order->tenant->name, 'phone' => null, 'address' => null] : null),
                'pickup_deadline_at' => $order->pickup_deadline_at?->toIso8601String(),
                'created_at' => $order->created_at?->toIso8601String(),
            ])
            ->values()
            ->all(),
            'pagination' => [
                'next_cursor' => $paginator->nextCursor()?->encode(),
                'has_more' => $paginator->nextCursor() !== null,
            ],
        ];
    }

    /**
     * The orders table stores Arabic and English customer-facing values.  The
     * mobile app uses English as the intentional Kurdish fallback until a
     * separately authored Kurdish field exists.  Exposing the resolved
     * values here matters because home-card payloads otherwise included only
     * Arabic while the full orders screen included both languages.
     *
     * @return array{customer_name_ar:string,customer_name_en:string,customer_name_ku:string,address_ar:string,address_en:string,address_ku:string}
     */
    protected function localizedOrderCardText(Order $order): array
    {
        $nameAr = (string) $order->customer_name_ar;
        $nameEn = filled($order->customer_name_en) ? (string) $order->customer_name_en : $nameAr;
        $addressAr = (string) $order->address_ar;
        $addressEn = filled($order->address_en) ? (string) $order->address_en : $addressAr;

        return [
            'customer_name_ar' => $nameAr,
            'customer_name_en' => $nameEn,
            // Kurdish falls back to the supplied English operational text,
            // matching HeroSlider and the rest of the app's locale policy.
            'customer_name_ku' => $nameEn,
            'address_ar' => $addressAr,
            'address_en' => $addressEn,
            'address_ku' => $addressEn,
        ];
    }

    /** @return array{name:string,phone:?string,vehicle:?string,role:?string}|null */
    protected function courierPayload(?User $courier): ?array
    {
        if (! $courier) {
            return null;
        }

        return [
            'name' => $courier->name,
            'phone' => $courier->phone,
            'vehicle' => $courier->vehicle,
            'role' => $courier->role,
        ];
    }

    /**
     * Merchant-facing cards must identify the courier who is operationally
     * responsible at the current stage, not merely the legacy primary
     * courier column. This mirrors the full Orders screen and chat target.
     *
     * @return array{name:string,phone:?string,vehicle:?string,role:?string}|null
     */
    protected function assignedCourierPayload(Order $order): ?array
    {
        $courier = match ($order->status) {
            'approved' => $order->pickupCourier ?: $order->courier ?: $order->deliveryCourier,
            'courier' => $order->deliveryCourier ?: $order->courier ?: $order->pickupCourier,
            default => $order->courier ?: $order->pickupCourier ?: $order->deliveryCourier,
        };

        return $this->courierPayload($courier);
    }

    protected function heroSlides(bool $isCourier, int $branchId = 0): array
    {
        $publishedSlides = MobileSlide::query()
            ->publishedFor($isCourier ? 'courier' : 'merchant', $branchId ?: null)
            ->get()
            ->map(fn (MobileSlide $slide) => $slide->mobilePayload())
            ->values()
            ->all();

        // New installations still have a useful, local-first hero area
        // before the operator publishes the first campaign. As soon as one
        // slide is created, the dashboard-controlled content is the only
        // source shown to the selected audience.
        if ($publishedSlides !== []) {
            return $publishedSlides;
        }

        if ($isCourier) {
            return [
                ['title_ar' => 'فعّل GPS لتوصيل أدق', 'title_en' => 'Enable GPS for accurate delivery', 'title_ku' => 'GPS چالاک بکە بۆ گەیاندنی وردتر', 'body_ar' => 'يساعد الفرع على معرفة آخر موقع تشاركه بموافقتك.', 'body_en' => 'It lets your branch use the last location you share with consent.', 'body_ku' => 'یارمەتی لقەکە دەدات گەشتەکەت بە ڕاستەوخۆ بەدواداچوون بکات', 'accent' => true, 'image_url' => null],
                ['title_ar' => 'أعلى تحصيل لك هذا الأسبوع', 'title_en' => 'Your best collection week yet', 'title_ku' => 'باشترین کۆکراوەت لەم هەفتەیە', 'body_ar' => 'تابع ميزانيتك وطلباتك من المحفظة والتقارير.', 'body_en' => 'Review your budget and jobs from Wallet and Reports.', 'body_ku' => 'بودجە و کارەکانت لە جزدان و ڕاپۆرتەکانەوە بەدواداچوون بکە.', 'accent' => true, 'image_url' => null],
            ];
        }

        return [
            ['title_ar' => 'تابع كل طلب لحظة بلحظة', 'title_en' => 'Track every order in real time', 'title_ku' => 'هەر داواکارییەک بە ڕاستەوخۆ بەدواداچوون بکە', 'body_ar' => 'أضف طلبك وحدد نقطة الاستلام الخاصة به، ثم تابع حالة الطلب.', 'body_en' => 'Create an order, set its pickup point, then follow its status.', 'body_ku' => 'لە تەبی داواکارییەکان شوێنی ڕاستەقینەی داواکارییەکەت بزانە', 'accent' => false, 'image_url' => null],
            ['title_ar' => 'إدارة طلباتك من مكان واحد', 'title_en' => 'Manage every order in one place', 'title_ku' => 'هەموو داواکارییەکانت لە یەک شوێن بەڕێوەببە', 'body_ar' => 'راجع الرصيد والتقارير والرسائل من التطبيق.', 'body_en' => 'Review balance, reports, and messages from the app.', 'body_ku' => 'باڵانس، ڕاپۆرت و نامەکانت لە ئەپەکەوە بپشکنە.', 'accent' => false, 'image_url' => null],
        ];
    }

}
