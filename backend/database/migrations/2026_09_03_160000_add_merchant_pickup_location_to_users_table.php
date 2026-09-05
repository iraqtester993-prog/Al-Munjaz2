<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Store a merchant's fixed shop/pickup point separately from a courier's
     * live, consented operational location.  Orders retain their own snapshot
     * so a later shop move never rewrites an already-created delivery.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->decimal('merchant_pickup_latitude', 10, 7)->nullable()->after('address');
            $table->decimal('merchant_pickup_longitude', 10, 7)->nullable()->after('merchant_pickup_latitude');
            $table->string('merchant_pickup_location_label', 255)->nullable()->after('merchant_pickup_longitude');
            $table->timestamp('merchant_pickup_location_updated_at')->nullable()->after('merchant_pickup_location_label');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn([
                'merchant_pickup_latitude',
                'merchant_pickup_longitude',
                'merchant_pickup_location_label',
                'merchant_pickup_location_updated_at',
            ]);
        });
    }
};
