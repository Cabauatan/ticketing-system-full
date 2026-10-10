<?php

declare(strict_types=1);

// Standalone, read-only check: never bootstrap Laravel or load its local .env.
require dirname(__DIR__).'/vendor/autoload.php';

$options = getopt('', ['env:', 'ca:']);
$envFile = $options['env'] ?? dirname(__DIR__).'/.env.render.local';

try {
    if (! is_file($envFile) || ! is_readable($envFile)) {
        throw new RuntimeException('Deployment environment file is missing or unreadable.');
    }
    $settings = Dotenv\Dotenv::parse(file_get_contents($envFile));
    $host = $settings['DB_HOST'] ?? '';
    $port = $settings['DB_PORT'] ?? '';
    $database = $settings['DB_DATABASE'] ?? '';
    $username = $settings['DB_USERNAME'] ?? '';
    $password = getenv('AIVEN_DB_PASSWORD') ?: ($settings['DB_PASSWORD'] ?? '');
    $certificate = $options['ca'] ?? ($settings['MYSQL_ATTR_SSL_CA'] ?? $settings['DB_SSL_CA'] ?? '');

    if (! extension_loaded('pdo_mysql')) {
        throw new RuntimeException('Enable PDO MySQL in the selected PHP runtime.');
    }
    if (! preg_match('/\A[a-z0-9][a-z0-9.-]+\z/i', $host)
        || str_starts_with($host, 'replace-')
        || ! ctype_digit((string) $port) || (int) $port < 1 || (int) $port > 65535
        || $database === '' || str_contains($database, ';') || $username === '') {
        throw new RuntimeException('Set the exact Aiven host, port, database and username in the deployment file.');
    }
    if ($password === '' || $password === 'replace-database-password') {
        throw new RuntimeException('A database password is required. Use check-aiven.ps1 for a hidden prompt.');
    }
    if (! is_file($certificate) || ! is_readable($certificate)) {
        throw new RuntimeException('Pass --ca with the readable local ca.pem path.');
    }

    $connection = new PDO(
        "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4",
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 8,
            PDO::ATTR_PERSISTENT => false,
            Pdo\Mysql::ATTR_SSL_CA => $certificate,
            Pdo\Mysql::ATTR_SSL_VERIFY_SERVER_CERT => true,
        ],
    );
    $connection->query('SELECT 1')->fetchColumn();
    $tls = $connection->query("SHOW SESSION STATUS LIKE 'Ssl_cipher'")->fetch(PDO::FETCH_ASSOC);
    if (empty($tls['Value'])) {
        throw new RuntimeException('The connection did not report a TLS cipher.');
    }
    echo 'PASS Aiven MySQL authentication and verified TLS connection.', PHP_EOL;
    echo 'TLS cipher: ', $tls['Value'], PHP_EOL;
    $schema = $connection->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'migrations'")->fetchColumn();
    echo $schema ? 'Migration table exists; pending migrations were not inspected.' : 'Migration table is absent; initial migrations have not been applied.', PHP_EOL;
    echo 'Read-only check complete. No migrations or database changes were executed.', PHP_EOL;
} catch (PDOException $exception) {
    $driverCode = (int) ($exception->errorInfo[1] ?? 0);
    $message = match ($driverCode) {
        1045 => 'Authentication failed. Verify the Aiven username and password.',
        1049 => 'The configured database does not exist.',
        2026 => 'TLS verification failed. Verify the CA certificate and full Aiven hostname.',
        2002, 2003 => 'Connection unavailable. Verify hostname, port, service state and network access.',
        default => 'Database check failed. Verify the connection settings and account privileges.',
    };
    fwrite(STDERR, $message.PHP_EOL);
    exit(1);
} catch (RuntimeException $exception) {
    // Only deliberately defined configuration messages are displayed.
    fwrite(STDERR, $exception->getMessage().PHP_EOL);
    exit(2);
} catch (Throwable) {
    fwrite(STDERR, 'Connection check failed. Verify the PHP runtime, environment file and CA certificate.'.PHP_EOL);
    exit(1);
}
