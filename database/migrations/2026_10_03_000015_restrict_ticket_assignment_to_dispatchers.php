<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['dispatcher', 'admin'] as $role) {
            DB::table('role_permissions')->insertOrIgnore([
                'role' => $role,
                'permission' => 'tickets.assign',
                'allowed' => true,
            ]);
            DB::table('permission_roles')->where('role', $role)->increment('version');
        }

        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        foreach ([
            'TicketShow' => 'permissions',
            'TicketUpdate' => 'permissions',
            'TicketUpdateAgents' => 'multiagent',
        ] as $name => $folder) {
            $sql = file_get_contents(database_path("procedures/$folder/$name.sql"));
            if ($sql === false) {
                throw new RuntimeException("Missing procedure $name");
            }
            DB::connection()->getPdo()->exec("DROP PROCEDURE IF EXISTS `$name`");
            DB::connection()->getPdo()->exec($sql);
        }
    }

    public function down(): void
    {
        DB::table('role_permissions')->whereIn('role', ['dispatcher', 'admin'])->where('permission', 'tickets.assign')->delete();
        DB::table('permission_roles')->whereIn('role', ['dispatcher', 'admin'])->increment('version');
    }
};
