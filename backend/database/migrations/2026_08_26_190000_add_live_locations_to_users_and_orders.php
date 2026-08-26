<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The product intentionally stores only a courier's most recent shared
     * position.  We do not create a location-history table: operations needs
     * a current map pin, not an undeclared route-tracking archive.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->decimal('current_latitude', 10, 7)->nullable()->after('last_active_at');
            $table->decimal('current_longitude', 10, 7)->nullable()->after('current_latitude');
            $table->unsignedInteger('location_accuracy_meters')->nullable()->after('current_longitude');
            $table->timestamp('location_updated_at')->nullable()->after('location_accuracy_meters');
            // Matches the restricted dashboard lookup without turning this
            // into a location-history index.
            $table->index(
                ['role', 'status', 'deleted_at', 'location_updated_at'],
                'users_courier_location_lookup_index',
            );
        });

        Schema::table('orders', function (Blueprint $table): void {
            // This is the merchant pickup point.  It travels with the order
            // so the assigned courier can choose their own installed maps app
            // to navigate to the merchant before collecting the shipment.
            $table->decimal('pickup_latitude', 10, 7)->nullable()->after('address_en');
            $table->decimal('pickup_longitude', 10, 7)->nullable()->after('pickup_latitude');
            $table->string('pickup_location_label', 255)->nullable()->after('pickup_longitude');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn(['pickup_latitude', 'pickup_longitude', 'pickup_location_label']);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex('users_courier_location_lookup_index');
            $table->dropColumn([
                'current_latitude',
                'current_longitude',
                'location_accuracy_meters',
                'location_updated_at',
            ]);
        });
    }
};
