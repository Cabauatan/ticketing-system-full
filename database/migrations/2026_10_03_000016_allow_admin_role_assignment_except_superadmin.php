<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Role management is a core Admin capability. Restore it for databases
        // where the permission was removed, and bump the version for stale forms.
        DB::table('role_permissions')->updateOrInsert(
            ['role' => 'admin', 'permission' => 'users.roles'],
            ['allowed' => true],
        );
        DB::table('permission_roles')->where('role', 'admin')->increment('version');

        if (DB::getDriverName() === 'mysql') {
            $sql = file_get_contents(database_path('procedures/multiagent/AdminUserRole.sql'));
            if ($sql === false) {
                throw new RuntimeException('Missing procedure AdminUserRole');
            }
            DB::connection()->getPdo()->exec('DROP PROCEDURE IF EXISTS `AdminUserRole`');
            DB::connection()->getPdo()->exec($sql);
        }
    }

    public function down(): void
    {
        // Keep the strengthened role boundary and permission in place on rollback.
    }
};
