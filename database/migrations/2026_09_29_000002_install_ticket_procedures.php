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
        foreach (['TicketList', 'TicketShow', 'TicketCreate', 'TicketUpdate', 'TicketComment', 'TicketAgents'] as $name) {
            $sql = file_get_contents(database_path("procedures/$name.sql"));
            if ($sql === false) {
                throw new RuntimeException("Cannot read procedure $name");
            }
            DB::unprepared("DROP PROCEDURE IF EXISTS `$name`");
            DB::connection()->getPdo()->exec($sql);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            foreach (['TicketList', 'TicketShow', 'TicketCreate', 'TicketUpdate', 'TicketComment', 'TicketAgents'] as $name) {
                DB::unprepared("DROP PROCEDURE IF EXISTS `$name`");
            }
        }
    }
};
