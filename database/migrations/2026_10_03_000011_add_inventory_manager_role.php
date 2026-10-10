<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('permission_roles')->insertOrIgnore(['role' => 'inventory_manager', 'version' => 1]);

        foreach ([
            'reports.view', 'reports.export',
            'inventory.view', 'inventory.manage', 'inventory.export',
        ] as $permission) {
            DB::table('role_permissions')->insertOrIgnore([
                'role' => 'inventory_manager', 'permission' => $permission, 'allowed' => true,
            ]);
        }

        if (DB::getDriverName() === 'mysql') {
            foreach ([
                'PermissionSet' => 'inventory',
                'AdminUserRole' => 'multiagent',
                'AssetRequire' => 'inventory',
                'AssetShow' => 'inventory',
                'AssetTicketLink' => 'inventory',
                'TicketAssets' => 'inventory',
            ] as $name => $folder) {
                $sql = file_get_contents(database_path("procedures/$folder/$name.sql"));
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
        DB::table('users')->where('role', 'inventory_manager')->update(['role' => 'requester']);
        DB::table('role_permissions')->where('role', 'inventory_manager')->delete();
        DB::table('permission_roles')->where('role', 'inventory_manager')->delete();
    }
};
