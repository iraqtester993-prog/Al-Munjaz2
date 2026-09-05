<?php

namespace Database\Seeders;

use App\Models\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Creates an isolated, idempotent operational dataset for capacity testing.
 *
 * Every generated record has the OPL-20260828 marker.  It deliberately uses
 * ordinary application tables and never truncates, migrates, or modifies an
 * existing customer record.  The marker makes the complete dataset auditable
 * and removable later with a separately reviewed maintenance operation.
 */
class OperationalLoadSeeder extends Seeder
{
    private const MARKER = 'OPL-20260828';

    private const MERCHANTS = 500;

    private const COURIERS = 1500;

    private const ORDERS = 25000;

    private const CONVERSATIONS = 2500;

    private const NOTIFICATIONS = 2500;

    public function run(): void
    {
        if (app()->environment('production') && env('OPERATIONAL_LOAD_CONFIRM') !== 'LIVE-20260828') {
            throw new \RuntimeException('Operational production load requires OPERATIONAL_LOAD_CONFIRM=LIVE-20260828.');
        }

        $now = now();
        $platform = Tenant::platform();
        $provinces = DB::table('provinces')->orderBy('sort_order')->get(['id', 'name_ar', 'name_en']);

        if ($provinces->isEmpty()) {
            throw new \RuntimeException('Cannot create operational load: provinces are missing.');
        }

        $this->seedBranches($platform->id, $provinces->all(), $now);
        $this->seedMerchants($provinces->all(), $now);
        $this->seedCouriers($platform->id, $provinces->all(), $now);

        $merchants = DB::table('users')
            ->join('tenants', 'tenants.id', '=', 'users.tenant_id')
            ->join('province_user', function ($join): void {
                $join->on('province_user.user_id', '=', 'users.id')
                    ->where('province_user.is_primary', true);
            })
            ->where('users.username', 'like', 'opl-merchant-%')
            ->orderBy('users.username')
            ->get(['users.id', 'users.tenant_id', 'users.name', 'province_user.province_id', 'tenants.slug']);
        $couriers = DB::table('users')
            ->where('username', 'like', 'opl-courier-%')
            ->orderBy('username')
            ->get(['id', 'tenant_id', 'branch_id', 'name']);
        $branches = DB::table('branches')
            ->where('tenant_id', $platform->id)
            ->where('code', 'like', 'OPL-%')
            ->orderBy('code')
            ->get(['id', 'province_id', 'city']);

        if ($merchants->count() !== self::MERCHANTS || $couriers->count() !== self::COURIERS || $branches->count() < 20) {
            throw new \RuntimeException('Operational load account preparation did not complete.');
        }

        $this->seedWallets($merchants->pluck('id')->all(), $couriers->pluck('id')->all(), $now);
        $this->seedOrders($merchants->all(), $couriers->all(), $branches->all(), $now);
        $this->seedChatsAndMessages($merchants->all(), $couriers->all(), $now);
        $this->seedNotifications($merchants->all(), $couriers->all(), $now);

        $this->command?->info(sprintf(
            'Operational load ready: %d merchants, %d couriers, %d orders, %d chats/messages, %d notifications.',
            self::MERCHANTS,
            self::COURIERS,
            self::ORDERS,
            self::CONVERSATIONS,
            self::NOTIFICATIONS,
        ));
    }

