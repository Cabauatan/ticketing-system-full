<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private function install(string $folder, string $name): void
    {
        $sql = file_get_contents(database_path("procedures/$folder/$name.sql"));
        if ($sql === false) {
            throw new RuntimeException("Missing procedure $name in $folder");
        }

        DB::connection()->getPdo()->exec("DROP PROCEDURE IF EXISTS `$name`");
        DB::connection()->getPdo()->exec($sql);
    }

    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("ALTER TABLE tickets ALTER COLUMN priority SET DEFAULT 'low'");
        $this->install('priority_control', 'TicketCreate');
        $this->install('priority_control', 'TicketUpdateAgents');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("ALTER TABLE tickets ALTER COLUMN priority SET DEFAULT 'medium'");
        $this->install('multiagent', 'TicketCreate');
        $this->install('multiagent', 'TicketUpdateAgents');
    }
};
