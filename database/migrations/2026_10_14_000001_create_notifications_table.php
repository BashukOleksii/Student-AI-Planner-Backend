<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->json('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            // Keep immutable inbox history if a future administrative purge removes its source.
            $table->foreignId('reminder_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->index(['notifiable_type', 'notifiable_id', 'read_at', 'created_at'], 'notifications_inbox_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
