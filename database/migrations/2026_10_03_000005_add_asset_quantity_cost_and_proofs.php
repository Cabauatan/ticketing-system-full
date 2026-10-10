<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PROCEDURES = ['AssetAttachmentAdd', 'AssetAttachmentList', 'AssetAttachmentGet'];

    public function up(): void
    {
        Schema::table('inventory_assets', function (Blueprint $table) {
            $table->unsignedInteger('quantity')->default(1);
            $table->decimal('unit_cost', 12, 2)->nullable();
        });

        Schema::create('inventory_asset_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained('inventory_assets')->cascadeOnDelete();
            $table->foreignId('uploader_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('filename', 200);
            $table->string('path', 500)->unique();
            $table->string('mime_type', 120)->nullable();
            $table->unsignedBigInteger('size');
            $table->dateTime('created_at');
            $table->index(['asset_id', 'id']);
        });

        if (DB::getDriverName() === 'mysql') {
            $this->install('purchasing', 'AssetSave');
            $this->install('purchasing', 'AssetAudit');
            foreach (self::PROCEDURES as $name) {
                $this->install('purchasing', $name);
            }
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            foreach (self::PROCEDURES as $name) {
                DB::connection()->getPdo()->exec("DROP PROCEDURE IF EXISTS `$name`");
            }
            $this->install('division', 'AssetSave');
            $this->install('division', 'AssetAudit');
        }

        Schema::dropIfExists('inventory_asset_attachments');
        Schema::table('inventory_assets', function (Blueprint $table) {
            $table->dropColumn(['quantity', 'unit_cost']);
        });
    }

    private function install(string $folder, string $name): void
    {
        $sql = file_get_contents(database_path("procedures/$folder/$name.sql"));
        if ($sql === false) {
            throw new RuntimeException("Missing procedure $name");
        }
        DB::connection()->getPdo()->exec("DROP PROCEDURE IF EXISTS `$name`");
        DB::connection()->getPdo()->exec($sql);
    }
};
