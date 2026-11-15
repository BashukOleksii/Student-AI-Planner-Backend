<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('reminder_email_enabled')->default(false);
            // Invalidates queued mail even if the user later restores an earlier address.
            $table->unsignedBigInteger('reminder_email_version')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['reminder_email_enabled', 'reminder_email_version']);
        });
    }
};
