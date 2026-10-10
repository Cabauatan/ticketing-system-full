<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const BASE = ['TicketList', 'TicketAgents', 'TicketAttachmentAdd', 'TicketAttachmentList', 'TicketAttachmentGet'];

    private const COMPLETE = ['TicketShow', 'TicketCreate', 'TicketUpdate', 'TicketComment', 'TicketReport', 'TicketNotifications', 'TicketNotificationRead', 'TicketCategories', 'AdminUsers', 'AdminUserRole', 'AdminCategorySave', 'AdminAudit', 'AdminBootstrap', 'TicketEscalate', 'TicketReopen'];

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
        if (DB::getDriverName() !== 'mysql') {
            return;
        }
        foreach ([...self::BASE, ...self::COMPLETE, 'SuperadminBootstrap'] as $name) {
            $this->install('superadmin/', $name);
        }
    }

    public function down(): void
    {
        // Never leave accounts with a role unsupported by the restored procedures.
        DB::table('users')->where('role', 'superadmin')->update(['role' => 'admin']);
        if (DB::getDriverName() !== 'mysql') {
            return;
        }
        foreach (self::BASE as $name) {
            $this->install('', $name);
        }
        foreach (self::COMPLETE as $name) {
            $this->install('complete/', $name);
        }
        DB::connection()->getPdo()->exec('DROP PROCEDURE IF EXISTS SuperadminBootstrap');
    }
};
