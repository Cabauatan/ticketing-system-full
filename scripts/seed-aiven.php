<?php

declare(strict_types=1);

namespace Ticketing\Deployment;

use App\Models\User;
use Database\Seeders\SuperadminSeeder;
use Dotenv\Dotenv;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Hash;
use PDO;
use Pdo\Mysql;
use PDOException;
use RuntimeException;
use Throwable;

class AivenSeedException extends RuntimeException {}

/** @param array<string, string> $settings
 * @return array<string, string>
 */
function aivenSeedEnvironment(array $settings, string $certificate, string $email, string $password, string $databasePassword, string $name): array
{
    $host = $settings['DB_HOST'] ?? '';
    $port = $settings['DB_PORT'] ?? '';
    $database = $settings['DB_DATABASE'] ?? '';
    $username = $settings['DB_USERNAME'] ?? '';

    if (! preg_match('/\A[a-z0-9][a-z0-9.-]+\.aivencloud\.com\z/i', $host)
        || ! ctype_digit($port) || (int) $port < 1 || (int) $port > 65535
        || ! preg_match('/\A[a-z0-9_.-]+\z/i', $database) || $username === '') {
        throw new AivenSeedException('Set the exact Aiven hostname, port, database and username in .env.render.local.');
    }
    if (! is_file($certificate) || ! is_readable($certificate) || filesize($certificate) === 0) {
        throw new AivenSeedException('Pass -CaPath with the readable, nonempty local ca.pem file.');
    }
    if (! filter_var($email, FILTER_VALIDATE_EMAIL) || trim($name) === '') {
        throw new AivenSeedException('A valid Superadmin email and nonempty name are required.');
    }
    if (strlen($password) < 12 || strlen($password) > 72) {
        throw new AivenSeedException('The Superadmin password must contain 12 to 72 bytes.');
    }
    if ($databasePassword === '') {
        throw new AivenSeedException('An Aiven database password is required. Run seed-aiven.ps1 for the hidden prompts.');
    }

    return array_replace($settings, [
        'APP_ENV' => 'production',
        'APP_DEBUG' => 'false',
        // Never load or replace the normal local Laravel configuration cache.
        'APP_CONFIG_CACHE' => '.runtime/aiven-seed-config-'.bin2hex(random_bytes(16)).'.php',
        'DB_CONNECTION' => 'mysql',
        'DB_URL' => '',
        'DB_HOST' => $host,
        'DB_PORT' => $port,
        'DB_DATABASE' => $database,
        'DB_USERNAME' => $username,
        'DB_PASSWORD' => $databasePassword,
        'DB_SOCKET' => '',
        'DB_SSL_CA' => $certificate,
        'MYSQL_ATTR_SSL_CA' => $certificate,
        'BROADCAST_CONNECTION' => 'null',
        'CACHE_STORE' => 'array',
        'SESSION_DRIVER' => 'array',
        'QUEUE_CONNECTION' => 'sync',
        'MAIL_MAILER' => 'array',
        'RUN_MIGRATIONS' => 'false',
        'SUPERADMIN_EMAIL' => $email,
        'SUPERADMIN_PASSWORD' => $password,
        'SUPERADMIN_NAME' => $name,
    ]);
}

