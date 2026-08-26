<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cashboxes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('branch_id')->nullable()->unique();
            $table->string('kind', 30)->default('branch')->index();
            $table->string('name_ar', 120);
            $table->string('name_en', 120)->nullable();
            $table->string('name_ku', 120)->nullable();
            $table->unsignedBigInteger('balance')->default(0);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('cashbox_vouchers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('cashbox_id')->index();
            $table->unsignedBigInteger('counterparty_cashbox_id')->nullable()->index();
            $table->unsignedBigInteger('branch_id')->nullable()->index();
            $table->unsignedBigInteger('actor_id')->nullable()->index();
            $table->string('type', 40)->index();
            $table->tinyInteger('direction');
            $table->unsignedBigInteger('amount');
            $table->string('reference', 50)->index();
            $table->string('note', 1000)->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('occurred_at')->useCurrent()->index();
            $table->timestamps();
        });

        Schema::create('pricing_rules', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('merchant_id')->nullable()->index();
            $table->unsignedBigInteger('origin_province_id')->nullable()->index();
            $table->unsignedBigInteger('destination_province_id')->nullable()->index();
            $table->string('service', 60)->nullable()->index();
            $table->string('vehicle', 30)->nullable()->index();
            $table->unsignedInteger('min_weight_grams')->default(0);
            $table->unsignedInteger('max_weight_grams')->nullable();
            $table->unsignedBigInteger('base_fee');
            $table->unsignedBigInteger('return_fee')->default(0);
            $table->unsignedSmallInteger('priority')->default(100);
            $table->boolean('is_active')->default(true)->index();
            $table->string('name_ar', 120);
            $table->string('name_en', 120)->nullable();
            $table->string('name_ku', 120)->nullable();
            $table->timestamps();
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedInteger('weight_grams')->default(0)->after('delivery_vehicle');
            $table->unsignedBigInteger('return_fee')->default(0)->after('fee');
            $table->unsignedBigInteger('return_fee_applied')->default(0)->after('return_fee');
            $table->unsignedBigInteger('pricing_rule_id')->nullable()->after('return_fee_applied')->index();
            $table->timestamp('returned_to_merchant_at')->nullable()->after('returned_at');
            $table->timestamp('return_fee_charged_at')->nullable()->after('returned_to_merchant_at');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['weight_grams', 'return_fee', 'return_fee_applied', 'pricing_rule_id', 'returned_to_merchant_at', 'return_fee_charged_at']);
        });

        Schema::dropIfExists('pricing_rules');
        Schema::dropIfExists('cashbox_vouchers');
        Schema::dropIfExists('cashboxes');
    }
};
