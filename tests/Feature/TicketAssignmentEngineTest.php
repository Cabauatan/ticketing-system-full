<?php

namespace Tests\Feature;

use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Mockery;
use PDO;
use PDOException;
use Tests\TestCase;

class TicketAssignmentEngineTest extends TestCase
{
    private function migration(): Migration
    {
        return require database_path('migrations/2026_10_11_000002_use_innodb_ticket_assignment_temporary_table.php');
    }

    public function test_forward_migration_reinstalls_only_the_current_assignment_procedure(): void
    {
        $sql = file_get_contents(database_path('procedures/multi_role_access/TicketUpdateAgents.sql'));
        $this->assertIsString($sql);
        $this->assertStringContainsString('ENGINE=InnoDB', $sql);
        $pdo = Mockery::mock(PDO::class);
        $pdo->shouldReceive('exec')->once()->ordered()
            ->with('DROP PROCEDURE IF EXISTS `TicketUpdateAgents`')->andReturn(0);
        $pdo->shouldReceive('exec')->once()->ordered()->with($sql)->andReturn(0);
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('getPdo')->once()->andReturn($pdo);
        DB::shouldReceive('getDriverName')->once()->andReturn('mysql');
        DB::shouldReceive('connection')->once()->andReturn($connection);

        $this->migration()->up();
    }

    public function test_forward_migration_skips_non_mysql_connections(): void
    {
        DB::shouldReceive('getDriverName')->once()->andReturn('sqlite');
        DB::shouldReceive('connection')->never();

        $this->migration()->up();
    }

