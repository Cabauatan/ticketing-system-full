<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_chat_state', function (Blueprint $table) {
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('last_read_id')->default(0);
            $table->boolean('internal')->default(false);
            $table->dateTime('typing_until')->nullable();
            $table->primary(['ticket_id', 'user_id']);
        });
        if (DB::getDriverName() === 'mysql') {
            foreach (['TicketChatRead', 'TicketChatPresence'] as $name) {
                $sql = file_get_contents(database_path("procedures/chat/$name.sql"));
                if ($sql === false) {
                    throw new RuntimeException("Missing procedure $name");
                }
                DB::connection()->getPdo()->exec("DROP PROCEDURE IF EXISTS `$name`");
                DB::connection()->getPdo()->exec($sql);
            }
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            foreach (['TicketChatRead', 'TicketChatPresence'] as $name) {
                DB::connection()->getPdo()->exec("DROP PROCEDURE IF EXISTS `$name`");
            }
        }
        Schema::dropIfExists('ticket_chat_state');
    }
};
