<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const PROCEDURES = [
        'TicketList', 'TicketShow', 'TicketAgents', 'TicketCreate', 'TicketUpdateAgents',
        'TicketComment', 'TicketAttachmentAdd', 'TicketAttachmentList', 'TicketAttachmentGet',
        'TicketNotifications', 'TicketNotificationRead', 'TicketChatRead', 'TicketChatPresence',
        'TicketReport', 'TicketAnalytics', 'AdminCategorySave', 'AdminUsers', 'AdminAudit',
        'TicketCategories', 'TicketAssets', 'AssetTicketLink', 'AssetShow', 'TicketReopen', 'TicketEscalate',
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        // Validate every source before replacing any deployed procedure.
        $sources = [];
        foreach (self::PROCEDURES as $name) {
            $sql = file_get_contents(database_path("procedures/multi_role_access/$name.sql"));
            if ($sql === false) {
                throw new RuntimeException("Missing multi-role procedure $name");
            }
            $sources[$name] = $sql;
        }

        foreach ($sources as $name => $sql) {
            DB::connection()->getPdo()->exec("DROP PROCEDURE IF EXISTS `$name`");
            DB::connection()->getPdo()->exec($sql);
        }
    }

    public function down(): void
    {
        // Preserve primary-plus-additional role authorization when rolling back.
        // Reinstalling the previous bodies would silently remove existing access.
    }
};
