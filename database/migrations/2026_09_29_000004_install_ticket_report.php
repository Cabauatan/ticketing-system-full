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
        $sql = file_get_contents(database_path('procedures/TicketReport.sql'));
        if ($sql === false) {
            throw new RuntimeException('Cannot read TicketReport procedure');
        }
        DB::unprepared('DROP PROCEDURE IF EXISTS TicketReport');
        DB::connection()->getPdo()->exec($sql);
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::unprepared('DROP PROCEDURE IF EXISTS TicketReport');
        }
    }
};
