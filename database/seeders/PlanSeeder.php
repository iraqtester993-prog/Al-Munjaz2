<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PlanSeeder extends Seeder
{
    public function run(): void
    {
        Plan::query()->delete();

        Plan::create([
            'slug' => 'basic',
            'name_ar' => 'أساسي',
            'name_en' => 'Basic',
            'name_ku' => 'بونەڕەت',
            'price' => 0,
            'limits' => ['max_orders_month' => 100, 'max_branches' => 2],
            'features' => ['orders', 'wallet', 'support'],
            'is_active' => true,
        ]);

        Plan::create([
            'slug' => 'pro',
            'name_ar' => 'احترافي',
            'name_en' => 'Pro',
            'name_ku' => 'پرۆ',
            'price' => 25000,
            'limits' => ['max_orders_month' => 2000, 'max_branches' => 10],
            'features' => ['orders', 'wallet', 'branches', 'support', 'reports'],
            'is_active' => true,
        ]);

        Plan::create([
            'slug' => 'enterprise',
            'name_ar' => 'مؤسسات',
            'name_en' => 'Enterprise',
            'name_ku' => 'دەزگا',
            'price' => 75000,
            'limits' => ['max_orders_month' => null, 'max_branches' => null],
            'features' => ['orders', 'wallet', 'branches', 'support', 'reports', 'api'],
            'is_active' => true,
        ]);
    }
}
