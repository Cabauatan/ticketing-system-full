<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private function install(string $name): void
    {
        $sql = file_get_contents(database_path("procedures/permissions/$name.sql"));

        if ($sql === false) {
            throw new RuntimeException("Missing procedure $name");
        }

        DB::connection()->getPdo()->exec("DROP PROCEDURE IF EXISTS `$name`");
        DB::connection()->getPdo()->exec($sql);
    }

    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $this->install('PermissionRequire');
        $this->install('PermissionSet');
    }

    public function down(): void
    {
        // These collation declarations are compatible with the prior procedure behavior.
    }
};
