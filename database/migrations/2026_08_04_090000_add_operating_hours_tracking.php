<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adds the fields needed for hour-meter-based preventive maintenance:
     *   - equipment.current_hours: the equipment's current operating-hour
     *     reading, updatable by a technician at any time.
     *   - maintenance_schedules.hours_at_last_maintenance: a snapshot of
     *     what current_hours was the last time THIS specific schedule was
     *     completed. Kept per-schedule (not just per-equipment) because one
     *     machine can have multiple hour-based schedules with different
     *     intervals (e.g. oil change every 500 hours, filter every 1000),
     *     each needing its own baseline to measure "hours since last done"
     *     from.
     */
    public function up(): void
    {
        Schema::table('equipment', function (Blueprint $table) {
            $table->unsignedInteger('current_hours')->nullable()->after('status');
        });

        Schema::table('maintenance_schedules', function (Blueprint $table) {
            $table->unsignedInteger('hours_at_last_maintenance')->nullable()->after('last_completed_date');
        });
    }

    public function down(): void
    {
        Schema::table('equipment', function (Blueprint $table) {
            $table->dropColumn('current_hours');
        });

        Schema::table('maintenance_schedules', function (Blueprint $table) {
            $table->dropColumn('hours_at_last_maintenance');
        });
    }
};
