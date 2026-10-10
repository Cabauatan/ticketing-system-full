<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_assets', function (Blueprint $table) {
            $table->string('division', 120)->nullable();
        });
        $this->install('division');
    }

    public function down(): void
    {
        $this->install('inventory');
        Schema::table('inventory_assets', function (Blueprint $table) {
            $table->dropColumn('division');
        });
    }

    private function install(string $folder): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }
        foreach (['AssetSave', 'AssetMove', 'AssetList', 'AssetAudit'] as $name) {
            $sql = file_get_contents(database_path("procedures/$folder/$name.sql"));
            if ($sql === false) {
                throw new RuntimeException("Missing procedure $name");
            }
            DB::connection()->getPdo()->exec("DROP PROCEDURE IF EXISTS `$name`");
            DB::connection()->getPdo()->exec($sql);
        }
    }
};
