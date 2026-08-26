<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\User;
use App\Services\CourierOrderAccess;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function app(Request $request)
    {
        $user = $request->user();
        $isCourier = $user->isCourierRole();

        return Inertia::render($isCourier ? 'Mobile/CourierHome' : 'Mobile/MerchantHome', [
            'stats' => $this->statsFor($user, $isCourier),
            'recentOrders' => $this->recentOrders($user, $isCourier),
            'availableOrders' => $isCourier ? $this->availableOrders($user) : [],
            'heroSlides' => $this->heroSlides($isCourier),
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
            $today = today();
            $mine = app(CourierOrderAccess::class)->assigned($user);
            $todayDelivered = (clone $mine)->where('status', 'delivered')->whereDate('date', $today)->get();
            $wallet = $user->wallet;

            return [
                'collectedToday' => $todayDelivered->sum('price'),
                'deliveredToday' => $todayDelivered->count(),
                'onDuty' => (bool) $user->is_online,
                'available' => app(CourierOrderAccess::class)->available($user)->count(),
                'withMe' => (clone $mine)->whereIn('status', ['approved', 'courier'])->count(),
                'delivered' => (clone $mine)->where('status', 'delivered')->count(),
                'returned' => (clone $mine)->where('status', 'returned')->count(),
                'walletBalance' => $wallet?->balance ?? 0,
                'budget' => $wallet?->budget ?? 0,
            ];
        }

        return [
            'total' => Order::query()->count(),
            'pending' => Order::query()->where('status', 'pending')->count(),
            'approved' => Order::query()->where('status', 'approved')->count(),
            'courier' => Order::query()->where('status', 'courier')->count(),
            'delivered' => Order::query()->where('status', 'delivered')->count(),
            'returned' => Order::query()->where('status', 'returned')->count(),
            'today' => Order::query()->whereDate('date', today())->count(),
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
            'courier:id,name,phone',
            'merchant:id,name,phone,address',
            'tenant:id,name',
        ])->latest('id')->limit(5)->get()->map(fn (Order $o) => [
            'id' => $o->id,
            'track_no' => $o->track_no,
            ...$this->localizedOrderCardText($o),
            'phone' => $o->phone,
            'delivery_vehicle' => $o->delivery_vehicle,
            'price' => $o->price,
            'status' => $o->status,
            'date' => $o->date->toDateString(),
            'notes' => $o->notes,
            'pickup_deadline_at' => $o->pickup_deadline_at?->toIso8601String(),
            'courier' => $o->courier ? ['name' => $o->courier->name, 'phone' => $o->courier->phone] : null,
            'merchant' => $o->merchant
                ? ['name' => $o->merchant->name, 'phone' => $o->merchant->phone, 'address' => $o->merchant->address]
                : ($o->tenant ? ['name' => $o->tenant->name, 'phone' => null, 'address' => null] : null),
        ])->all();
    }

    protected function availableOrders(User $user): array
    {
        return app(CourierOrderAccess::class)
            ->available($user)
            ->with(['tenant:id,name', 'merchant:id,name,phone,address'])
            ->latest('id')
            ->limit(12)
            ->get()
            ->map(fn (Order $order) => [
                'id' => $order->id,
                'track_no' => $order->track_no,
                ...$this->localizedOrderCardText($order),
                'phone' => $order->phone,
                'order_type' => $order->order_type,
                'delivery_vehicle' => $order->delivery_vehicle ?: 'normal',
                'vehicle_note' => $order->vehicle_note,
                'price' => $order->price,
                'fee' => $order->fee,
                'notes' => $order->notes,
                'merchant' => $order->merchant
                    ? ['name' => $order->merchant->name, 'phone' => $order->merchant->phone, 'address' => $order->merchant->address]
                    : ($order->tenant ? ['name' => $order->tenant->name, 'phone' => null, 'address' => null] : null),
                'pickup_deadline_at' => $order->pickup_deadline_at?->toIso8601String(),
                'created_at' => $order->created_at?->toIso8601String(),
            ])
            ->all();
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

    protected function heroSlides(bool $isCourier): array
    {
        if ($isCourier) {
            return [
                ['title_ar' => 'فعّل GPS لتوصيل أدق', 'title_en' => 'Enable GPS for accurate delivery', 'title_ku' => 'GPS چالاک بکە بۆ گەیاندنی وردتر', 'body_ar' => 'يساعد الفرع على تتبع رحلتك لحظياً', 'body_en' => 'Helps the branch track your trip live', 'body_ku' => 'یارمەتی لقەکە دەدات گەشتەکەت بە ڕاستەوخۆ بەدواداچوون بکات', 'tag_ar' => 'تطبيق المندوب', 'tag_en' => 'Courier App', 'tag_ku' => 'ئەپی گەیەنەر', 'accent' => true, 'image_url' => 'https://picsum.photos/seed/masar-c1/800/300'],
                ['title_ar' => 'أعلى تحصيل لك هذا الأسبوع', 'title_en' => 'Your best collection week yet', 'title_ku' => 'باشترین کۆکراوەت لەم هەفتەیە', 'body_ar' => '700,000 د.ع يوم الخميس — استمر بنفس الأداء', 'body_en' => '700,000 IQD on Thursday — keep it up', 'body_ku' => '٧٠٠,٠٠٠ د.ع ڕۆژی پێنجشەممە — بەردەوام بە هەمان ئەدا', 'tag_ar' => 'تطبيق المندوب', 'tag_en' => 'Courier App', 'tag_ku' => 'ئەپی گەیەنەر', 'accent' => true, 'image_url' => 'https://picsum.photos/seed/masar-c2/800/300'],
                ['title_ar' => 'سلّم النقدية قبل الساعة 6', 'title_en' => 'Hand over cash before 6 PM', 'title_ku' => 'پارەی نەقد پێش کاتژمێر ٦ تەسلیم بکە', 'body_ar' => 'لضمان إقفال صندوق الفرع بالوقت المحدد', 'body_en' => 'To ensure the branch cashbox closes on time', 'body_ku' => 'بۆ دڵنیابوون لە داخستنی سندوقی لق لە کاتی دیاریکراو', 'tag_ar' => 'تطبيق المندوب', 'tag_en' => 'Courier App', 'tag_ku' => 'ئەپی گەیەنەر', 'accent' => true, 'image_url' => 'https://picsum.photos/seed/masar-c3/800/300'],
            ];
        }

        return [
            ['title_ar' => 'تتبّع كل طلب لحظة بلحظة', 'title_en' => 'Track every order in real time', 'title_ku' => 'هەر داواکارییەک بە ڕاستەوخۆ بەدواداچوون بکە', 'body_ar' => 'اعرف مكان طلبك بالضبط من لوحة الطلبات', 'body_en' => 'Know exactly where your order is from the orders tab', 'body_ku' => 'لە تەبی داواکارییەکان شوێنی ڕاستەقینەی داواکارییەکەت بزانە', 'tag_ar' => 'تطبيق التاجر', 'tag_en' => 'Merchant App', 'tag_ku' => 'ئەپی بازرگان', 'accent' => false, 'image_url' => 'https://picsum.photos/seed/masar-t1/800/300'],
            ['title_ar' => 'عمولة أقل على الطلبات الكبيرة', 'title_en' => 'Lower fees on bulk orders', 'title_ku' => 'کرێی کەمتر بۆ داواکارییە زۆرەکان', 'body_ar' => 'أضف 20 طلب أو أكثر شهرياً واحصل على خصم تلقائي', 'body_en' => 'Add 20+ orders monthly and get an automatic discount', 'body_ku' => 'لە مانگێکدا ٢٠ داواکاری یان زیاتر زیاد بکە و داشکاندنی خۆکار وەر بگرە', 'tag_ar' => 'تطبيق التاجر', 'tag_en' => 'Merchant App', 'tag_ku' => 'ئەپی بازرگان', 'accent' => false, 'image_url' => 'https://picsum.photos/seed/masar-t2/800/300'],
            ['title_ar' => 'نسبة تسليمك 96% هذا الشهر', 'title_en' => 'Your delivery rate is 96% this month', 'title_ku' => 'ڕێژەی گەیاندنت ئەم مانگە ٩٦٪ە', 'body_ar' => 'من أفضل 10% من التجار على المنجز السريع', 'body_en' => "You're in the top 10% of merchants on Al-Munjaz Al-Saree", 'body_ku' => 'تۆ لە باشترین ١٠٪ی بازرگانانی ئەل‌موئەنجەز السەریعدایت', 'tag_ar' => 'تطبيق التاجر', 'tag_en' => 'Merchant App', 'tag_ku' => 'ئەپی بازرگان', 'accent' => false, 'image_url' => 'https://picsum.photos/seed/masar-t3/800/300'],
        ];
    }
}
