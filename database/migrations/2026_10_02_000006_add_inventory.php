<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PROCEDURES = ['AssetRequire', 'AssetAudit', 'AssetList', 'AssetShow', 'AssetSave', 'AssetMove', 'AssetTicketLink', 'TicketAssets', 'AssetExport'];

    public function up(): void
    {
        Schema::create('inventory_assets', function (Blueprint $table) {
            $table->id();
            $table->string('asset_tag', 60)->unique();
            $table->string('serial_number', 120)->nullable()->unique();
            $table->string('name', 160);
            $table->string('type', 30);
            $table->string('brand', 80)->nullable();
            $table->string('model', 120)->nullable();
            $table->text('specifications')->nullable();
            $table->date('purchase_date')->nullable();
            $table->date('warranty_until')->nullable();
            $table->string('status', 20)->default('available')->index();
            $table->string('holder_name', 160)->nullable();
            $table->string('employee_code', 80)->nullable();
            $table->string('department', 120)->nullable();
            $table->string('location', 160)->nullable();
            $table->text('accessories')->nullable();
            $table->dateTime('deployed_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });
        Schema::create('inventory_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained('inventory_assets')->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 30);
            $table->text('note')->nullable();
            $table->json('snapshot');
            $table->dateTime('created_at');
        });
        Schema::create('inventory_ticket', function (Blueprint $table) {
            $table->foreignId('asset_id')->constrained('inventory_assets')->cascadeOnDelete();
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $table->primary(['asset_id', 'ticket_id']);
        });
        foreach (['agent', 'admin'] as $role) {
            foreach (['inventory.view', 'inventory.manage', 'inventory.export'] as $permission) {
                DB::table('role_permissions')->insert(['role' => $role, 'permission' => $permission, 'allowed' => true]);
            }
        }
        // Invalidate permission editors opened before the catalog changed.
        DB::table('permission_roles')->whereIn('role', ['agent', 'admin'])->increment('version');
        if (DB::getDriverName() === 'mysql') {
            foreach ([...self::PROCEDURES, 'PermissionSet'] as $name) {
                $this->install($name, 'inventory');
            }
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            foreach (array_reverse(self::PROCEDURES) as $name) {
                DB::connection()->getPdo()->exec("DROP PROCEDURE IF EXISTS `$name`");
            }
            $this->install('PermissionSet', 'permissions');
        }
        DB::table('role_permissions')->where('permission', 'like', 'inventory.%')->delete();
        DB::table('permission_roles')->whereIn('role', ['agent', 'admin'])->increment('version');
        Schema::dropIfExists('inventory_ticket');
        Schema::dropIfExists('inventory_events');
        Schema::dropIfExists('inventory_assets');
    }

    private function install(string $name, string $folder): void
    {
        $sql = file_get_contents(database_path("procedures/$folder/$name.sql"));
        if ($sql === false) {
            throw new RuntimeException("Missing procedure $name");
        }
        DB::connection()->getPdo()->exec("DROP PROCEDURE IF EXISTS `$name`");
        DB::connection()->getPdo()->exec($sql);
    }
};
