<?php

namespace Tests\Feature;

use App\Listeners\CheckApplicationStorage;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class ApplicationStorageCommandTest extends TestCase
{
    public function test_failed_database_session_readiness_stops_startup_with_a_useful_exception(): void
    {
        config(['session.driver' => 'database', 'cache.default' => 'array']);

        $this->artisan('app:check-storage')
            ->expectsOutputToContain('Application storage readiness failed.')
            ->expectsOutputToContain('no such table: sessions')
            ->assertExitCode(1);
    }

    public function test_configured_database_storage_passes_without_reading_values(): void
    {
        config(['session.driver' => 'database', 'cache.default' => 'database']);
        Schema::create('sessions', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->text('payload');
            $table->integer('last_activity');
        });
        Schema::create('cache', function (Blueprint $table): void {
            $table->string('key')->primary();
            $table->text('value');
            $table->integer('expiration');
        });
        Schema::create('cache_locks', function (Blueprint $table): void {
            $table->string('key')->primary();
            $table->string('owner');
            $table->integer('expiration');
        });

        $this->artisan('app:check-storage')
            ->expectsOutput('Application storage readiness passed.')
            ->assertExitCode(0);
    }

    public function test_it_checks_the_configured_session_connection(): void
    {
        config([
            'session.driver' => 'database',
            'session.connection' => 'session_fixture',
            'cache.default' => 'array',
            'database.connections.session_fixture' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ],
        ]);
        Schema::create('sessions', fn (Blueprint $table) => $table->string('id')->primary());

        $this->artisan('app:check-storage')
            ->expectsOutputToContain('Application storage readiness failed.')
            ->expectsOutputToContain('no such table: sessions')
            ->assertExitCode(1);
    }

    public function test_diagnostics_redact_configured_keys_database_passwords_and_urls(): void
    {
        config([
            'app.key' => 'test-current-encryption-key',
            'app.previous_keys' => ['test-previous-encryption-key'],
            'database.connections.mysql.password' => 'test-password/+?',
            'database.connections.mysql.url' => 'mysql://fixture:other-password@localhost/fixture',
        ]);
        $this->mock(CheckApplicationStorage::class, function ($mock): void {
            $mock->shouldReceive('handle')->once()->andThrow(new RuntimeException(
                'Test failure: test-current-encryption-key test-previous-encryption-key test-password/+? test-password%2F%2B%3F mysql://fixture:other-password@localhost/fixture',
            ));
        });

        $this->artisan('app:check-storage')
            ->expectsOutputToContain('Test failure: [redacted] [redacted] [redacted] [redacted] [redacted]')
            ->assertExitCode(1);
    }
}
