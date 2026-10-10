<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private function install(string $directory, string $name): void
    {
        $sql = file_get_contents(database_path("procedures/$directory$name.sql"));
        if ($sql === false) {
            throw new RuntimeException("Cannot read $name");
        }
        DB::connection()->getPdo()->exec("DROP PROCEDURE IF EXISTS `$name`");
        DB::connection()->getPdo()->exec($sql);
    }

    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dateTime('due_at')->nullable()->index();
        });
        Schema::create('ticket_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('ticket_id')->constrained();
            $table->string('message', 200);
            $table->timestamp('read_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['user_id', 'read_at', 'id']);
        });
        if (DB::getDriverName() === 'mysql') {
            DB::statement("UPDATE tickets SET due_at = DATE_ADD(created_at, INTERVAL (CASE priority WHEN 'urgent' THEN 4 WHEN 'high' THEN 8 WHEN 'medium' THEN 24 ELSE 72 END) HOUR)");
            foreach (['TicketCreate', 'TicketUpdate', 'TicketComment', 'TicketNotifications', 'TicketNotificationRead'] as $name) {
                $this->install('sla/', $name);
            }
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            foreach (['TicketCreate', 'TicketUpdate', 'TicketComment'] as $name) {
                $this->install('', $name);
            }
            DB::unprepared('DROP PROCEDURE IF EXISTS TicketNotifications');
            DB::unprepared('DROP PROCEDURE IF EXISTS TicketNotificationRead');
        }
        Schema::dropIfExists('ticket_notifications');
        Schema::table('tickets', fn (Blueprint $table) => $table->dropColumn('due_at'));
    }
};
