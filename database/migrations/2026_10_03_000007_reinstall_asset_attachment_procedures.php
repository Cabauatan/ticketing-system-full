<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const PROCEDURES = ['AssetAttachmentAdd', 'AssetAttachmentList', 'AssetAttachmentGet'];

    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        foreach (self::PROCEDURES as $name) {
            $sql = file_get_contents(database_path("procedures/purchasing/$name.sql"));
            if ($sql === false) {
                throw new RuntimeException("Missing procedure $name");
            }

            DB::connection()->getPdo()->exec("DROP PROCEDURE IF EXISTS `$name`");
            DB::connection()->getPdo()->exec($sql);
        }
    }

    public function down(): void
    {
        // The original inventory proof migration owns these procedures.
    }
};
