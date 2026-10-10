<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->install('lifecycle');
    }

    public function down(): void
    {
        DB::table('inventory_assets')->where('status', 'returned')->update(['status' => 'available']);
        DB::table('inventory_assets')->where('status', 'damaged')->update(['status' => 'repair']);
        DB::table('inventory_assets')->where('status', 'replaced')->update(['status' => 'retired']);
        $this->install('division');
    }

    private function install(string $folder): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }
        foreach (['AssetMove', 'AssetList'] as $name) {
            $sql = file_get_contents(database_path("procedures/$folder/$name.sql"));
            if ($sql === false) {
                throw new RuntimeException("Missing procedure $name");
            }
            DB::connection()->getPdo()->exec("DROP PROCEDURE IF EXISTS `$name`");
            DB::connection()->getPdo()->exec($sql);
        }
    }
};
