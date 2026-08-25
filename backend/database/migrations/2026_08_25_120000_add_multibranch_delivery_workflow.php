<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->string('code', 20)->nullable()->after('tenant_id');
            $table->string('phone', 30)->nullable()->after('city');
            $table->string('address', 255)->nullable()->after('phone');
            $table->unsignedBigInteger('cash_balance')->default(0)->after('address');
            $table->boolean('is_active')->default(true)->after('cash_balance');
            $table->unique(['tenant_id', 'code']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('branch_id')->nullable()->after('tenant_id')->index();
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedBigInteger('origin_branch_id')->nullable()->after('branch_id')->index();
            $table->unsignedBigInteger('destination_branch_id')->nullable()->after('origin_branch_id')->index();
            $table->unsignedBigInteger('merchant_id')->nullable()->after('destination_branch_id')->index();
            $table->unsignedBigInteger('pickup_courier_id')->nullable()->after('merchant_id')->index();
            $table->unsignedBigInteger('delivery_courier_id')->nullable()->after('pickup_courier_id')->index();
            $table->string('workflow_stage', 40)->default('created')->after('status')->index();
        });

        Schema::create('branch_transfers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('origin_branch_id')->index();
            $table->unsignedBigInteger('destination_branch_id')->index();
            $table->unsignedBigInteger('transporter_id')->nullable()->index();
            $table->string('reference', 40)->unique();
            $table->string('status', 30)->default('draft')->index();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('branch_transfer_orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('branch_transfer_id')->index();
            $table->unsignedBigInteger('order_id')->index();
            $table->timestamps();
            $table->unique(['branch_transfer_id', 'order_id']);
        });

        Schema::create('order_movements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('order_id')->index();
            $table->unsignedBigInteger('from_branch_id')->nullable()->index();
            $table->unsignedBigInteger('to_branch_id')->nullable()->index();
            $table->unsignedBigInteger('actor_id')->nullable()->index();
            $table->string('stage', 40)->index();
            $table->string('note', 255)->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('occurred_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_movements');
        Schema::dropIfExists('branch_transfer_orders');
        Schema::dropIfExists('branch_transfers');
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['origin_branch_id', 'destination_branch_id', 'merchant_id', 'pickup_courier_id', 'delivery_courier_id', 'workflow_stage']);
        });
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('branch_id'));
        Schema::table('branches', fn (Blueprint $table) => $table->dropColumn(['code', 'phone', 'address', 'cash_balance', 'is_active']));
    }
};
