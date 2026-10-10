<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const PROCEDURES = [
        'PermissionSet' => 'inventory',
        'AdminUserRole' => 'multiagent',
        'TicketAgents' => 'superadmin',
        'TicketList' => 'multiagent',
        'TicketShow' => 'permissions',
        'TicketUpdateAgents' => 'multiagent',
        'TicketUpdate' => 'multiagent',
        'TicketCreate' => 'multiagent',
        'TicketComment' => 'multiagent',
        'TicketReopen' => 'permissions',
        'TicketReport' => 'permissions',
        'AdminCategorySave' => 'permissions',
        'TicketEscalate' => 'superadmin',
        'TicketAttachmentAdd' => 'permissions',
        'TicketAttachmentList' => 'permissions',
        'TicketAttachmentGet' => 'permissions',
        'TicketNotifications' => 'permissions',
        'TicketNotificationRead' => 'permissions',
        'TicketChatRead' => 'chat',
        'TicketChatPresence' => 'chat',
        'TicketAnalytics' => 'reports',
    ];

    private function install(string $name, string $folder): void
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
        DB::table('permission_roles')->insertOrIgnore(['role' => 'dispatcher', 'version' => 1]);

        $permissions = [
            'tickets.view', 'tickets.update', 'tickets.comment', 'tickets.internal',
            'attachments.upload', 'attachments.download', 'reports.view', 'reports.export',
            'notifications.view', 'notifications.read',
        ];
        foreach ($permissions as $permission) {
            DB::table('role_permissions')->insertOrIgnore([
                'role' => 'dispatcher', 'permission' => $permission, 'allowed' => true,
            ]);
        }

        if (DB::getDriverName() === 'mysql') {
            foreach (self::PROCEDURES as $name => $folder) {
                $this->install($name, $folder);
            }
        }
    }

    public function down(): void
    {
        DB::table('users')->where('role', 'dispatcher')->update(['role' => 'agent']);
        DB::table('role_permissions')->where('role', 'dispatcher')->delete();
        DB::table('permission_roles')->where('role', 'dispatcher')->delete();
    }
};
