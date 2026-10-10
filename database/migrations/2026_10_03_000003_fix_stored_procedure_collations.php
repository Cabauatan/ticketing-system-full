<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const PROCEDURES = [
        'AdminBootstrap' => 'superadmin',
        'AdminCategorySave' => 'permissions',
        'AdminUserRole' => 'multiagent',
        'AdminUsers' => 'permissions',
        'AssetAudit' => 'division',
        'AssetExport' => 'inventory',
        'AssetList' => 'lifecycle',
        'AssetMove' => 'lifecycle',
        'AssetRequire' => 'inventory',
        'PermissionRequire' => 'permissions',
        'PermissionSet' => 'inventory',
        'SuperadminBootstrap' => 'superadmin',
        'TicketAttachmentAdd' => 'permissions',
        'TicketComment' => 'multiagent',
        'TicketCreate' => 'multiagent',
        'TicketList' => 'multiagent',
        'TicketUpdate' => 'multiagent',
        'TicketUpdateAgents' => 'multiagent',
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        foreach (self::PROCEDURES as $name => $folder) {
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
        // The explicit parameter collations preserve the existing procedure behavior.
    }
};
