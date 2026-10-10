<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const REVISED = ['TicketList', 'TicketShow', 'TicketCreate', 'TicketUpdate', 'TicketComment', 'TicketReopen', 'TicketAttachmentAdd', 'TicketAttachmentGet', 'TicketAttachmentList', 'TicketReport', 'TicketNotifications', 'TicketNotificationRead', 'AdminUsers', 'AdminUserRole', 'AdminCategorySave', 'AdminAudit'];

    private function install(string $folder, string $name): void
    {
        $sql = file_get_contents(database_path("procedures/$folder/$name.sql"));
        if ($sql === false) {
            throw new RuntimeException("Missing procedure $name");
        }
        DB::connection()->getPdo()->exec("DROP PROCEDURE IF EXISTS `$name`");
        DB::connection()->getPdo()->exec($sql);
    }

    public function up(): void
    {
        if (! Schema::hasTable('permission_roles')) {
            Schema::create('permission_roles', function (Blueprint $table) {
                $table->string('role', 20)->primary();
                $table->unsignedInteger('version')->default(1);
            });
        }
        if (! Schema::hasTable('role_permissions')) {
            Schema::create('role_permissions', function (Blueprint $table) {
                $table->string('role', 20);
                $table->string('permission', 80);
                $table->boolean('allowed')->default(true);
                $table->primary(['role', 'permission']);
                $table->foreign('role')->references('role')->on('permission_roles');
            });
        }
        $common = ['tickets.view', 'tickets.create', 'tickets.comment', 'tickets.reopen', 'attachments.upload', 'attachments.download', 'reports.view', 'reports.export', 'notifications.view', 'notifications.read'];
        foreach (['requester', 'agent', 'admin'] as $role) {
            DB::table('permission_roles')->insertOrIgnore(['role' => $role, 'version' => 1]);
            $permissions = $common;
            if ($role !== 'requester') {
                $permissions = [...$permissions, 'tickets.update', 'tickets.internal'];
            }
            if ($role === 'admin') {
                $permissions = [...$permissions, 'users.view', 'users.roles', 'categories.manage', 'audit.view'];
            }
            foreach ($permissions as $permission) {
                DB::table('role_permissions')->insertOrIgnore(['role' => $role, 'permission' => $permission, 'allowed' => true]);
            }
        }
        if (DB::getDriverName() === 'mysql') {
            foreach (['PermissionRequire', 'PermissionList', 'PermissionSet', ...self::REVISED, 'TicketReportExport'] as $name) {
                $this->install('permissions', $name);
            }
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            foreach (self::REVISED as $name) {
                $this->install('superadmin', $name);
            }
            foreach (['TicketReportExport', 'PermissionSet', 'PermissionList', 'PermissionRequire'] as $name) {
                DB::connection()->getPdo()->exec("DROP PROCEDURE IF EXISTS `$name`");
            }
        }
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('permission_roles');
    }
};
