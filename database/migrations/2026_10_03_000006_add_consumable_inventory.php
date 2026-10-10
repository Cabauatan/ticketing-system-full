<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const PROCEDURES = [
        'AssetSave' => 'purchasing',
        'AssetList' => 'lifecycle',
        'AssetMove' => 'lifecycle',
        'AssetStockMove' => 'purchasing',
    ];

    public function up(): void
    {
        $this->install(self::PROCEDURES);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::connection()->getPdo()->exec('DROP PROCEDURE IF EXISTS `AssetStockMove`');
        $this->install([
            'AssetSave' => 'rollback_consumables',
            'AssetList' => 'rollback_consumables',
            'AssetMove' => 'rollback_consumables',
        ]);
    }

    /** @param array<string, string> $procedures */
    private function install(array $procedures): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        foreach ($procedures as $name => $folder) {
            $sql = file_get_contents(database_path("procedures/$folder/$name.sql"));
            if ($sql === false) {
                throw new RuntimeException("Missing procedure $name");
            }
            DB::connection()->getPdo()->exec("DROP PROCEDURE IF EXISTS `$name`");
            DB::connection()->getPdo()->exec($sql);
        }
    }
};