    public function test_assignment_upgrade_and_rollback_work_with_memory_disabled(): void
    {
        // Opt-in only: this test never reads the application's MySQL endpoint.
        $port = getenv('QA_MYSQL_ENGINE_PORT');
        if ($port === false) {
            $this->markTestSkipped('Set QA_MYSQL_ENGINE_PORT to an isolated loopback MySQL with MEMORY disabled.');
        }
        $this->assertTrue(ctype_digit($port) && (int) $port > 0 && (int) $port <= 65535);
        $settings = [
            'driver' => 'mysql', 'host' => '127.0.0.1', 'port' => (int) $port,
            'username' => getenv('QA_MYSQL_ENGINE_USER') ?: 'root',
            'password' => getenv('QA_MYSQL_ENGINE_PASSWORD') ?: '',
            'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '', 'strict' => true, 'options' => [PDO::ATTR_TIMEOUT => 3],
        ];
        $server = new PDO("mysql:host=127.0.0.1;port=$port;charset=utf8mb4", $settings['username'], $settings['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 3,
        ]);
        $disabled = $server->query('SELECT @@disabled_storage_engines')->fetchColumn();
        $this->assertContains('MEMORY', array_map('strtoupper', explode(',', (string) $disabled)));
        $schema = 'ticketing_test_assignment_engine_'.bin2hex(random_bytes(6));
        $server->exec("CREATE DATABASE `$schema` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        try {
            config([
                'database.default' => 'assignment_engine_test',
                'database.connections.assignment_engine_test' => [...$settings, 'database' => $schema],
                'broadcasting.default' => 'null', 'cache.default' => 'array',
                'session.driver' => 'array', 'queue.default' => 'sync',
            ]);
            $this->assertSame(0, Artisan::call('migrate', ['--database' => 'assignment_engine_test', '--force' => true]));
            $pdo = DB::connection()->getPdo();
            $source = file_get_contents(database_path('procedures/multi_role_access/TicketUpdateAgents.sql'));
            $this->assertIsString($source);
            $pdo->exec('DROP PROCEDURE IF EXISTS `TicketUpdateAgents`');
            $pdo->exec(str_replace('ENGINE=InnoDB', 'ENGINE=MEMORY', $source));
            $ids = [];
            foreach (['admin', 'requester', 'agent', 'requester'] as $role) {
                $ids[] = DB::table('users')->insertGetId([
                    'name' => 'Assignment engine fixture', 'email' => bin2hex(random_bytes(8)).'@example.test',
                    'password' => 'unused-test-password', 'role' => $role,
                    'created_at' => now(), 'updated_at' => now(), 'email_verified_at' => now(),
                ]);
            }
            [$admin, $owner, $agent, $additional] = $ids;
            DB::table('user_roles')->insert(['user_id' => $additional, 'role' => 'agent']);
            $ticket = DB::table('tickets')->insertGetId([
                'requester_id' => $owner, 'subject' => 'Assignment engine fixture', 'description' => 'QA',
                'category' => 'hardware', 'priority' => 'low', 'status' => 'new', 'version' => 1,
                'created_at' => now(), 'updated_at' => now(), 'due_at' => now()->addHours(72),
            ]);
            $call = function (int $version, string $status, array $assignees) use ($pdo, $admin, $ticket): void {
                $statement = $pdo->prepare('CALL TicketUpdateAgents(?, ?, ?, ?, ?, ?)');
                $statement->execute([$admin, $ticket, $version, $status, 'medium', json_encode($assignees, JSON_THROW_ON_ERROR)]);
                while ($statement->nextRowset()) {
                    // Consume every CALL result before reusing the connection.
                }
                $statement->closeCursor();
            };
            $expectError = function (callable $attempt, int $code): void {
                try {
                    $attempt();
                    $this->fail('Expected procedure rejection.');
                } catch (PDOException $exception) {
                    $this->assertSame($code, (int) ($exception->errorInfo[1] ?? 0));
                }
            };
            $snapshot = static fn (): array => [
                DB::table('tickets')->get()->toArray(), DB::table('ticket_assignees')->get()->toArray(),
                DB::table('ticket_notifications')->get()->toArray(), DB::table('ticket_events')->get()->toArray(),
            ];
            $before = $snapshot();
            $expectError(fn () => $call(1, 'open', [$agent, $additional]), 3161);
            $this->assertEquals($before, $snapshot());

            $this->migration()->up();
            $this->assertEquals($before, $snapshot());
            $call(1, 'open', [$agent, $additional]);
            $current = DB::table('tickets')->where('id', $ticket)->first();
            $this->assertSame('open', $current->status);
            $this->assertSame('medium', $current->priority);
            $this->assertSame(2, (int) $current->version);
            $this->assertSame($agent, (int) $current->assignee_id);
            $this->assertSame([$agent, $additional], array_map('intval', DB::table('ticket_assignees')->orderBy('user_id')->pluck('user_id')->all()));
            $this->assertSame(3, DB::table('ticket_notifications')->count());
            $this->assertSame(1, DB::table('ticket_events')->count());
            $expectError(fn () => $pdo->query('SELECT * FROM tmp_ticket_agents'), 1146);

            // Fail after ticket/notification writes to prove transactional rollback.
            $pdo->exec("CREATE TRIGGER qa_reject_ticket_event BEFORE INSERT ON ticket_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MYSQL_ERRNO = 50004, MESSAGE_TEXT = 'QA audit failure'");
            $committed = $snapshot();
            $expectError(fn () => $call(2, 'in_progress', [$agent, $additional]), 50004);
            $this->assertEquals($committed, $snapshot());
            $expectError(fn () => $pdo->query('SELECT * FROM tmp_ticket_agents'), 1146);
            $pdo->exec('DROP TRIGGER qa_reject_ticket_event');

            $expectError(fn () => $call(2, 'in_progress', [$agent, $agent]), 50004);
            $this->assertEquals($committed, $snapshot());
            $expectError(fn () => $pdo->query('SELECT * FROM tmp_ticket_agents'), 1146);
            $call(2, 'in_progress', [$additional]);
            $this->assertSame('in_progress', DB::table('tickets')->where('id', $ticket)->value('status'));
            $this->assertSame([$additional], array_map('intval', DB::table('ticket_assignees')->pluck('user_id')->all()));
        } finally {
            DB::disconnect('assignment_engine_test');
            $server->exec("DROP DATABASE `$schema`");
        }
    }
}
