<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 40)->unique();
            $table->string('name_ar', 80);
            $table->string('name_en', 80);
            $table->string('name_ku', 80)->nullable();
            $table->unsignedBigInteger('price')->default(0);
            $table->json('limits')->nullable();
            $table->json('features')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('plan_id')->nullable();
            $table->string('slug', 80)->unique();
            $table->string('name', 120);
            $table->string('kind', 20)->default('merchant')->index();
            $table->string('status', 20)->default('trial')->index();
            $table->timestamp('trial_ends_at')->nullable();
            $table->unsignedBigInteger('wallet_balance')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('plan_id')->references('id')->on('plans')->nullOnDelete();
        });

        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('name_ar', 120)->nullable();
            $table->string('name_en', 120)->nullable();
            $table->string('name_ku', 120)->nullable();
            $table->string('city', 60)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
        });

        Schema::create('provinces', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('name_ar', 80);
            $table->string('name_en', 80);
            $table->string('name_ku', 80)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('wallets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->unique();
            $table->unsignedBigInteger('balance')->default(0);
            $table->unsignedBigInteger('budget')->default(0);
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallets');
        Schema::dropIfExists('provinces');
        Schema::dropIfExists('branches');
        Schema::dropIfExists('tenants');
        Schema::dropIfExists('plans');
    }
};
