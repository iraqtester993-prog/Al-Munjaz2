<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('shop_name', 120)->nullable()->after('vehicle');
            $table->string('address', 255)->nullable()->after('shop_name');
            $table->timestamp('phone_verified_at')->nullable()->after('email_verified_at');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->string('delivery_vehicle', 20)->default('normal')->after('order_type');
            $table->string('vehicle_note', 255)->nullable()->after('delivery_vehicle');
            $table->timestamp('pickup_deadline_at')->nullable()->after('returned_at');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['delivery_vehicle', 'vehicle_note', 'pickup_deadline_at']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['shop_name', 'address', 'phone_verified_at']);
        });
    }
};
