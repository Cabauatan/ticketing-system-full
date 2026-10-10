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
        foreach (['TicketAnalytics', 'TicketAnalyticsExport'] as $name) {
            $sql = file_get_contents(database_path("procedures/reports/$name.sql"));
            if ($sql === false) {
                throw new RuntimeException("Missing procedure $name");
            }
            DB::connection()->getPdo()->exec("DROP PROCEDURE IF EXISTS `$name`");
            DB::connection()->getPdo()->exec($sql);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::unprepared('DROP PROCEDURE IF EXISTS TicketAnalyticsExport');
            DB::unprepared('DROP PROCEDURE IF EXISTS TicketAnalytics');
        }
    }
};
