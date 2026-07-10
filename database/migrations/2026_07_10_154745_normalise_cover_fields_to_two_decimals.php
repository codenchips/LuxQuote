<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $this->modifyCoverColumns(2);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $this->modifyCoverColumns(3);
    }

    private function modifyCoverColumns(int $scale): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        foreach (['projects', 'project_lines'] as $table) {
            foreach (['cover_1', 'cover_2', 'cover_3'] as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    continue;
                }

                DB::statement("ALTER TABLE `{$table}` MODIFY `{$column}` DECIMAL(6, {$scale}) NULL");
            }
        }
    }
};
