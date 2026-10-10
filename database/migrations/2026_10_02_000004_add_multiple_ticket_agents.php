<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const SOURCES = [
        'TicketCreate' => 'permissions', 'TicketShow' => 'permissions',
        'TicketList' => 'permissions', 'TicketComment' => 'permissions',
        'TicketReopen' => 'permissions', 'TicketReport' => 'permissions',
        'AdminUserRole' => 'permissions', 'TicketEscalate' => 'superadmin',
        'TicketUpdate' => 'permissions',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('ticket_assignees')) {
            Schema::create('ticket_assignees', function (Blueprint $table) {
                $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->primary(['ticket_id', 'user_id']);
            });
            DB::table('ticket_assignees')->insertUsing(['ticket_id', 'user_id'],
                DB::table('tickets')->select('id', 'assignee_id')->whereNotNull('assignee_id'));
        }
        if (DB::getDriverName() === 'mysql') {
            foreach (['TicketUpdateAgents', ...array_keys(self::SOURCES)] as $name) {
                $this->install($name, 'multiagent');
            }
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            foreach (self::SOURCES as $name => $source) {
                $this->install($name, $source);
            }
            DB::unprepared('DROP PROCEDURE IF EXISTS TicketUpdateAgents');
        }
        Schema::dropIfExists('ticket_assignees');
    }

    private function install(string $name, string $source): void
    {
        $sql = file_get_contents(database_path("procedures/$source/$name.sql"));
        if ($sql === false) {
            throw new RuntimeException("Missing procedure $name");
        }
        DB::connection()->getPdo()->exec("DROP PROCEDURE IF EXISTS `$name`");
        DB::connection()->getPdo()->exec($sql);
    }
};
