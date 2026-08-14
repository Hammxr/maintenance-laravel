<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * tasks.task_id, contacts.contact_id, notes.note_id, and
     * opportunities.opportunity_id were all created as a plain
     * `integer(...)->primary()` column rather than an auto-incrementing one.
     * Every model for these tables (Task, Contact, Note, Opportunity) uses
     * Eloquent's default $incrementing = true, so Eloquent leaves the ID
     * column out of INSERT statements entirely, expecting the database to
     * supply a value. Since the column was never AUTO_INCREMENT, MySQL has
     * no default for it, so every create() fails with:
     *   "Field 'xxx_id' doesn't have a default value"
     *
     * `companies` never had this problem because it already uses
     * $table->increments('company_id').
     *
     * contact_id and opportunity_id are also referenced by foreign keys on
     * notes and tasks. MySQL/InnoDB refuses to MODIFY a column involved in a
     * foreign key even with foreign_key_checks disabled (error 1833), so
     * those specific constraints must be dropped and recreated around the
     * ALTER. task_id and note_id aren't referenced by anything else and can
     * be altered directly.
     *
     * MySQL automatically seeds the auto_increment counter from
     * MAX(column)+1 when altering an existing column, so this is safe to
     * run against tables that already have data.
     */
    private array $foreignKeys = [
        ['table' => 'notes', 'constraint' => 'notes_contact_id_foreign', 'column' => 'contact_id', 'referencesColumn' => 'contact_id', 'referencesTable' => 'contacts'],
        ['table' => 'tasks', 'constraint' => 'tasks_contact_id_foreign', 'column' => 'contact_id', 'referencesColumn' => 'contact_id', 'referencesTable' => 'contacts'],
        ['table' => 'notes', 'constraint' => 'notes_opportunity_id_foreign', 'column' => 'opportunity_id', 'referencesColumn' => 'opportunity_id', 'referencesTable' => 'opportunities'],
        ['table' => 'tasks', 'constraint' => 'tasks_opportunity_id_foreign', 'column' => 'opportunity_id', 'referencesColumn' => 'opportunity_id', 'referencesTable' => 'opportunities'],
    ];

    /**
     * Everything below is MySQL-specific: `ALTER TABLE ... MODIFY ...
     * AUTO_INCREMENT` and `DROP FOREIGN KEY` are both MySQL syntax, and
     * SQLite rejects them outright.
     *
     * There is also nothing to fix on SQLite — an INTEGER PRIMARY KEY there is
     * an alias for the rowid and already auto-increments, which is why these
     * tables only ever misbehaved against MySQL. Tests run on SQLite
     * in-memory, so without this guard every migration-backed test in the
     * suite fails with 'near "MODIFY": syntax error'.
     */
    private function isMySql(): bool
    {
        return DB::connection()->getDriverName() === 'mysql';
    }

    public function up(): void
    {
        if (! $this->isMySql()) {
            return;
        }

        if (Schema::hasTable('tasks') && Schema::hasColumn('tasks', 'task_id')) {
            DB::statement('ALTER TABLE `tasks` MODIFY `task_id` INT NOT NULL AUTO_INCREMENT');
        }

        if (Schema::hasTable('notes') && Schema::hasColumn('notes', 'note_id')) {
            DB::statement('ALTER TABLE `notes` MODIFY `note_id` INT NOT NULL AUTO_INCREMENT');
        }

        $this->dropForeignKeys();

        if (Schema::hasTable('contacts') && Schema::hasColumn('contacts', 'contact_id')) {
            DB::statement('ALTER TABLE `contacts` MODIFY `contact_id` INT NOT NULL AUTO_INCREMENT');
        }

        if (Schema::hasTable('opportunities') && Schema::hasColumn('opportunities', 'opportunity_id')) {
            DB::statement('ALTER TABLE `opportunities` MODIFY `opportunity_id` INT NOT NULL AUTO_INCREMENT');
        }

        $this->restoreForeignKeys();
    }

    public function down(): void
    {
        if (! $this->isMySql()) {
            return;
        }

        $this->dropForeignKeys();

        if (Schema::hasTable('contacts') && Schema::hasColumn('contacts', 'contact_id')) {
            DB::statement('ALTER TABLE `contacts` MODIFY `contact_id` INT NOT NULL');
        }

        if (Schema::hasTable('opportunities') && Schema::hasColumn('opportunities', 'opportunity_id')) {
            DB::statement('ALTER TABLE `opportunities` MODIFY `opportunity_id` INT NOT NULL');
        }

        $this->restoreForeignKeys();

        if (Schema::hasTable('tasks') && Schema::hasColumn('tasks', 'task_id')) {
            DB::statement('ALTER TABLE `tasks` MODIFY `task_id` INT NOT NULL');
        }

        if (Schema::hasTable('notes') && Schema::hasColumn('notes', 'note_id')) {
            DB::statement('ALTER TABLE `notes` MODIFY `note_id` INT NOT NULL');
        }
    }

    private function dropForeignKeys(): void
    {
        foreach ($this->foreignKeys as $fk) {
            DB::statement("ALTER TABLE `{$fk['table']}` DROP FOREIGN KEY `{$fk['constraint']}`");
        }
    }

    private function restoreForeignKeys(): void
    {
        foreach ($this->foreignKeys as $fk) {
            DB::statement(
                "ALTER TABLE `{$fk['table']}` ADD CONSTRAINT `{$fk['constraint']}` ".
                "FOREIGN KEY (`{$fk['column']}`) REFERENCES `{$fk['referencesTable']}` (`{$fk['referencesColumn']}`)"
            );
        }
    }
};
