<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mobile_slides', function (Blueprint $table) {
            $table->id();
            $table->string('audience', 20)->default('all')->index();
            $table->string('title_ar', 160);
            $table->string('title_en', 160)->nullable();
            $table->string('title_ku', 160)->nullable();
            $table->text('body_ar')->nullable();
            $table->text('body_en')->nullable();
            $table->text('body_ku')->nullable();
            $table->string('tag_ar', 80)->nullable();
            $table->string('tag_en', 80)->nullable();
            $table->string('tag_ku', 80)->nullable();
            $table->string('cta_ar', 80)->nullable();
            $table->string('cta_en', 80)->nullable();
            $table->string('cta_ku', 80)->nullable();
            $table->string('action_url', 500)->nullable();
            $table->string('image_path')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamp('starts_at')->nullable()->index();
            $table->timestamp('ends_at')->nullable()->index();
            $table->timestamps();

            $table->index(['audience', 'is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mobile_slides');
    }
};
