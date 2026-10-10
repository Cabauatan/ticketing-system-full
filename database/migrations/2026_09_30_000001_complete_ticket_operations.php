<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const NEW_PROCEDURES = ['TicketCategories', 'AdminUsers', 'AdminUserRole', 'AdminCategorySave', 'AdminAudit', 'AdminBootstrap', 'TicketEscalate', 'TicketReopen'];

    private function install(string $folder, string $name): void
    {
        $sql = file_get_contents(database_path("procedures/$folder$name.sql"));
        if ($sql === false) {
            throw new RuntimeException("Missing procedure $name");
        }
        DB::connection()->getPdo()->exec("DROP PROCEDURE IF EXISTS `$name`");
        DB::connection()->getPdo()->exec($sql);
    }

    public function up(): void
    {
        if (! Schema::hasTable('ticket_categories')) {
            Schema::create('ticket_categories', function (Blueprint $table) {
                $table->string('slug', 40)->primary();
                $table->string('name', 80);
                $table->boolean('active')->default(true);
                $table->foreignId('default_assignee_id')->nullable()->constrained('users');
            });
        }
        foreach (['hardware', 'software', 'network', 'access', 'other'] as $slug) {
            DB::table('ticket_categories')->updateOrInsert(['slug' => $slug], ['name' => ucfirst($slug), 'active' => true]);
        }
        if (! Schema::hasTable('admin_audit')) {
            Schema::create('admin_audit', function (Blueprint $table) {
                $table->id();
                $table->foreignId('actor_id')->constrained('users');
                $table->string('action', 40);
                $table->string('detail', 500);
                $table->timestamp('created_at');
            });
        }
        if (! Schema::hasTable('ticket_admin_lock')) {
            Schema::create('ticket_admin_lock', function (Blueprint $table) {
                $table->integer('id')->primary();
            });
        }
        DB::table('ticket_admin_lock')->insertOrIgnore(['id' => 1]);
        if (! Schema::hasTable('ticket_escalations')) {
            Schema::create('ticket_escalations', function (Blueprint $table) {
                $table->id();
                $table->foreignId('ticket_id')->constrained();
                $table->dateTime('due_at');
                $table->timestamp('created_at');
                $table->unique(['ticket_id', 'due_at']);
            });
        }
        Schema::table('ticket_events', fn (Blueprint $table) => $table->unsignedBigInteger('actor_id')->nullable()->change());
        if (! Schema::hasColumn('ticket_notifications', 'staff_only')) {
            Schema::table('ticket_notifications', fn (Blueprint $table) => $table->boolean('staff_only')->default(false));
        }
        if (DB::getDriverName() === 'mysql') {
            DB::statement("UPDATE ticket_notifications SET staff_only = 1 WHERE message = 'A new internal note was added.'");
            foreach ([...self::NEW_PROCEDURES, 'TicketCreate', 'TicketUpdate', 'TicketComment', 'TicketShow', 'TicketReport', 'TicketNotifications', 'TicketNotificationRead'] as $name) {
                $this->install('complete/', $name);
            }
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            foreach (self::NEW_PROCEDURES as $name) {
                DB::connection()->getPdo()->exec("DROP PROCEDURE IF EXISTS `$name`");
            }
            foreach (['TicketCreate', 'TicketUpdate', 'TicketComment', 'TicketNotifications', 'TicketNotificationRead'] as $name) {
                $this->install('sla/', $name);
            }
            foreach (['TicketShow', 'TicketReport'] as $name) {
                $this->install('', $name);
            }
        }
        // Preserve system audit rows and nullable actor IDs on rollback.
        Schema::table('ticket_notifications', fn (Blueprint $table) => $table->dropColumn('staff_only'));
        Schema::dropIfExists('ticket_escalations');
        Schema::dropIfExists('admin_audit');
        Schema::dropIfExists('ticket_admin_lock');
        Schema::dropIfExists('ticket_categories');
    }
};
