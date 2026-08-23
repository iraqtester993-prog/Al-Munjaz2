<?php

namespace Database\Seeders;

use App\Models\Province;
use Illuminate\Database\Seeder;

class ProvinceSeeder extends Seeder
{
    public function run(): void
    {
        Province::query()->delete();

        $provinces = [
            ['بغداد', 'Baghdad', 'بەغدا'],
            ['البصرة', 'Basra', 'بەسرە'],
            ['نينوى', 'Nineveh', 'نەینەوا'],
            ['أربيل', 'Erbil', 'هەولێر'],
            ['النجف', 'Najaf', 'نەجەف'],
            ['كربلاء', 'Karbala', 'کەربەلا'],
            ['الأنبار', 'Anbar', 'ئەنبار'],
            ['ديالى', 'Diyala', 'دیالە'],
            ['ذي قار', 'Dhi Qar', 'ذی قار'],
            ['كركوك', 'Kirkuk', 'کەرکووک'],
            ['واسط', 'Wasit', 'واسط'],
            ['ميسان', 'Maysan', 'مەیسەن'],
            ['السليمانية', 'Sulaymaniyah', 'سلێمانی'],
            ['صلاح الدين', 'Salah al-Din', 'سەلاحەدین'],
            ['بابل', 'Babylon', 'بابل'],
            ['القادسية', 'Al-Qadisiyyah', 'قادسیە'],
            ['المثنى', 'Muthanna', 'موسەنا'],
            ['دهوك', 'Duhok', 'دهۆک'],
        ];

        foreach ($provinces as $i => [$ar, $en, $ku]) {
            Province::create([
                'name_ar' => $ar,
                'name_en' => $en,
                'name_ku' => $ku,
                'sort_order' => $i + 1,
            ]);
        }
    }
}
