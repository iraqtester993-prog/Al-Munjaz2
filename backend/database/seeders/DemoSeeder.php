<?php

namespace Database\Seeders;

use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\Document;
use App\Models\Notification;
use App\Models\Order;
use App\Models\OrderStatusLog;
use App\Models\Plan;
use App\Models\Province;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Database\Seeder;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::updateOrCreate(
            ['username' => 'admin'],
            [
                'name' => 'مدير المنصة',
                'email' => 'admin@almunjaz.app',
                'phone' => '07900000000',
                'password' => '123456',
                'role' => 'admin',
                'status' => 'active',
            ]
        );

        $merchantTenant = Tenant::updateOrCreate(
            ['slug' => 'merchant-demo'],
            [
                'plan_id' => Plan::where('slug', 'basic')->value('id'),
                'name' => 'شركة المنفذ للتجارة',
                'kind' => 'merchant',
                'status' => 'active',
                'trial_ends_at' => now()->addDays(30),
                'wallet_balance' => 245000,
            ]
        );

        $merchant = User::updateOrCreate(
            ['username' => 'تاجر'],
            [
                'tenant_id' => $merchantTenant->id,
                'name' => 'التاجر',
                'phone' => '07710000001',
                'password' => '123456',
                'role' => 'merchant',
                'status' => 'active',
            ]
        );

        Wallet::updateOrCreate(['user_id' => $merchant->id], [
            'balance' => 245000,
            'budget' => 0,
        ]);

        $courierTenant = Tenant::updateOrCreate(
            ['slug' => 'courier-demo'],
            [
                'plan_id' => null,
                'name' => 'مكتب المندوبين',
                'kind' => 'courier',
                'status' => 'active',
                'wallet_balance' => 0,
            ]
        );

        $courier = User::updateOrCreate(
            ['username' => 'مندوب'],
            [
                'tenant_id' => $courierTenant->id,
                'name' => 'المندوب',
                'phone' => '07720000002',
                'password' => '123456',
                'role' => 'courier',
                'status' => 'active',
                'vehicle' => 'bike',
                'is_online' => true,
            ]
        );

        Wallet::updateOrCreate(['user_id' => $courier->id], [
            'balance' => 150000,
            'budget' => 500000,
        ]);

        // Demo accounts use Baghdad so the real courier visibility policy can
        // be exercised without bypassing province checks.
        if ($baghdad = Province::query()->where('name_ar', 'بغداد')->value('id')) {
            $merchant->provinces()->syncWithoutDetaching([$baghdad => ['is_primary' => true]]);
            $courier->provinces()->syncWithoutDetaching([$baghdad => ['is_primary' => true]]);
        }

        Branch::updateOrCreate(
            ['tenant_id' => $merchantTenant->id, 'name_ar' => 'الفرع الرئيسي'],
            ['name_en' => 'Main Branch', 'city' => 'بغداد']
        );

        Document::create([
            'user_id' => $courier->id,
            'type' => 'driving_license',
            'path' => 'documents/demo-license.jpg',
            'status' => 'approved',
        ]);

        Setting::set('currency', 'IQD');
        Setting::set('trial_days', 14);

        if (Order::query()->count() === 0) {
            $this->seedOrders($merchantTenant->id, $merchant->id, $courier->id);
        }
    }

    private function seedOrders(int $tenantId, int $merchantId, int $courierId): void
    {
        $demo = [
            ['احمد كريم', '07711234567', 'الكرادة - شارع الرشيد', 'بغداد', 'pending', 25000, 3000],
            ['سارة علي', '07722334455', 'زاخو - ساحة الحسين', 'دهوك', 'approved', 45000, 5000],
            ['محمد حسن', '07504567890', 'أربيل - عنكاوا', 'أربيل', 'courier', 18000, 2000],
            ['زينب جاسم', '07733445566', 'البصرة - العشار', 'البصرة', 'delivered', 62000, 8000],
            ['عمر فارس', '07809988776', 'الموصل - الدواسة', 'نينوى', 'delivered', 33000, 4000],
            ['ليلى سعد', '07744556677', 'النجف - حي السلام', 'النجف', 'returned', 21000, 2500],
        ];

        $date = today()->subDays(3);
        $statuses = ['pending' => 0, 'approved' => 1, 'courier' => 2, 'delivered' => 3, 'returned' => 4];
        $provinceIds = Province::query()->pluck('id', 'name_ar');

        foreach ($demo as $i => [$customer, $phone, $address, $city, $status, $price, $fee]) {
            $track = 'ALM-'.strtoupper(str_pad((string) (1000 + $i), 4, '0', STR_PAD_LEFT));

            $order = Order::create([
                'tenant_id' => $tenantId,
                'track_no' => $track,
                'source' => 'merchant',
                'customer_name_ar' => $customer,
                'customer_name_en' => $customer,
                'phone' => $phone,
                'address_ar' => $address,
                'address_en' => $address,
                'order_type' => $i % 2 === 0 ? 'منتج' : 'مستند',
                'delivery_vehicle' => $i % 2 === 0 ? 'normal' : 'suv',
                'price' => $price,
                'fee' => $fee,
                'status' => $status,
                'courier_id' => $status === 'pending' ? null : $courierId,
                'province_id' => $provinceIds[$city] ?? null,
                'date' => $date->copy()->addHours($i),
                'created_by' => $merchantId,
            ]);

            $times = [
                'approved' => ['col' => 'accepted_at', 'at' => $date->copy()->addHours($i)->addMinutes(40)],
                'courier' => ['col' => 'picked_at', 'at' => $date->copy()->addHours($i)->addMinutes(120)],
                'delivered' => ['col' => 'delivered_at', 'at' => $date->copy()->addHours($i)->addMinutes(300)],
                'returned' => ['col' => 'returned_at', 'at' => $date->copy()->addHours($i)->addMinutes(300)],
            ];

            $step = $statuses[$status];
            $flow = ['pending', 'approved', 'courier', 'delivered'];

            foreach (array_slice($flow, 0, $step) as $k => $s) {
                OrderStatusLog::create([
                    'tenant_id' => $tenantId,
                    'order_id' => $order->id,
                    'from_status' => $k === 0 ? null : $flow[$k - 1],
                    'to_status' => $s,
                    'user_id' => $merchantId,
                ]);
            }

            $attrs = ['status' => $status];
            if ($status !== 'pending' && isset($times[$status])) {
                $attrs[$times[$status]['col']] = $times[$status]['at'];
            }

            $order->update($attrs);

            if (in_array($status, ['delivered', 'returned'])) {
                $direction = $status === 'delivered' ? 1 : -1;
                $ref = $status === 'delivered' ? 'settlement' : 'returned';

                Transaction::create([
                    'tenant_id' => $tenantId,
                    'user_id' => $courierId,
                    'type' => $ref,
                    'amount' => $price,
                    'direction' => $direction,
                    'ref' => $track,
                    'order_id' => $order->id,
                    'date' => $date->copy()->addHours($i),
                    'note' => $status === 'delivered' ? 'تحصيل الطلب' : 'إرجاع الطلب',
                ]);

                if ($status === 'delivered') {
                    Transaction::create([
                        'tenant_id' => $tenantId,
                        'user_id' => $merchantId,
                        'type' => 'settlement',
                        'amount' => $price,
                        'direction' => 1,
                        'ref' => $track,
                        'order_id' => $order->id,
                        'date' => $date->copy()->addHours($i),
                        'note' => 'تسوية طلب',
                    ]);
                }
            }
        }

        $chat = Chat::create([
            'tenant_id' => $tenantId,
            'user_id' => $merchantId,
            'counterparty_type' => 'support',
            'title_ar' => 'الدعم الفني',
            'title_en' => 'Support',
            'last_message' => 'أهلاً بك في منصة المنفذ، كيف يمكننا مساعدتك؟',
            'last_at' => now(),
        ]);

        ChatMessage::create([
            'chat_id' => $chat->id,
            'sender_id' => $merchantId,
            'text' => 'السلام عليكم',
        ]);

        ChatMessage::create([
            'chat_id' => $chat->id,
            'sender_id' => null,
            'text' => 'أهلاً بك في منصة المنفذ، كيف يمكننا مساعدتك؟',
        ]);

        Notification::create([
            'tenant_id' => $tenantId,
            'user_id' => $merchantId,
            'type' => 'welcome',
            'title_ar' => 'مرحباً بك في المنفذ',
            'title_en' => 'Welcome to Al-Munjaz',
            'title_ku' => 'بەخێرهاتی بۆ منفذ',
            'body_ar' => 'تم تفعيل حسابك بنجاح. يمكنك الآن إدارة طلباتك ومحفظتك.',
            'body_en' => 'Your account is now active. You can manage orders and your wallet.',
            'body_ku' => 'هەژمارەکەت بە سەرکەوتوویی چالاک کرا.',
            'created_at' => now()->subHours(3),
        ]);

        Notification::create([
            'tenant_id' => $tenantId,
            'user_id' => $merchantId,
            'type' => 'order',
            'title_ar' => 'طلب جديد',
            'title_en' => 'New order',
            'title_ku' => 'داواکاری نوێ',
            'body_ar' => 'تم إضافة طلب جديد برقم التتبع ALM-1000.',
            'body_en' => 'A new order ALM-1000 was added.',
            'body_ku' => 'داواکاریەکی نوێ زیادکرا بە ژمارە ALM-1000.',
            'created_at' => now()->subHour(),
        ]);

        ActivityLog::create([
            'tenant_id' => $tenantId,
            'user_id' => $merchantId,
            'action' => 'account.activated',
            'subject_type' => 'user',
            'subject_id' => $merchantId,
            'data' => ['username' => 'تاجر'],
        ]);
    }
}
