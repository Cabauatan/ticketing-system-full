<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained();
            $table->foreignId('user_id')->constrained();
            $table->string('filename', 200);
            $table->string('path')->unique();
            $table->string('mime_type', 100);
            $table->unsignedInteger('size');
            $table->timestamp('created_at');
            $table->index(['ticket_id', 'id']);
        });
        if (DB::getDriverName() === 'mysql') {
            foreach (['TicketAttachmentAdd', 'TicketAttachmentList', 'TicketAttachmentGet'] as $name) {
                $sql = file_get_contents(database_path("procedures/$name.sql"));
                if ($sql === false) {
                    throw new RuntimeException("Cannot read procedure $name");
                }
                DB::unprepared("DROP PROCEDURE IF EXISTS `$name`");
                DB::connection()->getPdo()->exec($sql);
            }
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            foreach (['TicketAttachmentAdd', 'TicketAttachmentList', 'TicketAttachmentGet'] as $name) {
                DB::unprepared("DROP PROCEDURE IF EXISTS `$name`");
            }
        }
        Schema::dropIfExists('ticket_attachments');
    }
};
