<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Line leaders mirror a filing system the technicians already use off-system:
     * each machine informally "belongs to" a line leader, and they want to filter
     * the equipment list the same way here, for continuity with how they already
     * work.
     *
     * This is deliberately NOT equipment.team_id. That column is the
     * Jetstream/Filament tenant which owns the record and scopes every query in
     * the app; a line leader is a grouping label *within* one tenant. The two
     * would be impossible to tell apart if both were called "team".
     *
     * Kept as its own table rather than a free-text column because the whole
     * point is filtering, and free text fragments a filter the first time
     * somebody types "J. Smith" instead of "John Smith". Deliberately not a
     * foreign key to users either — line leaders don't necessarily have logins,
     * and requiring an account to record one would block the common case.
     */
    public function up(): void
    {
        Schema::create('line_leaders', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('notes')->nullable();
            $table->foreignId('team_id')->nullable()->constrained('teams')->onDelete('cascade');
            $table->timestamps();

            // Two line leaders of the same name within one team would make the
            // filter ambiguous, which defeats the purpose.
            $table->unique(['team_id', 'name']);
        });

        Schema::table('equipment', function (Blueprint $table) {
            // Nullable by design: plenty of equipment doesn't fall under any
            // line leader, and the field must stay optional on the create form.
            $table->foreignId('line_leader_id')
                ->nullable()
                ->after('company_id')
                ->constrained('line_leaders')
                // Removing a line leader must not take their equipment with it —
                // it just becomes unassigned.
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('equipment', function (Blueprint $table) {
            $table->dropForeign(['line_leader_id']);
            $table->dropColumn('line_leader_id');
        });

        Schema::dropIfExists('line_leaders');
    }
};
