<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ApplicationHealthTest extends TestCase
{
    public function test_health_fails_when_database_session_table_is_missing(): void
    {
        config(['session.driver' => 'database']);
        $this->get('/up')->assertStatus(500);
    }

    public function test_health_fails_when_database_cache_table_is_missing(): void
    {
        config(['cache.default' => 'database']);
        $this->get('/up')->assertStatus(500);
    }

    public function test_health_fails_when_database_cache_locks_table_is_missing(): void
    {
        config(['cache.default' => 'database']);
        Schema::create('cache', fn (Blueprint $table) => $table->string('key')->primary());
        $this->get('/up')->assertStatus(500);
    }

    public function test_health_passes_when_required_database_storage_is_available(): void
    {
        config(['session.driver' => 'database', 'cache.default' => 'database']);
        foreach (['sessions', 'cache', 'cache_locks'] as $name) {
            Schema::create($name, fn (Blueprint $table) => $table->string('id')->primary());
        }
        $this->get('/up')->assertOk();
    }

    public function test_health_does_not_require_database_tables_for_file_and_array_storage(): void
    {
        config(['session.driver' => 'file', 'cache.default' => 'array']);
        $this->get('/up')->assertOk();
    }
}
