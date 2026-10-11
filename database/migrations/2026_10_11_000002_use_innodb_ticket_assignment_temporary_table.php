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

        $sql = file_get_contents(database_path('procedures/multi_role_access/TicketUpdateAgents.sql'));
        if ($sql === false) {
            throw new RuntimeException('Missing TicketUpdateAgents procedure source.');
        }

        // Reinstall the current authorization rules using a supported engine.
        // Only the procedure changes; existing tickets and assignments remain.
        $connection = DB::connection()->getPdo();
        $connection->exec('DROP PROCEDURE IF EXISTS `TicketUpdateAgents`');
        $connection->exec($sql);
    }

    public function down(): void
    {
        // Keep the supported engine and current multi-role authorization.
        // Restoring MEMORY would break ticket updates on managed MySQL.
    }
};