    /** @param array<int, object> $provinces */
    private function seedBranches(int $platformId, array $provinces, Carbon $now): void
    {
        $rows = [];
        for ($index = 1; $index <= 20; $index++) {
            $province = $provinces[($index - 1) % count($provinces)];
            $rows[] = [
                'tenant_id' => $platformId,
                'code' => sprintf('OPL-%02d', $index),
                'name_ar' => sprintf('فرع %s التشغيلي %d', $province->name_ar, (int) ceil($index / count($provinces))),
                'name_en' => sprintf('%s Operations %d', $province->name_en, (int) ceil($index / count($provinces))),
                'city' => $province->name_ar,
                'phone' => sprintf('07940%06d', $index),
                'address' => sprintf('مركز عمليات %s', $province->name_ar),
                'province_id' => $province->id,
                'cash_balance' => 0,
                'is_platform_managed' => true,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('branches')->upsert($rows, ['tenant_id', 'code'], [
            'name_ar', 'name_en', 'city', 'phone', 'address', 'province_id', 'is_platform_managed', 'is_active', 'updated_at',
        ]);
    }

    /** @param array<int, object> $provinces */
    private function seedMerchants(array $provinces, Carbon $now): void
    {
        $password = Hash::make(bin2hex(random_bytes(32)));
        $tenantRows = [];
        $userRows = [];

        for ($index = 1; $index <= self::MERCHANTS; $index++) {
            $tenantRows[] = [
                'slug' => sprintf('opl-merchant-%04d', $index),
                'name' => sprintf('تاجر تشغيل %04d', $index),
                'kind' => 'merchant',
                'status' => 'active',
                'trial_ends_at' => null,
                'wallet_balance' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($tenantRows, 500) as $chunk) {
            DB::table('tenants')->upsert($chunk, ['slug'], ['name', 'kind', 'status', 'wallet_balance', 'updated_at']);
        }

        $tenants = DB::table('tenants')->where('slug', 'like', 'opl-merchant-%')->pluck('id', 'slug');
        for ($index = 1; $index <= self::MERCHANTS; $index++) {
            $province = $provinces[($index - 1) % count($provinces)];
            $slug = sprintf('opl-merchant-%04d', $index);
            $userRows[] = [
                'tenant_id' => $tenants[$slug],
                'name' => sprintf('تاجر تشغيل %04d', $index),
                'username' => $slug,
                'phone' => sprintf('07950%06d', $index),
                'password' => $password,
                'role' => 'merchant',
                'status' => 'active',
                'shop_name' => sprintf('متجر التشغيل %04d', $index),
                'address' => sprintf('%s - مركز المدينة', $province->name_ar),
                'theme' => 'light',
                'locale' => 'ar',
                'is_online' => true,
                'last_active_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($userRows, 500) as $chunk) {
            DB::table('users')->upsert($chunk, ['username'], [
                'tenant_id', 'name', 'phone', 'password', 'role', 'status', 'shop_name', 'address', 'theme', 'locale', 'is_online', 'last_active_at', 'updated_at',
            ]);
        }

        $users = DB::table('users')->where('username', 'like', 'opl-merchant-%')->orderBy('username')->get(['id']);
        $provinceRows = [];
        foreach ($users as $offset => $user) {
            $provinceRows[] = [
                'user_id' => $user->id,
                'province_id' => $provinces[$offset % count($provinces)]->id,
                'is_primary' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        DB::table('province_user')->upsert($provinceRows, ['user_id', 'province_id'], ['is_primary', 'updated_at']);
    }

    /** @param array<int, object> $provinces */
    private function seedCouriers(int $platformId, array $provinces, Carbon $now): void
    {
        $password = Hash::make(bin2hex(random_bytes(32)));
        $branchesByProvince = DB::table('branches')
            ->where('tenant_id', $platformId)
            ->where('code', 'like', 'OPL-%')
            ->orderBy('code')
            ->get(['id', 'province_id'])
            ->groupBy('province_id');
        $userRows = [];

        for ($index = 1; $index <= self::COURIERS; $index++) {
            $province = $provinces[($index - 1) % count($provinces)];
            $branch = $branchesByProvince[$province->id]->first();
            $userRows[] = [
                'tenant_id' => $platformId,
                'branch_id' => $branch->id,
                'name' => sprintf('مندوب تشغيل %04d', $index),
                'username' => sprintf('opl-courier-%04d', $index),
                'phone' => sprintf('07850%06d', $index),
                'password' => $password,
                'role' => 'courier',
                'status' => 'active',
                'vehicle' => $index % 3 === 0 ? 'car' : 'bike',
                'theme' => 'light',
                'locale' => 'ar',
                'is_online' => $index % 5 !== 0,
                'last_active_at' => $now,
                'current_latitude' => 33.3128050 + (($index % 17) / 1000),
                'current_longitude' => 44.3614880 + (($index % 19) / 1000),
                'location_accuracy_meters' => 15 + ($index % 35),
                'location_updated_at' => $now->copy()->subMinutes($index % 20),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($userRows, 500) as $chunk) {
            DB::table('users')->upsert($chunk, ['username'], [
                'tenant_id', 'branch_id', 'name', 'phone', 'password', 'role', 'status', 'vehicle', 'theme', 'locale', 'is_online', 'last_active_at',
                'current_latitude', 'current_longitude', 'location_accuracy_meters', 'location_updated_at', 'updated_at',
            ]);
        }

        $users = DB::table('users')->where('username', 'like', 'opl-courier-%')->orderBy('username')->get(['id']);
        $provinceRows = [];
        foreach ($users as $offset => $user) {
            $provinceRows[] = [
                'user_id' => $user->id,
                'province_id' => $provinces[$offset % count($provinces)]->id,
                'is_primary' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        DB::table('province_user')->upsert($provinceRows, ['user_id', 'province_id'], ['is_primary', 'updated_at']);
    }

    /** @param array<int, int> $merchantIds @param array<int, int> $courierIds */
    private function seedWallets(array $merchantIds, array $courierIds, Carbon $now): void
    {
        $rows = [];
        foreach ($merchantIds as $offset => $id) {
            $rows[] = ['user_id' => $id, 'balance' => 100000 + (($offset % 9) * 25000), 'budget' => 0, 'budget_balance' => 0, 'created_at' => $now, 'updated_at' => $now];
        }
        foreach ($courierIds as $offset => $id) {
            $budget = 200000 + (($offset % 11) * 30000);
            $rows[] = ['user_id' => $id, 'balance' => 50000 + (($offset % 7) * 15000), 'budget' => $budget, 'budget_balance' => $budget, 'created_at' => $now, 'updated_at' => $now];
        }
        foreach (array_chunk($rows, 1000) as $chunk) {
            DB::table('wallets')->upsert($chunk, ['user_id'], ['balance', 'budget', 'budget_balance', 'updated_at']);
        }
    }

    /** @param array<int, object> $merchants @param array<int, object> $couriers @param array<int, object> $branches */
    private function seedOrders(array $merchants, array $couriers, array $branches, Carbon $now): void
    {
        $statusFlow = [
            'pending' => ['created', null, null, null, null],
            'approved' => ['awaiting_pickup', 'accepted_at', null, null, null],
            'courier' => ['picked_up', 'accepted_at', 'picked_at', null, null],
            'delivered' => ['delivered', 'accepted_at', 'picked_at', 'delivered_at', null],
            'returned' => ['returned', 'accepted_at', 'picked_at', null, 'returned_at'],
        ];
        $statuses = ['pending', 'pending', 'approved', 'approved', 'courier', 'courier', 'delivered', 'delivered', 'delivered', 'returned'];
        $branchesByProvince = collect($branches)->groupBy('province_id');
        $rows = [];

        for ($index = 1; $index <= self::ORDERS; $index++) {
            $merchant = $merchants[($index - 1) % count($merchants)];
            $courier = $couriers[($index - 1) % count($couriers)];
            $branch = $branchesByProvince[$merchant->province_id]->first()
                ?? $branches[($index - 1) % count($branches)];
            $status = $statuses[($index - 1) % count($statuses)];
            [$stage, $accepted, $picked, $delivered, $returned] = $statusFlow[$status];
            $created = $now->copy()->subMinutes(($index * 7) % 43200);
            $price = 12000 + (($index % 85) * 1000);
            $fee = 2000 + (($index % 7) * 500);
            $assigned = $status === 'pending' ? null : $courier->id;

            $rows[] = [
                'tenant_id' => $merchant->tenant_id,
                'track_no' => sprintf('%s-%05d', self::MARKER, $index),
                'source' => 'merchant',
                'customer_name_ar' => sprintf('زبون تشغيل %05d', $index),
                'customer_name_en' => sprintf('Operational Customer %05d', $index),
                'phone' => sprintf('07760%06d', $index),
                'phone2' => null,
                'address_ar' => sprintf('%s - عنوان اختبار تشغيلي %05d', $branch->city, $index),
                'address_en' => sprintf('%s - Operational address %05d', $branch->city, $index),
                'pickup_latitude' => 33.3128050 + (($index % 17) / 1000),
                'pickup_longitude' => 44.3614880 + (($index % 19) / 1000),
                'pickup_location_label' => sprintf('موقع تاجر تشغيل %04d', (($index - 1) % count($merchants)) + 1),
                'order_type' => $index % 4 === 0 ? 'مستند' : 'طلب عادي',
                'delivery_vehicle' => $index % 3 === 0 ? 'car' : 'bike',
                'vehicle_note' => null,
                'weight_grams' => 300 + (($index % 15) * 150),
                'price' => $price,
                'fee' => $fee,
                'return_fee' => 1000,
                'return_fee_applied' => $status === 'returned' ? 1000 : 0,
                'pricing_rule_id' => null,
                'status' => $status,
                'workflow_stage' => $stage,
                'courier_id' => $assigned,
                'branch_id' => $branch->id,
                'origin_branch_id' => $branch->id,
                'destination_branch_id' => $branch->id,
                'merchant_id' => $merchant->id,
                'pickup_courier_id' => $assigned,
                'delivery_courier_id' => $assigned,
                'province_id' => $branch->province_id,
                'date' => $created->toDateString(),
                'notes' => sprintf('[%s] بيانات تشغيلية للقياس فقط.', self::MARKER),
                'created_by' => $merchant->id,
                'accepted_at' => $accepted ? $created->copy()->addMinutes(10) : null,
                'picked_at' => $picked ? $created->copy()->addMinutes(25) : null,
                'delivered_at' => $delivered ? $created->copy()->addMinutes(75) : null,
                'returned_at' => $returned ? $created->copy()->addMinutes(90) : null,
                'pickup_deadline_at' => $status === 'pending' ? $now->copy()->addMinutes(30) : null,
                'created_at' => $created,
                'updated_at' => $now,
            ];

            if (count($rows) === 500) {
                $this->upsertOrders($rows);
                $rows = [];
            }
        }

        if ($rows !== []) {
            $this->upsertOrders($rows);
        }
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function upsertOrders(array $rows): void
    {
        DB::table('orders')->upsert($rows, ['track_no'], [
            'tenant_id', 'customer_name_ar', 'customer_name_en', 'phone', 'address_ar', 'address_en', 'pickup_latitude', 'pickup_longitude', 'pickup_location_label',
            'order_type', 'delivery_vehicle', 'weight_grams', 'price', 'fee', 'return_fee', 'return_fee_applied', 'status', 'workflow_stage', 'courier_id',
            'branch_id', 'origin_branch_id', 'destination_branch_id', 'merchant_id', 'pickup_courier_id', 'delivery_courier_id', 'province_id', 'date', 'notes',
            'created_by', 'accepted_at', 'picked_at', 'delivered_at', 'returned_at', 'pickup_deadline_at', 'updated_at',
        ]);
    }

    /** @param array<int, object> $merchants @param array<int, object> $couriers */
    private function seedChatsAndMessages(array $merchants, array $couriers, Carbon $now): void
    {
        $orders = DB::table('orders')->where('track_no', 'like', self::MARKER.'-%')->orderBy('track_no')->limit(self::CONVERSATIONS)->get(['id', 'tenant_id', 'merchant_id', 'courier_id']);
        $chatRows = [];
        foreach ($orders as $offset => $order) {
            $courier = $order->courier_id ?: $couriers[$offset % count($couriers)]->id;
            $chatRows[] = [
                'tenant_id' => $order->tenant_id,
                'user_id' => $order->merchant_id,
                'counterparty_type' => 'courier',
                'counterparty_id' => $courier,
                'order_id' => $order->id,
                'title_ar' => 'محادثة تشغيلية',
                'title_en' => self::MARKER.' operational chat',
                'last_message' => self::MARKER.' متابعة الطلب.',
                'last_at' => $now->copy()->subMinutes($offset % 180),
                'unread' => $offset % 4 === 0 ? 1 : 0,
                'user_read_at' => null,
                'counterparty_read_at' => null,
                'admin_read_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        foreach (array_chunk($chatRows, 500) as $chunk) {
            DB::table('chats')->insertOrIgnore($chunk);
        }

        $chats = DB::table('chats')->where('title_en', self::MARKER.' operational chat')->orderBy('id')->get(['id', 'user_id', 'counterparty_id']);
        $alreadySeeded = DB::table('chat_messages')->where('text', 'like', '['.self::MARKER.']%')->pluck('chat_id')->flip();
        $messages = [];
        foreach ($chats as $offset => $chat) {
            if (isset($alreadySeeded[$chat->id])) {
                continue;
            }
            $messages[] = [
                'chat_id' => $chat->id,
                'sender_id' => $offset % 2 === 0 ? $chat->user_id : $chat->counterparty_id,
                'text' => sprintf('[%s] تحديث تشغيلي للمحادثة %04d.', self::MARKER, $offset + 1),
                'read_at' => $offset % 3 === 0 ? null : $now,
                'created_at' => $now->copy()->subMinutes($offset % 180),
            ];
        }
        foreach (array_chunk($messages, 500) as $chunk) {
            DB::table('chat_messages')->insert($chunk);
        }
    }

    /** @param array<int, object> $merchants @param array<int, object> $couriers */
    private function seedNotifications(array $merchants, array $couriers, Carbon $now): void
    {
        $rows = [];
        for ($index = 1; $index <= self::NOTIFICATIONS; $index++) {
            $recipient = $index % 2 === 0
                ? $merchants[($index - 1) % count($merchants)]
                : $couriers[($index - 1) % count($couriers)];
            $rows[] = [
                'tenant_id' => $recipient->tenant_id ?? null,
                'user_id' => $recipient->id,
                'type' => 'operational_load',
                'title_ar' => 'إشعار تشغيلي',
                'title_en' => 'Operational notification',
                'title_ku' => null,
                'body_ar' => sprintf('إشعار اختبار سعة رقم %04d.', $index),
                'body_en' => sprintf('Operational capacity notification %04d.', $index),
                'body_ku' => null,
                'read_at' => $index % 4 === 0 ? null : $now,
                'data' => json_encode(['marker' => self::MARKER, 'number' => $index], JSON_THROW_ON_ERROR),
                'dedup_key' => sprintf('%s-notification-%04d', self::MARKER, $index),
                'created_at' => $now->copy()->subMinutes($index % 720),
            ];
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('notifications')->upsert($chunk, ['dedup_key'], [
                'tenant_id', 'user_id', 'type', 'title_ar', 'title_en', 'body_ar', 'body_en', 'read_at', 'data', 'created_at',
            ]);
        }
    }
}
