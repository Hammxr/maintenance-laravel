<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Records when current_hours was last updated, so the overdue report
     * can say "as of the reading on [date]" rather than implying we know
     * the exact moment a schedule crossed its hour threshold (we don't —
     * only that it had crossed it by the time of the last reading).
     */
    public function up(): void
    {
        Schema::table('equipment', function (Blueprint $table) {
            $table->timestamp('current_hours_recorded_at')->nullable()->after('current_hours');
        });
    }

    public function down(): void
    {
        Schema::table('equipment', function (Blueprint $table) {
            $table->dropColumn('current_hours_recorded_at');
        });
    }
};
