<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_roles', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('role', 20);
            $table->primary(['user_id', 'role']);
            $table->index('role');
        });

        if (DB::getDriverName() === 'mysql') {
            foreach ([
                'PermissionRequire' => 'permissions',
                'AdminUsers' => 'permissions',
                'AdminUserRole' => 'multiagent',
                'AssetRequire' => 'inventory',
            ] as $name => $folder) {
                $sql = file_get_contents(database_path("procedures/$folder/$name.sql"));
                if ($sql === false) {
                    throw new RuntimeException("Missing procedure $name");
                }
                DB::connection()->getPdo()->exec("DROP PROCEDURE IF EXISTS `$name`");
                DB::connection()->getPdo()->exec($sql);
            }
        }
    }

    public function down(): void
    {
        // Role membership is part of authorization; removing it without first
        // reverting the matching application code would break permission checks.
    }
};
