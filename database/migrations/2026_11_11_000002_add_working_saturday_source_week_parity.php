<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schedule_date_exceptions', fn (Blueprint $table) => $table->string('source_week_parity', 16)->nullable());
        DB::statement("ALTER TABLE schedule_date_exceptions ADD CONSTRAINT saturday_source_parity_check CHECK (
            source_week_parity IS NULL OR (kind = 'working_saturday' AND source_week_parity IN ('numerator', 'denominator')))");
    }

    public function down(): void
    {
        if (DB::table('schedule_date_exceptions')->whereNotNull('source_week_parity')->exists()) {
            throw new RuntimeException('Working Saturday source metadata exists; use a forward migration.');
        }
        DB::statement('ALTER TABLE schedule_date_exceptions DROP CHECK saturday_source_parity_check');
        Schema::table('schedule_date_exceptions', fn (Blueprint $table) => $table->dropColumn('source_week_parity'));
    }
};
