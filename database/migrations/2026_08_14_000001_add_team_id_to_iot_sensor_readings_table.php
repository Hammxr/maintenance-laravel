<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('iot_sensor_readings', function (Blueprint $table) {
            $table->foreignId('team_id')->nullable()->after('equipment_id')->constrained()->onDelete('cascade');
            $table->index(['team_id', 'reading_time']);
        });

        // Existing readings inherit the team of the equipment they belong to.
        DB::table('iot_sensor_readings')->whereNull('team_id')->update([
            'team_id' => DB::table('equipment')
                ->whereColumn('equipment.id', 'iot_sensor_readings.equipment_id')
                ->select('equipment.team_id'),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('iot_sensor_readings', function (Blueprint $table) {
            $table->dropIndex(['team_id', 'reading_time']);
            $table->dropForeign(['team_id']);
            $table->dropColumn('team_id');
        });
    }
};
