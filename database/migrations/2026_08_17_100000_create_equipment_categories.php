<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * equipment.category was a plain string backed by a hardcoded list of eight
     * options in EquipmentResource. Real plant equipment doesn't fit eight
     * generic buckets — anything unrecognised ended up in "Other", which tells
     * a technician nothing — and the list could only be extended by editing
     * code and redeploying.
     *
     * Modelled as a lookup table rather than free text for the same reason as
     * line_leaders: category is a filter dimension, and free text fragments a
     * filter the first time somebody types "Refrigeration" instead of
     * "Refrigeration ". Tenant-scoped, so each team defines its own vocabulary.
     */
    private const DEFAULT_CATEGORIES = [
        'HVAC',
        'Electrical',
        'Plumbing',
        'Mechanical',
        'IT Equipment',
        'Safety Equipment',
        'Vehicles',
        'Other',
    ];

    public function up(): void
    {
        Schema::create('equipment_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('team_id')->nullable()->constrained('teams')->onDelete('cascade');
            $table->timestamps();

            $table->unique(['team_id', 'name']);
        });

        Schema::table('equipment', function (Blueprint $table) {
            $table->foreignId('equipment_category_id')
                ->nullable()
                ->after('category')
                ->constrained('equipment_categories')
                // Retiring a category must not delete the machines filed under
                // it — they just become uncategorised.
                ->nullOnDelete();
        });

        $this->seedDefaultsPerTeam();
        $this->backfillFromExistingStrings();

        Schema::table('equipment', function (Blueprint $table) {
            // The original create_equipment migration indexed ['category',
            // 'location']. The column cannot be dropped while that index
            // still references it, so swap the index over first.
            $table->dropIndex('equipment_category_location_index');
            $table->index(['equipment_category_id', 'location']);
        });

        Schema::table('equipment', function (Blueprint $table) {
            $table->dropColumn('category');
        });
    }

    public function down(): void
    {
        Schema::table('equipment', function (Blueprint $table) {
            $table->string('category')->nullable()->after('manufacturer');
        });

        // Restore the original strings so no information is lost on rollback.
        foreach (DB::table('equipment_categories')->get() as $category) {
            DB::table('equipment')
                ->where('equipment_category_id', $category->id)
                ->update(['category' => $category->name]);
        }

        Schema::table('equipment', function (Blueprint $table) {
            $table->dropIndex(['equipment_category_id', 'location']);
            $table->dropForeign(['equipment_category_id']);
            $table->dropColumn('equipment_category_id');
            $table->index(['category', 'location']);
        });

        Schema::dropIfExists('equipment_categories');
    }

    /**
     * Give every existing team the original eight options, so nobody opens the
     * form to an empty dropdown after upgrading.
     */
    private function seedDefaultsPerTeam(): void
    {
        $teamIds = DB::table('teams')->pluck('id');

        foreach ($teamIds as $teamId) {
            foreach (self::DEFAULT_CATEGORIES as $name) {
                DB::table('equipment_categories')->insertOrIgnore([
                    'name' => $name,
                    'team_id' => $teamId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    /**
     * Map each existing category string onto a row, creating any value that
     * isn't one of the defaults (a team may already have hand-entered
     * something else).
     */
    private function backfillFromExistingStrings(): void
    {
        $existing = DB::table('equipment')
            ->select('team_id', 'category')
            ->whereNotNull('category')
            ->distinct()
            ->get();

        foreach ($existing as $row) {
            $categoryId = DB::table('equipment_categories')
                ->where('team_id', $row->team_id)
                ->where('name', $row->category)
                ->value('id');

            if ($categoryId === null) {
                $categoryId = DB::table('equipment_categories')->insertGetId([
                    'name' => $row->category,
                    'team_id' => $row->team_id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table('equipment')
                ->where('category', $row->category)
                ->when(
                    $row->team_id === null,
                    fn ($q) => $q->whereNull('team_id'),
                    fn ($q) => $q->where('team_id', $row->team_id),
                )
                ->update(['equipment_category_id' => $categoryId]);
        }
    }
};
