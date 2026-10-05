<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subjects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('education_institution_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('code', 50)->nullable();
            $table->char('color', 7)->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'name']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE subjects
                ADD CONSTRAINT subjects_valid_color
                    CHECK (color IS NULL OR color REGEXP '^#[0-9A-Fa-f]{6}$')
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('subjects');
    }
};
