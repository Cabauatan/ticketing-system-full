<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private function install(string $folder): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $sql = file_get_contents(database_path("procedures/$folder/TicketEscalate.sql"));
        if ($sql === false) {
            throw new RuntimeException("Missing TicketEscalate procedure in $folder");
        }

        DB::connection()->getPdo()->exec('DROP PROCEDURE IF EXISTS `TicketEscalate`');
        DB::connection()->getPdo()->exec($sql);
    }

    public function up(): void
    {
        $this->install('auto_urgent');
    }

    public function down(): void
    {
        $this->install('superadmin');
    }
};
