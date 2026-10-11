<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\TicketRealtime;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;
use Ticketing\Deployment\AivenSeedException;

use function Ticketing\Deployment\aivenSeedEnvironment;
use function Ticketing\Deployment\applyAivenSeedEnvironment;
use function Ticketing\Deployment\assertAivenUserSchema;
use function Ticketing\Deployment\seedVerifiedSuperadmin;

class AivenSuperadminSeedTest extends TestCase
{
    private array $seedEnvironmentBefore = [];

    protected function setUp(): void
    {
        parent::setUp();
        require_once base_path('scripts/seed-aiven.php');
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('role')->default('requester');
            $table->timestamp('email_verified_at')->nullable();
            $table->string('two_factor_secret')->nullable();
            $table->timestamps();
        });
        Schema::create('user_roles', function (Blueprint $table): void {
            $table->unsignedBigInteger('user_id');
            $table->string('role');
        });
        foreach (['SUPERADMIN_EMAIL', 'SUPERADMIN_PASSWORD', 'SUPERADMIN_NAME'] as $key) {
            $this->seedEnvironmentBefore[$key] = [getenv($key), $_ENV[$key] ?? null, $_SERVER[$key] ?? null];
        }
        applyAivenSeedEnvironment([
            'SUPERADMIN_EMAIL' => 'cloud-admin@example.com',
            'SUPERADMIN_PASSWORD' => 'Cloud-test-pass_26',
            'SUPERADMIN_NAME' => 'Cloud Administrator',
        ]);
    }

    protected function tearDown(): void
    {
        foreach ($this->seedEnvironmentBefore as $key => [$processValue, $envValue, $serverValue]) {
            putenv($processValue === false ? $key : $key.'='.$processValue);
            if ($envValue === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $envValue;
            }
            if ($serverValue === null) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $serverValue;
            }
        }
        parent::tearDown();
    }

    public function test_cloud_target_overrides_connection_urls_ca_aliases_and_cached_configuration(): void
    {
        $environment = aivenSeedEnvironment([
            'DB_HOST' => 'mysql-example.g.aivencloud.com', 'DB_PORT' => '14717',
            'DB_DATABASE' => 'defaultdb', 'DB_USERNAME' => 'avnadmin',
            'DB_URL' => 'sqlite://local.sqlite', 'DB_CONNECTION' => 'sqlite',
            'MYSQL_ATTR_SSL_CA' => '/etc/secrets/ca.pem', 'APP_CONFIG_CACHE' => 'bootstrap/cache/config.php',
        ], __FILE__, 'cloud-admin@example.com', 'Cloud-test-pass_26', 'database-secret', 'Cloud Administrator');

        $this->assertSame('mysql', $environment['DB_CONNECTION']);
        $this->assertSame('', $environment['DB_URL']);
        $this->assertSame(__FILE__, $environment['DB_SSL_CA']);
        $this->assertSame(__FILE__, $environment['MYSQL_ATTR_SSL_CA']);
        $this->assertSame('database-secret', $environment['DB_PASSWORD']);
        $this->assertSame('production', $environment['APP_ENV']);
        $this->assertSame('false', $environment['RUN_MIGRATIONS']);
        $this->assertSame('null', $environment['BROADCAST_CONNECTION']);
        $this->assertStringStartsWith('.runtime/aiven-seed-config-', $environment['APP_CONFIG_CACHE']);
        $this->assertFileDoesNotExist(base_path($environment['APP_CONFIG_CACHE']));
    }

    public function test_seed_rejects_a_local_database_target(): void
    {
        $this->expectException(AivenSeedException::class);
        aivenSeedEnvironment([
            'DB_HOST' => '127.0.0.1', 'DB_PORT' => '3306',
            'DB_DATABASE' => 'ticketing', 'DB_USERNAME' => 'root',
        ], __FILE__, 'cloud-admin@example.com', 'Cloud-test-pass_26', 'database-secret', 'Cloud Administrator');
    }

    public function test_preflight_requires_the_role_membership_schema_needed_for_login(): void
    {
        assertAivenUserSchema(DB::connection());
        Schema::drop('user_roles');

        $this->expectException(AivenSeedException::class);
        assertAivenUserSchema(DB::connection());
    }

    public function test_existing_seeder_creates_a_verified_superadmin_with_a_hashed_password_without_broadcasts(): void
    {
        $this->mock(TicketRealtime::class)->shouldNotReceive('afterWrite');
        $user = seedVerifiedSuperadmin($this->app, DB::connection(), 'cloud-admin@example.com', 'Cloud-test-pass_26');

        $this->assertSame('superadmin', $user->role);
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue(Hash::check('Cloud-test-pass_26', $user->password));
        $this->assertNotSame('Cloud-test-pass_26', $user->password);
        $this->assertSame('Cloud Administrator', $user->name);
        $this->assertSame(1, User::count());
    }

    public function test_existing_account_is_updated_once_and_other_accounts_and_two_factor_settings_are_preserved(): void
    {
        $existing = User::forceCreate([
            'name' => 'Existing User', 'email' => 'cloud-admin@example.com',
            'password' => 'Original-pass_26', 'role' => 'agent', 'two_factor_secret' => 'preserved-two-factor-data',
        ]);
        $other = User::forceCreate([
            'name' => 'Other User', 'email' => 'other@example.com', 'password' => 'Other-user-pass_26', 'role' => 'requester',
        ]);
        $otherAttributes = $other->fresh()->getAttributes();
        $user = seedVerifiedSuperadmin($this->app, DB::connection(), 'cloud-admin@example.com', 'Cloud-test-pass_26');

        $this->assertSame($existing->id, $user->id);
        $this->assertSame(2, User::count());
        $this->assertSame('superadmin', $user->role);
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue(Hash::check('Cloud-test-pass_26', $user->password));
        $this->assertSame('preserved-two-factor-data', $user->two_factor_secret);
        $this->assertSame($otherAttributes, $other->fresh()->getAttributes());
    }

    public function test_failure_setting_the_role_rolls_back_password_and_verification_changes(): void
    {
        $existing = User::forceCreate([
            'name' => 'Existing User', 'email' => 'cloud-admin@example.com',
            'password' => 'Original-pass_26', 'role' => 'requester',
        ]);
        DB::statement("CREATE TRIGGER reject_seed_role BEFORE UPDATE OF role ON users WHEN NEW.role = 'superadmin' BEGIN SELECT RAISE(ABORT, 'Role update rejected'); END");

        try {
            seedVerifiedSuperadmin($this->app, DB::connection(), 'cloud-admin@example.com', 'Cloud-test-pass_26');
            $this->fail('The role update must fail.');
        } catch (QueryException) {
            $user = $existing->fresh();
            $this->assertSame('requester', $user->role);
            $this->assertNull($user->email_verified_at);
            $this->assertSame('Existing User', $user->name);
            $this->assertTrue(Hash::check('Original-pass_26', $user->password));
        }
    }
}