/** @param array<string, string> $environment */
function applyAivenSeedEnvironment(array $environment): void
{
    foreach ($environment as $key => $value) {
        putenv($key.'='.$value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}

function assertAivenUserSchema(Connection $connection): void
{
    $schema = $connection->getSchemaBuilder();
    if (! $schema->hasColumns('users', ['id', 'name', 'role', 'email_verified_at', 'password', 'email', 'created_at', 'updated_at'])
        || ! $schema->hasColumns('user_roles', ['user_id', 'role'])) {
        throw new AivenSeedException('The Aiven account schema is incomplete. Finish migrations in Render first.');
    }
}

function seedVerifiedSuperadmin(Application $application, Connection $connection, string $email, string $password): User
{
    if ((new User)->getConnection() !== $connection) {
        throw new AivenSeedException('The account model must use the same database connection as the seed transaction.');
    }

    return $connection->transaction(fn (): User => Model::withoutEvents(fn (): User => Model::unguarded(function () use ($application, $email, $password): User {
        $application->make(SuperadminSeeder::class)->setContainer($application)->__invoke();
        $user = User::where('email', $email)->firstOrFail();

        if ($user->role !== 'superadmin' || $user->email_verified_at === null
            || ! Hash::check($password, $user->password)) {
            throw new AivenSeedException('Superadmin verification failed; account changes were rolled back.');
        }

        return $user;
    })));
}

function runAivenSuperadminSeed(): int
{
    $options = getopt('', ['env-file:', 'ca:', 'email:', 'name:', 'help']);
    if (isset($options['help'])) {
        echo 'Run scripts/seed-aiven.ps1 -CaPath "C:\path\to\ca.pem" -Email "admin@example.com" for hidden password prompts.', PHP_EOL;

        return 0;
    }

    try {
        $projectRoot = dirname(__DIR__);
        if (! is_file($projectRoot.'/vendor/autoload.php')) {
            throw new AivenSeedException('Install Composer dependencies before running this helper.');
        }
        require_once $projectRoot.'/vendor/autoload.php';
        $envFile = realpath($options['env-file'] ?? $projectRoot.'/.env.render.local');
        if ($envFile === false || ! is_readable($envFile)) {
            throw new AivenSeedException('Deployment environment file is missing or unreadable.');
        }
        $certificate = realpath($options['ca'] ?? '');
        $settings = Dotenv::parse(file_get_contents($envFile));
        $email = strtolower(trim($options['email'] ?? ''));
        $password = getenv('AIVEN_SUPERADMIN_PASSWORD') ?: '';
        $environment = aivenSeedEnvironment($settings, $certificate ?: '', $email, $password,
            getenv('AIVEN_DB_PASSWORD') ?: '', $options['name'] ?? 'Superadmin');
        if (! extension_loaded('pdo_mysql')) {
            throw new AivenSeedException('Enable PDO MySQL in the selected PHP runtime.');
        }
        applyAivenSeedEnvironment($environment);
        $application = require $projectRoot.'/bootstrap/app.php';
        $application->useEnvironmentPath(dirname($envFile))->loadEnvironmentFrom(basename($envFile));
        $application->make(Kernel::class)->bootstrap();

        $connection = $application->make('db')->connection('mysql');
        $configuration = $connection->getConfig();
        if ($application->make('db')->getDefaultConnection() !== 'mysql'
            || $configuration['host'] !== $environment['DB_HOST']
            || (string) $configuration['port'] !== $environment['DB_PORT']
            || $configuration['username'] !== $environment['DB_USERNAME']
            || $configuration['database'] !== $environment['DB_DATABASE']
            || ($configuration['options'][Mysql::ATTR_SSL_VERIFY_SERVER_CERT] ?? null) !== true
            || ($configuration['options'][Mysql::ATTR_SSL_CA] ?? null) !== $certificate) {
            throw new AivenSeedException('The active database configuration does not match the verified Aiven target.');
        }
        $tls = $connection->getPdo()->query("SHOW SESSION STATUS LIKE 'Ssl_cipher'")->fetch(PDO::FETCH_ASSOC);
        if (empty($tls['Value'])) {
            throw new AivenSeedException('The Aiven connection did not report TLS; no account changes were made.');
        }
        assertAivenUserSchema($connection);

        echo 'Target: ', $environment['DB_HOST'], ':', $environment['DB_PORT'], '/', $environment['DB_DATABASE'], PHP_EOL;
        echo 'PASS verified TLS connection (', $tls['Value'], ').', PHP_EOL;
        $user = seedVerifiedSuperadmin($application, $connection, $email, $password);
        echo 'PASS Superadmin seeded: ', $user->email, PHP_EOL;
        echo 'Role: superadmin; email: verified; login password: the value entered at the hidden prompt.', PHP_EOL;

        return 0;
    } catch (AivenSeedException $exception) {
        fwrite(STDERR, $exception->getMessage().PHP_EOL);
    } catch (PDOException $exception) {
        $driverCode = (int) ($exception->errorInfo[1] ?? 0);
        $message = match ($driverCode) {
            1045 => 'Aiven authentication failed. Verify the database username and password.',
            1049 => 'The configured Aiven database does not exist.',
            2026 => 'TLS verification failed. Check ca.pem and the full Aiven hostname.',
            2002, 2003 => 'Aiven connection unavailable. Verify the service and network access.',
            default => 'Superadmin seeding failed. Check the Aiven schema and account privileges.',
        };
        fwrite(STDERR, $message.PHP_EOL);
    } catch (Throwable) {
        // Do not print exceptions or SQL bindings that could contain credentials.
        fwrite(STDERR, 'Could not seed the Superadmin. Check the deployment file, PHP dependencies and CA certificate.'.PHP_EOL);
    }

    return 1;
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    exit(runAivenSuperadminSeed());
}
