<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $sql = file_get_contents(database_path('procedures/multiagent/TicketUpdateAgents.sql'));

        if ($sql === false) {
            throw new RuntimeException('Missing procedure TicketUpdateAgents');
        }

        DB::connection()->getPdo()->exec('DROP PROCEDURE IF EXISTS `TicketUpdateAgents`');
        DB::connection()->getPdo()->exec($sql);
    }

    public function down(): void
    {
        // Restoring the previous procedure would reintroduce the collation error.
    }
};
