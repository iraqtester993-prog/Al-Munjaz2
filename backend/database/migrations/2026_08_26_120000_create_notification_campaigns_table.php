<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('audience', 24);
            $table->foreignId('target_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 30)->default('announcement');
            $table->string('title_ar', 160);
            $table->string('title_en', 160)->nullable();
            $table->string('title_ku', 160)->nullable();
            $table->text('body_ar')->nullable();
            $table->text('body_en')->nullable();
            $table->text('body_ku')->nullable();
            $table->unsignedInteger('recipient_count')->default(0);
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['audience', 'sent_at']);
        });

        Schema::table('notifications', function (Blueprint $table) {
            $table->foreignId('campaign_id')
                ->nullable()
                ->after('id')
                ->constrained('notification_campaigns')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropConstrainedForeignId('campaign_id');
        });

        Schema::dropIfExists('notification_campaigns');
    }
};
