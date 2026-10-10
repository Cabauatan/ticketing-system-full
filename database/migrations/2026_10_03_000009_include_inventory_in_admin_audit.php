<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->install('permissions/AdminAudit.sql');
    }

    public function down(): void
    {
        $this->install('complete/AdminAudit.sql');
    }

    private function install(string $path): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $sql = file_get_contents(database_path("procedures/$path"));
        if ($sql === false) {
            throw new RuntimeException("Missing procedure source: $path");
        }

        DB::connection()->getPdo()->exec('DROP PROCEDURE IF EXISTS `AdminAudit`');
        DB::connection()->getPdo()->exec($sql);
    }
};
