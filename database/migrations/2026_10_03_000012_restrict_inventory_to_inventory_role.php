<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Inventory permissions belong to inventory managers and administrators.
        DB::table('role_permissions')
            ->where('role', 'agent')
            ->whereIn('permission', ['inventory.view', 'inventory.manage', 'inventory.export'])
            ->delete();

        DB::table('permission_roles')->whereIn('role', ['agent', 'admin', 'inventory_manager'])->increment('version');

        if (DB::getDriverName() === 'mysql') {
            $sql = file_get_contents(database_path('procedures/inventory/AssetRequire.sql'));
            if ($sql === false) {
                throw new RuntimeException('Missing procedure AssetRequire');
            }

            DB::connection()->getPdo()->exec('DROP PROCEDURE IF EXISTS `AssetRequire`');
            DB::connection()->getPdo()->exec($sql);
        }
    }

    public function down(): void
    {
        // Keep inventory private if this migration is rolled back. Restoring agent
        // access would violate the role boundary requested for the system.
    }
};
