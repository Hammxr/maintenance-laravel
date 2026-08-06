<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adds what's needed for a Task to double as an "unplanned maintenance"
     * record: a real, savable 'name' column (the form has always had a Name
     * field, but it was never wired to any actual column — anything typed
     * there was silently discarded), a link to the piece of equipment the
     * work was performed on, and a completed_at timestamp so date-ranged
     * reports can tell exactly when it was done (mirroring the same
     * precision work_orders.completed_at already gets).
     */
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->string('name')->nullable()->after('task_id');
            $table->foreignId('equipment_id')->nullable()->after('name')
                ->constrained('equipment')->nullOnDelete();
            $table->timestamp('completed_at')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('equipment_id');
            $table->dropColumn(['name', 'completed_at']);
        });
    }
};
