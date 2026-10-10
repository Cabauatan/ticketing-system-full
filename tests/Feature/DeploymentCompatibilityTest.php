<?php

namespace Tests\Feature;

use App\Providers\AppServiceProvider;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Pdo\Mysql;
use ReflectionMethod;
use Tests\TestCase;

class DeploymentCompatibilityTest extends TestCase
{
    protected function tearDown(): void
    {
        TrustProxies::flushState();
        Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO);
        URL::forceScheme(null);
        URL::forceRootUrl(null);
        parent::tearDown();
    }

    public function test_frontend_can_preflight_api_auth_and_broadcast_routes(): void
    {
        config(['cors.allowed_origins' => ['https://support-desk.pages.dev']]);
        foreach (['/api/tickets', '/login', '/broadcasting/auth', '/user/confirm-password', '/passkeys/login-options'] as $path) {
            $this->withHeaders([
                'Origin' => 'https://support-desk.pages.dev',
                'Access-Control-Request-Method' => 'POST',
                'Access-Control-Request-Headers' => 'content-type,x-csrf-token',
            ])->options($path)->assertNoContent()
                ->assertHeader('Access-Control-Allow-Origin', 'https://support-desk.pages.dev')
                ->assertHeader('Access-Control-Allow-Credentials', 'true');
        }
    }

    public function test_unlisted_origins_and_health_endpoint_do_not_receive_cors_access(): void
    {
        config(['cors.allowed_origins' => ['https://support-desk.pages.dev']]);
        // The CORS library returns a single configured origin, never the unlisted origin.
        $this->withHeaders(['Origin' => 'https://untrusted.example', 'Access-Control-Request-Method' => 'POST'])
            ->options('/login')->assertHeader('Access-Control-Allow-Origin', 'https://support-desk.pages.dev');
        config(['cors.allowed_origins' => ['https://support-desk.pages.dev', 'https://another-frontend.example']]);
        $this->options('/login')->assertHeaderMissing('Access-Control-Allow-Origin');
        $this->withHeader('Origin', 'https://support-desk.pages.dev')
            ->get('/up')->assertOk()->assertHeaderMissing('Access-Control-Allow-Origin');
    }

    public function test_cross_origin_api_retains_authentication_and_exposes_download_filenames(): void
    {
        config(['cors.allowed_origins' => ['https://support-desk.pages.dev']]);
        $this->withHeader('Origin', 'https://support-desk.pages.dev')->getJson('/api/reports/export.xlsx')
            ->assertUnauthorized()
            ->assertHeader('Access-Control-Allow-Origin', 'https://support-desk.pages.dev')
            ->assertHeader('Access-Control-Expose-Headers', 'Content-Disposition');
    }

    public function test_cross_site_csrf_bootstrap_keeps_secure_host_only_session_cookie(): void
    {
        config(['cors.allowed_origins' => ['https://support-desk.pages.dev'], 'session.secure' => true,
            'session.same_site' => 'none', 'session.domain' => null]);
        $response = $this->withHeader('Origin', 'https://support-desk.pages.dev')->getJson('/api/csrf-token')
            ->assertOk()->assertJsonStructure(['token'])->assertHeader('Access-Control-Allow-Credentials', 'true');
        $cookie = collect($response->headers->getCookies())
            ->first(fn ($cookie) => $cookie->getName() === config('session.cookie'));
        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isSecure());
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('none', $cookie->getSameSite());
        $this->assertNull($cookie->getDomain());
    }

    public function test_cors_configuration_rejects_wildcards_and_supports_local_frontend_fallback(): void
    {
        $this->withEnvironment(['CORS_ALLOWED_ORIGINS' => ' *, https://support-desk.pages.dev/, https://support-desk.pages.dev, https://other.example/path '], function (): void {
            $cors = require config_path('cors.php');
            $this->assertSame(['https://support-desk.pages.dev'], $cors['allowed_origins']);
            $this->assertTrue($cors['supports_credentials']);
        });
        $this->withEnvironment(['CORS_ALLOWED_ORIGINS' => null, 'FRONTEND_URL' => 'http://localhost:5173'], function (): void {
            $cors = require config_path('cors.php');
            $this->assertSame(['http://localhost:5173'], $cors['allowed_origins']);
        });
    }

    public function test_render_proxy_recognizes_https_and_ignores_forwarded_host(): void
    {
        $this->withEnvironment(['APP_ENV' => 'production', 'RENDER' => 'true', 'TRUSTED_PROXIES' => null], function (): void {
            $this->app['env'] = 'production';
            $proxy = require config_path('trustedproxy.php');
            config(['trustedproxy.proxies' => $proxy['proxies'], 'app.url' => 'https://ticketing-api.onrender.com']);
            (new ReflectionMethod(AppServiceProvider::class, 'configureDefaults'))
                ->invoke($this->app->getProvider(AppServiceProvider::class));
            $this->assertSame('*', $proxy['proxies']);
            $this->registerProxyProbe();
            $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.2', 'HTTP_X_FORWARDED_PROTO' => 'https',
                'HTTP_X_FORWARDED_FOR' => '203.0.113.20', 'HTTP_X_FORWARDED_HOST' => 'untrusted.example'])
                ->get('http://ticketing-api.onrender.com/deployment-proxy-probe')->assertOk()->assertExactJson([
                    'secure' => true, 'host' => 'ticketing-api.onrender.com', 'ip' => '203.0.113.20',
                    'health_url' => 'https://ticketing-api.onrender.com/up',
                ]);
        });
    }

    public function test_local_http_ignores_proxy_settings_and_keeps_generated_http_urls(): void
    {
        $this->withEnvironment(['APP_ENV' => 'local', 'RENDER' => 'true', 'TRUSTED_PROXIES' => '*'], function (): void {
            $this->app['env'] = 'local';
            $proxy = require config_path('trustedproxy.php');
            config(['trustedproxy.proxies' => $proxy['proxies'], 'app.url' => 'http://localhost:8001']);
            (new ReflectionMethod(AppServiceProvider::class, 'configureDefaults'))
                ->invoke($this->app->getProvider(AppServiceProvider::class));
            $this->assertNull($proxy['proxies']);
            $this->registerProxyProbe();
            $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_PROTO' => 'https',
                'HTTP_X_FORWARDED_FOR' => '203.0.113.20'])->get('http://localhost:8001/deployment-proxy-probe')
                ->assertOk()->assertExactJson(['secure' => false, 'host' => 'localhost', 'ip' => '127.0.0.1',
                    'health_url' => 'http://localhost:8001/up']);
        });
    }

    public function test_ca_alias_enables_verified_mysql_tls_without_changing_sqlite(): void
    {
        if (! extension_loaded('pdo_mysql')) {
            $this->markTestSkipped('PDO MySQL is required to inspect its TLS options.');
        }
        $this->withEnvironment(['MYSQL_ATTR_SSL_CA' => null, 'DB_SSL_CA' => '/etc/secrets/ca.pem',
            'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:'], function (): void {
                $database = require config_path('database.php');
                $this->assertSame('sqlite', $database['default']);
                $this->assertSame(':memory:', $database['connections']['sqlite']['database']);
                foreach (['mysql', 'mariadb'] as $connection) {
                    $options = $database['connections'][$connection]['options'];
                    $this->assertSame('/etc/secrets/ca.pem', $options[Mysql::ATTR_SSL_CA]);
                    $this->assertTrue($options[Mysql::ATTR_SSL_VERIFY_SERVER_CERT]);
                }
            });
        $this->withEnvironment(['MYSQL_ATTR_SSL_CA' => '/etc/secrets/preferred-ca.pem',
            'DB_SSL_CA' => '/etc/secrets/fallback-ca.pem'], function (): void {
                $database = require config_path('database.php');
                $this->assertSame('/etc/secrets/preferred-ca.pem', $database['connections']['mysql']['options'][Mysql::ATTR_SSL_CA]);
            });
        $this->withEnvironment(['MYSQL_ATTR_SSL_CA' => null, 'DB_SSL_CA' => null], function (): void {
            $database = require config_path('database.php');
            $this->assertSame([], $database['connections']['mysql']['options']);
        });
    }

    private function registerProxyProbe(): void
    {
        Route::get('/deployment-proxy-probe', fn (Request $request) => response()->json([
            'secure' => $request->isSecure(), 'host' => $request->getHost(), 'ip' => $request->ip(), 'health_url' => url('/up'),
        ]));
    }

    private function withEnvironment(array $values, callable $callback): void
    {
        $original = [];
        foreach ($values as $name => $value) {
            $original[$name] = [$_ENV[$name] ?? null, $_SERVER[$name] ?? null, getenv($name)];
            if ($value === null) {
                unset($_ENV[$name], $_SERVER[$name]);
                putenv($name);
            } else {
                $_ENV[$name] = $_SERVER[$name] = $value;
                putenv($name.'='.$value);
            }
        }
        try {
            $callback();
        } finally {
            foreach ($original as $name => [$environment, $server, $process]) {
                if ($environment === null) {
                    unset($_ENV[$name]);
                } else {
                    $_ENV[$name] = $environment;
                }
                if ($server === null) {
                    unset($_SERVER[$name]);
                } else {
                    $_SERVER[$name] = $server;
                }
                putenv($process === false ? $name : $name.'='.$process);
            }
        }
    }
}
