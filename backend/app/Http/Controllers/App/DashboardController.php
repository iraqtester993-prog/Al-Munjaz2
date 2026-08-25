<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CourierOrderAccess;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function app(Request $request)
    {
        $user = $request->user();
        $isCourier = $user->role === 'courier';

        return Inertia::render($isCourier ? 'Mobile/CourierHome' : 'Mobile/MerchantHome', [
            'stats' => $this->statsFor($user, $isCourier),
            'recentOrders' => $this->recentOrders($user, $isCourier),
            'availableOrders' => $isCourier ? $this->availableOrders($user) : [],
            'heroSlides' => $this->heroSlides($isCourier),
        ]);
    }

    public function duty(Request $request)
    {
        abort_unless($request->user()->role === 'courier', 403);

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

        $tenant = TenantContext::tenant();

        return [
            'total' => Order::query()->count(),
            'pending' => Order::query()->where('status', 'pending')->count(),
            'approved' => Order::query()->where('status', 'approved')->count(),
            'courier' => Order::query()->where('status', 'courier')->count(),
            'delivered' => Order::query()->where('status', 'delivered')->count(),
            'returned' => Order::query()->where('status', 'returned')->count(),
            'today' => Order::query()->whereDate('date', today())->count(),
            'walletBalance' => $tenant?->wallet_balance ?? ($user->wallet?->balance ?? 0),
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
            'customer_name_ar' => $o->customer_name_ar,
            'phone' => $o->phone,
            'address_ar' => $o->address_ar,
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
                'customer_name_ar' => $order->customer_name_ar,
                'phone' => $order->phone,
                'address_ar' => $order->address_ar,
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

    protected function heroSlides(bool $isCourier): array
    {
        if ($isCourier) {
            return [
                ['title_ar' => 'فعّل GPS لتوصيل أدق', 'title_en' => 'Enable GPS for accurate delivery', 'body_ar' => 'يساعد الفرع على تتبع رحلتك لحظياً', 'body_en' => 'Helps the branch track your trip live', 'tag_ar' => 'تطبيق المندوب', 'tag_en' => 'Courier App', 'accent' => true, 'image_url' => 'https://picsum.photos/seed/masar-c1/800/300'],
                ['title_ar' => 'أعلى تحصيل لك هذا الأسبوع', 'title_en' => 'Your best collection week yet', 'body_ar' => '700,000 د.ع يوم الخميس — استمر بنفس الأداء', 'body_en' => '700,000 IQD on Thursday — keep it up', 'tag_ar' => 'تطبيق المندوب', 'tag_en' => 'Courier App', 'accent' => true, 'image_url' => 'https://picsum.photos/seed/masar-c2/800/300'],
                ['title_ar' => 'سلّم النقدية قبل الساعة 6', 'title_en' => 'Hand over cash before 6 PM', 'body_ar' => 'لضمان إقفال صندوق الفرع بالوقت المحدد', 'body_en' => 'To ensure the branch cashbox closes on time', 'tag_ar' => 'تطبيق المندوب', 'tag_en' => 'Courier App', 'accent' => true, 'image_url' => 'https://picsum.photos/seed/masar-c3/800/300'],
            ];
        }

        return [
            ['title_ar' => 'تتبّع كل طلب لحظة بلحظة', 'title_en' => 'Track every order in real time', 'body_ar' => 'اعرف مكان طلبك بالضبط من لوحة الطلبات', 'body_en' => 'Know exactly where your order is from the orders tab', 'tag_ar' => 'تطبيق التاجر', 'tag_en' => 'Merchant App', 'accent' => false, 'image_url' => 'https://picsum.photos/seed/masar-t1/800/300'],
            ['title_ar' => 'عمولة أقل على الطلبات الكبيرة', 'title_en' => 'Lower fees on bulk orders', 'body_ar' => 'أضف 20 طلب أو أكثر شهرياً واحصل على خصم تلقائي', 'body_en' => 'Add 20+ orders monthly and get an automatic discount', 'tag_ar' => 'تطبيق التاجر', 'tag_en' => 'Merchant App', 'accent' => false, 'image_url' => 'https://picsum.photos/seed/masar-t2/800/300'],
            ['title_ar' => 'نسبة تسليمك 96% هذا الشهر', 'title_en' => 'Your delivery rate is 96% this month', 'body_ar' => 'من أفضل 10% من التجار على المنجز السريع', 'body_en' => "You're in the top 10% of merchants on Al-Munjaz Al-Saree", 'tag_ar' => 'تطبيق التاجر', 'tag_en' => 'Merchant App', 'accent' => false, 'image_url' => 'https://picsum.photos/seed/masar-t3/800/300'],
        ];    }
}
