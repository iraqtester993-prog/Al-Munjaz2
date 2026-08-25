<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\User;
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
            $mine = Order::query()->where('courier_id', $user->id);
            $todayDelivered = (clone $mine)->where('status', 'delivered')->whereDate('date', $today)->get();
            $wallet = $user->wallet;

            return [
                'collectedToday' => $todayDelivered->sum('price'),
                'deliveredToday' => $todayDelivered->count(),
                'onDuty' => (bool) $user->is_online,
                'available' => Order::query()->where('status', 'pending')->count(),
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
            ? Order::query()->where('courier_id', $user->id)
            : Order::query();

        return $query->with('courier:id,name,phone')->latest('id')->limit(5)->get()->map(fn (Order $o) => [
            'id' => $o->id,
            'track_no' => $o->track_no,
            'customer_name_ar' => $o->customer_name_ar,
            'price' => $o->price,
            'status' => $o->status,
            'date' => $o->date->toDateString(),
            'courier' => $o->courier ? ['name' => $o->courier->name, 'phone' => $o->courier->phone] : null,
        ])->all();
    }

    protected function heroSlides(bool $isCourier): array
    {
        if ($isCourier) {
            return [
                ['title_ar' => 'فعّل GPS لتوصيل أدق', 'title_en' => 'Enable GPS for accurate delivery', 'body_ar' => 'يساعد الفرع على تتبع رحلتك لحظياً', 'body_en' => 'Helps the branch track your trip live', 'tag_ar' => 'تطبيق المندوب', 'tag_en' => 'Courier App', 'accent' => true],
                ['title_ar' => 'سلّم النقدية قبل الساعة 6', 'title_en' => 'Hand over cash before 6 PM', 'body_ar' => 'لضمان إقفال صندوق الفرع بالوقت المحدد', 'body_en' => 'To ensure the branch cashbox closes on time', 'tag_ar' => 'تطبيق المندوب', 'tag_en' => 'Courier App', 'accent' => true],
            ];
        }

        return [
            ['title_ar' => 'تتبّع كل طلب لحظة بلحظة', 'title_en' => 'Track every order in real time', 'body_ar' => 'اعرف مكان طلبك بالضبط من لوحة الطلبات', 'body_en' => 'Know exactly where your order is from the orders tab', 'tag_ar' => 'تطبيق التاجر', 'tag_en' => 'Merchant App', 'accent' => false],
            ['title_ar' => 'عمولة أقل على الطلبات الكبيرة', 'title_en' => 'Lower fees on bulk orders', 'body_ar' => 'أضف 20 طلب أو أكثر شهرياً واحصل على خصم تلقائي', 'body_en' => 'Add 20+ orders monthly and get an automatic discount', 'tag_ar' => 'تطبيق التاجر', 'tag_en' => 'Merchant App', 'accent' => false],
        ];    }
}
