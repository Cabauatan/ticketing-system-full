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

        $sql = file_get_contents(database_path('procedures/reports/TicketAnalytics.sql'));
        if ($sql === false) {
            throw new RuntimeException('Missing procedure TicketAnalytics');
        }

        DB::connection()->getPdo()->exec('DROP PROCEDURE IF EXISTS `TicketAnalytics`');
        DB::connection()->getPdo()->exec($sql);
    }

    public function down(): void
    {
        // The previous report procedure does not support role memberships.
    }
};
