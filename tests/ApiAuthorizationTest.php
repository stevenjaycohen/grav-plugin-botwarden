<?php
declare(strict_types=1);
namespace Grav\Plugin\Botwarden\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use SQLite3;

/** Real HTTP integration tests; explicitly opt in to the disposable local host. */
#[Group('integration')]
final class ApiAuthorizationTest extends TestCase
{
    private static string $base;
    private static string $host;
    private static string $work;
    private static array $credentials;
    private static bool $prepared = false;
    private static array $responseSessions = [];
    private static bool $createdWorkDirectory = false;

    public static function setUpBeforeClass(): void
    {
        $url = getenv('BOTWARDEN_TEST_URL');
        if (!$url) self::markTestSkipped('Set BOTWARDEN_TEST_URL for the repository-local disposable Grav host.');
        self::$base = rtrim($url, '/');
        if (!in_array(self::$base, ['http://127.0.0.1:8777', 'http://127.0.0.1:8779/grav'], true)) {
            throw new \RuntimeException('Only the dedicated loopback fixture URLs are allowed');
        }
        self::$host = dirname(__DIR__) . '/vendor/.botwarden-migration/grav';
        self::$work = dirname(__DIR__) . '/vendor/.botwarden-migration/security';
        if (is_link(self::$work)) throw new \RuntimeException('Security work directory must not be a symlink');
        if (!is_dir(self::$work)) {
            if (!mkdir(self::$work, 0700)) throw new \RuntimeException('Could not create repository-local security work directory');
            self::$createdWorkDirectory = true;
        }
        $response = self::request('GET', '/');
        if ($response['status'] !== 200 || !str_contains($response['body'], 'Synthetic botWarden migration test site.')) {
            throw new \RuntimeException('The loopback target is not the disposable migration fixture');
        }
        self::helper('setup');
        self::$prepared = true;
        self::$credentials = json_decode(file_get_contents(self::$work . '/credentials.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$prepared) self::helper('cleanup');
        foreach (array_keys(self::$responseSessions) as $path) if (is_file($path)) unlink($path);
        if (self::$createdWorkDirectory && is_dir(self::$work)) {
            if (count(scandir(self::$work)) !== 2 || !rmdir(self::$work)) throw new \RuntimeException('Security fixture work directory was not cleaned; inspect it for diagnostics');
        }
    }

    protected function setUp(): void
    {
        $data = self::$host . '/user/data/botwarden';
        $marker = json_decode(file_get_contents($data . '/migration-complete.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(self::$credentials['fixture_id'], $marker['fixture_id']);
        $db = new SQLite3($data . '/botwarden.sqlite');
        $db->enableExceptions(true);
        $db->exec("DELETE FROM local; DELETE FROM history; DELETE FROM sqlite_sequence WHERE name = 'history'");
        $db->close();
    }

    private static function routes(): array
    {
        return [
            ['GET', '/botwarden/status', null],
            ['GET', '/botwarden/legacy', null],
            ['GET', '/botwarden/blocks', null],
            ['GET', '/botwarden/history', null],
            ['POST', '/botwarden/blocks', ['ip' => '198.51.100.200', 'reason' => 'Authorization fixture']],
            ['POST', '/botwarden/unblock', ['ip' => '198.51.100.200']],
            ['POST', '/botwarden/migrate', ['confirm' => true]],
        ];
    }

    public static function unauthorizedRequests(): iterable
    {
        foreach (['anonymous', 'limited', 'non_super', 'limited_with_session', 'pending_two_factor'] as $credential) {
            foreach (self::routes() as [$method, $path, $body]) {
                yield $credential . ' ' . $method . ' ' . $path => [$credential, $method, $path, $body];
            }
        }
    }

    #[DataProvider('unauthorizedRequests')]
    public function testUnauthorizedCredentialsCannotAccessAnyManagementRoute(string $credential, string $method, string $path, ?array $body): void
    {
        $before = $this->state();
        $response = self::request($method, '/api/v1' . $path, $body, self::headers($credential));
        $expected = in_array($credential, ['anonymous', 'pending_two_factor'], true) ? 401 : 403;
        $this->assertSame($expected, $response['status'], $credential . ' ' . $path);
        $this->assertSame($before, $this->state(), 'Denied requests must not change security data or legacy files');
    }

    public static function forgedSessionWrites(): iterable
    {
        foreach (['foreign_origin', 'null_origin', 'foreign_referrer', 'bad_origin_good_referrer', 'missing_origin_form', 'invalid_key_origin', 'invalid_token_origin'] as $scenario) {
            foreach (array_slice(self::routes(), 4) as [$method, $path, $body]) {
                yield $scenario . ' ' . $path => [$scenario, $method, $path, $body];
            }
        }
    }

    #[DataProvider('forgedSessionWrites')]
    public function testForgedCookieWritesAreRejected(string $scenario, string $method, string $path, array $body): void
    {
        $before = $this->state();
        $headers = self::headers('session');
        switch ($scenario) {
            case 'foreign_origin': $headers['Origin'] = 'https://csrf-fixture.invalid'; break;
            case 'null_origin': $headers['Origin'] = 'null'; break;
            case 'foreign_referrer': $headers['Referer'] = 'https://csrf-fixture.invalid/form'; break;
            case 'bad_origin_good_referrer':
                $headers['Origin'] = 'https://csrf-fixture.invalid';
                $headers['Referer'] = self::$base . '/admin';
                break;
            case 'invalid_key_origin':
                $headers['Origin'] = 'https://csrf-fixture.invalid';
                $headers['X-API-Key'] = 'grav_invalid_fixture_key';
                break;
            case 'invalid_token_origin':
                $headers['Origin'] = 'https://csrf-fixture.invalid';
                $headers['Authorization'] = 'Bearer invalid-fixture-token';
                break;
        }
        $response = self::request($method, '/api/v1' . $path, $body, $headers, $scenario === 'missing_origin_form');
        $this->assertSame(403, $response['status'], $scenario);
        $this->assertSame($before, $this->state());
    }

    public function testFullSessionCanReadAndWriteWithSameHostOrigin(): void
    {
        $this->exerciseAuthorized(self::headers('session') + ['Origin' => self::$base]);
    }

    public function testDocumentedSessionHeaderFallbacks(): void
    {
        foreach ([
            [['Referer' => self::$base . '/admin/plugin/botwarden'], true],
            [[], false],
            [['X-Requested-With' => 'XMLHttpRequest'], true],
            [['Origin' => 'http://127.0.0.1:18777'], true],
        ] as [$extra, $form]) {
            $headers = self::headers('session') + $extra;
            $response = self::request('POST', '/api/v1/botwarden/blocks', ['ip' => '198.51.100.200'], $headers, $form);
            $this->assertSame(200, $response['status']);
            $response = self::request('POST', '/api/v1/botwarden/unblock', ['ip' => '198.51.100.200'], $headers, $form);
            $this->assertSame(200, $response['status']);
        }
    }

    public function testExplicitKeysAndJwtDoNotRequireCookieCsrfHeaders(): void
    {
        foreach (['explicit_super', 'unscoped_super'] as $credential) $this->exerciseAuthorized(self::headers($credential));
        $tokens = self::login();
        try { $this->exerciseAuthorized(['Authorization' => 'Bearer ' . $tokens['access_token']]); }
        finally { self::revoke($tokens); }
    }

    public static function discoveryCredentials(): iterable
    {
        yield 'restricted key on super account' => ['limited', 0];
        yield 'restricted key with full session' => ['limited_with_session', 0];
        yield 'over-scoped key on regular account' => ['non_super', 0];
        yield 'explicit super scope' => ['explicit_super', 7];
        yield 'unscoped super key' => ['unscoped_super', 7];
    }

    #[DataProvider('discoveryCredentials')]
    public function testToolDiscoveryHonorsAccountAndKeyScopes(string $credential, int $count): void
    {
        $response = self::request('GET', '/api/v1/mcp/tools', null, self::headers($credential));
        $this->assertSame(200, $response['status']);
        $tools = array_filter($response['json']['data']['tools'], static fn(array $tool): bool => str_starts_with($tool['name'], 'botwarden_'));
        $this->assertCount($count, $tools);
    }

    public function testSessionPermissionsFollowAccountRevocation(): void
    {
        $before = $this->state();
        self::helper('revoke_super');
        try {
            $headers = self::headers('session') + ['Origin' => self::$base];
            $this->assertSame(403, self::request('GET', '/api/v1/botwarden/status', null, $headers)['status']);
            $this->assertSame(403, self::request('POST', '/api/v1/botwarden/blocks', ['ip' => '198.51.100.200'], $headers)['status']);
            $this->assertSame($before, $this->state());
        } finally { self::helper('restore_super'); }
    }

    public function testDisabledAccountRejectsSessionKeyAndJwt(): void
    {
        $tokens = self::login();
        self::helper('disable_super');
        try {
            foreach ([self::headers('session'), self::headers('explicit_super'), ['Authorization' => 'Bearer ' . $tokens['access_token']]] as $headers) {
                $this->assertSame(401, self::request('GET', '/api/v1/botwarden/status', null, $headers)['status']);
            }
        } finally { self::helper('enable_super'); self::revoke($tokens); }
    }

    public function testAccountCutoffInvalidatesAnOlderSession(): void
    {
        self::helper('invalidate_sessions');
        try { $this->assertSame(401, self::request('GET', '/api/v1/botwarden/status', null, self::headers('session'))['status']); }
        finally { self::helper('reset_cutoff'); }
    }

    public function testRevokedKeyCannotAuthenticate(): void
    {
        self::helper('revoke_test_key');
        $this->assertSame(401, self::request('GET', '/api/v1/botwarden/status', null, self::headers('revocable'))['status']);
    }

    public function testLoggedOutNativeSessionCannotAuthenticate(): void
    {
        $headers = ['Cookie' => self::$credentials['sessions']['logout']];
        $this->assertSame(200, self::request('GET', '/api/v1/botwarden/status', null, $headers)['status']);
        self::helper('logout_session');
        $this->assertSame(401, self::request('GET', '/api/v1/botwarden/status', null, $headers)['status']);
    }

    public function testRevokedRefreshTokenCannotIssueTokens(): void
    {
        $tokens = self::login();
        self::revoke($tokens);
        $response = self::request('POST', '/api/v1/auth/refresh', ['refresh_token' => $tokens['refresh_token']]);
        $this->assertSame(401, $response['status']);
    }

    public function testRemovedKnownAgentsConnectionReturnsNotFoundWithoutSendingVisits(): void
    {
        require_once self::$host . '/vendor/autoload.php';
        $path = self::$host . '/user/config/plugins/botwarden.yaml';
        $this->assertFalse(is_link($path));
        $original = is_file($path) ? file_get_contents($path) : null;
        $mode = is_file($path) ? fileperms($path) & 0777 : null;
        $mtime = is_file($path) ? filemtime($path) : null;
        $work = dirname(self::$work) . '/services';
        $this->assertDirectoryDoesNotExist($work);
        mkdir($work, 0700);
        try {
            $config = $original === null ? [] : \Symfony\Component\Yaml\Yaml::parse($original);
            $config['dark_visitors']['api_key'] = 'synthetic-fixture-key';
            foreach ([false, true] as $reportVisits) {
                $config['dark_visitors']['report_visits'] = $reportVisits;
                file_put_contents($path, json_encode($config, JSON_THROW_ON_ERROR));
                self::helper('clear_cache');
                $before = $this->state();
                $response = self::request('POST', '/api/v1/botwarden/known-agents/connect', [], self::headers('explicit_super'));
                $this->assertSame(404, $response['status']);
                $this->assertStringContainsString('application/problem+json', (string)$response['type']);
                // This request waits for the preceding shutdown hook to finish.
                $this->assertSame(200, self::request('GET', '/api/v1/ping')['status']);
                $this->assertFileDoesNotExist($work . '/requests.jsonl');
                $this->assertSame($before, $this->state());
            }
        } finally {
            if ($original === null) unlink($path);
            else { file_put_contents($path, $original); chmod($path, $mode); touch($path, $mtime); }
            self::helper('clear_cache');
            if (is_file($work . '/requests.jsonl')) unlink($work . '/requests.jsonl');
            rmdir($work);
        }
    }

    public function testPasswordCredentialFieldsPreserveMaskedValuesOnSettingsSave(): void
    {
        require_once self::$host . '/vendor/autoload.php';
        $this->assertFileDoesNotExist(self::$host . '/user/data/api/webhooks.yaml', 'The disposable fixture must not deliver webhooks');
        $path = self::$host . '/user/config/plugins/botwarden.yaml';
        $this->assertFalse(is_link($path));
        $original = is_file($path) ? file_get_contents($path) : null;
        $mode = is_file($path) ? fileperms($path) & 0777 : null;
        $mtime = is_file($path) ? filemtime($path) : null;
        $keys = [
            'abuseipdb.api_key' => 'synthetic-abuseipdb-key',
            'crowdsec.cti_api_key' => 'synthetic-crowdsec-cti-key',
            'crowdsec.lapi_api_key' => 'synthetic-crowdsec-lapi-key',
            'dark_visitors.api_key' => 'synthetic-known-agents-key',
        ];
        try {
            $config = $original === null ? [] : \Symfony\Component\Yaml\Yaml::parse($original);
            foreach ($keys as $name => $value) {
                [$section, $field] = explode('.', $name, 2);
                $config[$section][$field] = $value;
            }
            $config['dark_visitors']['report_visits'] = false;
            $config['logging'] = false;
            file_put_contents($path, json_encode($config, JSON_THROW_ON_ERROR));
            self::helper('clear_cache');
            $before = $this->state();
            $headers = self::headers('unscoped_super') + ['X-Config-Environment' => 'default'];
            $blueprint = self::request('GET', '/api/v1/blueprints/plugins/botwarden', null, $headers);
            $this->assertSame(200, $blueprint['status']);
            $types = array_column($blueprint['json']['data']['fields'], 'type', 'name');
            foreach ($keys as $name => $value) $this->assertSame('password', $types[$name], $name);

            $response = self::request('GET', '/api/v1/config/plugins/botwarden', null, $headers);
            $this->assertSame(200, $response['status']);
            $this->assertStringContainsString('application/json', (string)$response['type']);
            $settings = $response['json']['data'];
            foreach ($keys as $name => $value) {
                [$section, $field] = explode('.', $name, 2);
                $this->assertSame('********', $settings[$section][$field], $name);
                $this->assertStringNotContainsString($value, $response['body']);
            }
            $settings['logging'] = true;
            $saved = self::request('PATCH', '/api/v1/config/plugins/botwarden', $settings, $headers);
            $this->assertSame(200, $saved['status']);
            $disk = \Symfony\Component\Yaml\Yaml::parseFile($path);
            $this->assertTrue($disk['logging']);
            foreach ($keys as $name => $value) {
                [$section, $field] = explode('.', $name, 2);
                $this->assertSame($value, $disk[$section][$field], $name);
                $this->assertSame('********', $saved['json']['data'][$section][$field], $name);
                $this->assertStringNotContainsString($value, $saved['body']);
            }

            $changes = ['abuseipdb' => ['api_key' => 'synthetic-replacement-abuseipdb'],
                'crowdsec' => ['cti_api_key' => 'synthetic-replacement-cti', 'lapi_api_key' => 'synthetic-replacement-lapi'],
                'dark_visitors' => ['api_key' => '']];
            $changed = self::request('PATCH', '/api/v1/config/plugins/botwarden', $changes, $headers);
            $this->assertSame(200, $changed['status']);
            $disk = \Symfony\Component\Yaml\Yaml::parseFile($path);
            foreach ($changes as $section => $fields) {
                foreach ($fields as $field => $value) {
                    $this->assertSame($value, $disk[$section][$field], $section . '.' . $field);
                    $this->assertSame($value === '' ? '' : '********', $changed['json']['data'][$section][$field]);
                    if ($value !== '') $this->assertStringNotContainsString($value, $changed['body']);
                }
            }
            $this->assertSame($before, $this->state(), 'Settings saves must not modify security data');
        } finally {
            if ($original === null) { if (is_file($path)) unlink($path); }
            else { file_put_contents($path, $original); chmod($path, $mode); touch($path, $mtime); }
            self::helper('clear_cache');
        }
    }

    public static function proxyWarningCases(): iterable
    {
        yield 'empty configuration' => ['', 'none', [], 'No trusted proxies'];
        yield 'comments are not proxies' => ["# no proxies\n", 'none', [], 'No trusted proxies'];
        yield 'custom trusted peer' => ['127.0.0.1', 'none', ['X-Forwarded-For' => '8.8.8.8'], null];
        yield 'untrusted peer with forwarding header' => ['9.9.9.9', 'none', ['X-Forwarded-For' => '8.8.8.8'], 'outside Trusted Proxies'];
        yield 'Cloudflare IPv4 preset' => ['', 'cloudflare', ['X-Botwarden-Fixture-Peer' => '173.245.48.1', 'CF-Connecting-IP' => '8.8.8.8'], null];
        yield 'Cloudflare IPv6 preset' => ['', 'cloudflare', ['X-Botwarden-Fixture-Peer' => '2606:4700:4700::1111', 'CF-Connecting-IP' => '8.8.8.8'], null];
        yield 'preset does not trust other peers' => ['', 'cloudflare', ['X-Botwarden-Fixture-Peer' => '1.1.1.1', 'X-Real-IP' => '8.8.8.8'], 'outside Trusted Proxies'];
    }

    #[DataProvider('proxyWarningCases')]
    public function testProxyWarningFollowsEffectiveConfiguration(string $custom, string $preset, array $headers, ?string $warning): void
    {
        $path = self::$host . '/user/config/plugins/botwarden.yaml';
        $this->assertFalse(is_link($path));
        $original = is_file($path) ? file_get_contents($path) : null;
        $mode = is_file($path) ? fileperms($path) & 0777 : null;
        $mtime = is_file($path) ? filemtime($path) : null;
        try {
            $config = $original === null ? [] : \Symfony\Component\Yaml\Yaml::parse($original);
            $config['trusted_proxies'] = $custom;
            $config['trusted_proxy_preset'] = $preset;
            file_put_contents($path, json_encode($config, JSON_THROW_ON_ERROR));
            self::helper('clear_cache');
            $before = $this->state();
            $response = self::request('GET', '/api/v1/botwarden/status', null, self::headers('explicit_super') + $headers);
            $this->assertSame(200, $response['status']);
            $data = $response['json']['data'];
            $this->assertArrayHasKey('proxy_warning', $data);
            if ($warning === null) $this->assertNull($data['proxy_warning']);
            else $this->assertStringContainsString($warning, $data['proxy_warning']);
            $this->assertSame($before, $this->state(), 'Dashboard warnings must not modify security data');
            $this->assertSame(401, self::request('GET', '/api/v1/botwarden/status', null, $headers)['status']);
        } finally {
            if ($original === null) unlink($path);
            else {
                file_put_contents($path, $original);
                chmod($path, $mode);
                touch($path, $mtime);
            }
            self::helper('clear_cache');
        }
    }

    public static function managementRouteCases(): iterable
    {
        yield 'default routes' => ['/admin', '/api', 'v1', '/admin', '/api/v1'];
        yield 'custom routes and version' => ['/control', '/service', 'v2', '/control', '/service/v2'];
        yield 'nested routes with surrounding slashes' => ['/control/panel/', '/service/internal/', 'v3', '/control/panel', '/service/internal/v3'];
    }

    #[DataProvider('managementRouteCases')]
    public function testConfiguredManagementRoutesRemainAccessibleToBlockedIps(string $adminRoute, string $apiRoute, string $version, string $adminPath, string $apiPath): void
    {
        require_once self::$host . '/vendor/autoload.php';
        $directory = self::$host . '/user/config/plugins/';
        $originals = [];
        foreach (['botwarden', 'admin2', 'api'] as $plugin) {
            $path = $directory . $plugin . '.yaml';
            $this->assertFalse(is_link($path));
            $originals[$plugin] = is_file($path)
                ? ['content' => file_get_contents($path), 'mode' => fileperms($path) & 0777, 'mtime' => filemtime($path)]
                : null;
        }
        try {
            foreach (['admin2' => ['route' => $adminRoute], 'api' => ['route' => $apiRoute, 'version_prefix' => $version],
                'botwarden' => ['enabled' => true, 'trusted_proxies' => '127.0.0.1',
                    'sources' => ['local' => true], 'enable_blacklisting' => true, 'response' => 418,
                    'enable_filtering' => true, 'filters' => '.']] as $plugin => $changes) {
                $config = $originals[$plugin] === null ? [] : \Symfony\Component\Yaml\Yaml::parse($originals[$plugin]['content']);
                file_put_contents($directory . $plugin . '.yaml', json_encode(array_replace_recursive($config, $changes), JSON_THROW_ON_ERROR));
            }
            self::helper('clear_cache');
            $db = new SQLite3(self::$host . '/user/data/botwarden/botwarden.sqlite');
            $db->exec("INSERT INTO local (ip, is_manual) VALUES ('8.8.8.8', 1)");
            $db->close();
            $headers = ['X-Forwarded-For' => '8.8.8.8'];
            $this->assertSame(418, self::request('GET', '/', null, $headers)['status']);
            foreach ([$adminPath, $adminPath . '/plugin/botwarden'] as $path) {
                $response = self::request('GET', $path, null, $headers);
                $this->assertContains($response['status'], [200, 302], $path);
                if ($response['status'] === 200) {
                    $this->assertStringContainsString('text/html', (string)$response['type']);
                    $this->assertMatchesRegularExpression('~<html(?:\s|>)~i', $response['body']);
                }
            }
            $shell = self::request('GET', $adminPath . '/login', null, $headers);
            $this->assertSame(200, $shell['status']);
            $this->assertSame(1, preg_match('~window\.__GRAV_CONFIG__ = (.*?);</script>~s', $shell['body'], $boot));
            $boot = json_decode($boot[1], true, 512, JSON_THROW_ON_ERROR);
            $mount = parse_url(self::$base, PHP_URL_PATH) ?: '';
            $this->assertSame($mount, $boot['serverUrl']);
            $this->assertSame($mount . $adminPath, $boot['basePath']);
            $this->assertSame($apiPath, $boot['apiPrefix']);
            $this->assertSame(1, preg_match('~(?:src|href)="([^"]+/_app/[^"]+\.js)"~', $shell['body'], $asset));
            $this->assertStringStartsWith($mount . '/user/plugins/admin2/app/_app/', $asset[1]);
            $script = self::request('GET', substr($asset[1], strlen($mount)), null, $headers);
            $this->assertSame(200, $script['status']);
            $this->assertMatchesRegularExpression('~(?:text|application)/javascript~', (string)$script['type']);
            $this->assertNotSame('', $script['body']);
            $ping = self::request('GET', $apiPath . '/ping', null, $headers);
            $this->assertSame(200, $ping['status']);
            $this->assertStringContainsString('application/json', (string)$ping['type']);
            $this->assertIsArray($ping['json']);
            $this->assertSame(401, self::request('GET', $apiPath, null, $headers)['status']);
            $this->assertSame(401, self::request('GET', $apiPath . '/botwarden/status', null, $headers)['status']);
            $status = self::request('GET', $apiPath . '/botwarden/status', null, self::headers('explicit_super') + $headers);
            $this->assertSame(200, $status['status']);
            $this->assertSame(1, $status['json']['data']['local_count']);
            $this->assertSame(422, self::request('POST', $apiPath . '/auth/token', [], $headers)['status']);
            foreach (['limited' => 403, 'non_super' => 403, 'pending_two_factor' => 401] as $credential => $expected) {
                $denied = self::request('GET', $apiPath . '/botwarden/status', null, self::headers($credential) + $headers);
                $this->assertSame($expected, $denied['status']);
                $this->assertStringContainsString('application/problem+json', (string)$denied['type']);
            }
            $this->assertSame(401, self::request('POST', $apiPath . '/auth/token',
                ['username' => self::$credentials['accounts']['super']['username'], 'password' => 'invalid-fixture-password'], $headers)['status']);
            $login = self::request('POST', $apiPath . '/auth/token', self::$credentials['accounts']['super'], $headers);
            $this->assertSame(200, $login['status']);
            $tokens = $login['json']['data'];
            try {
                $authenticated = ['Authorization' => 'Bearer ' . $tokens['access_token']] + $headers;
                $this->assertSame(200, self::request('GET', $apiPath . '/botwarden/status', null, $authenticated)['status']);
                $origin = substr(self::$base, 0, strlen(self::$base) - strlen($mount));
                foreach ([$authenticated, ['Cookie' => self::$credentials['sessions']['management'], 'Origin' => $origin] + $headers] as $authorized) {
                    $this->assertSame(200, self::request('POST', $apiPath . '/botwarden/blocks',
                        ['ip' => '198.51.100.201', 'reason' => 'Mounted route fixture'], $authorized)['status']);
                    $this->assertSame(200, self::request('POST', $apiPath . '/botwarden/unblock',
                        ['ip' => '198.51.100.201'], $authorized)['status']);
                }
            } finally {
                $this->assertSame(204, self::request('POST', $apiPath . '/auth/revoke',
                    ['refresh_token' => $tokens['refresh_token']], $headers)['status']);
            }
            foreach ([$adminPath . 'istrator', $apiPath . '0/ping', '/' . trim($apiRoute, '/') . '/other-version/ping', '/apiary/v1/ping'] as $path) {
                $this->assertSame(418, self::request('GET', $path, null, $headers)['status'], $path);
            }
            if ($adminPath !== '/admin') $this->assertSame(418, self::request('GET', '/admin', null, $headers)['status']);
            if ($apiPath !== '/api/v1') $this->assertSame(418, self::request('GET', '/api/v1/ping', null, $headers)['status']);
            $db = new SQLite3(self::$host . '/user/data/botwarden/botwarden.sqlite', SQLITE3_OPEN_READONLY);
            try {
                $this->assertSame(1, (int)$db->querySingle('SELECT COUNT(*) FROM local'));
                $this->assertSame(0, (int)$db->querySingle('SELECT COUNT(*) FROM report_queue'));
            } finally { $db->close(); }
        } finally {
            foreach ($originals as $plugin => $original) {
                $path = $directory . $plugin . '.yaml';
                if ($original === null) { if (is_file($path)) unlink($path); }
                else {
                    file_put_contents($path, $original['content']);
                    chmod($path, $original['mode']);
                    touch($path, $original['mtime']);
                }
            }
            self::helper('clear_cache');
        }
    }

    private function exerciseAuthorized(array $headers): void
    {
        foreach (array_slice(self::routes(), 0, 4) as [$method, $path]) {
            $this->assertSame(200, self::request($method, '/api/v1' . $path, null, $headers)['status']);
        }
        $response = self::request('POST', '/api/v1/botwarden/blocks', ['ip' => '198.51.100.200', 'reason' => 'Authorization fixture'], $headers);
        $this->assertSame(200, $response['status']);
        $response = self::request('POST', '/api/v1/botwarden/unblock', ['ip' => '198.51.100.200'], $headers);
        $this->assertSame(200, $response['status']);
        $response = self::request('POST', '/api/v1/botwarden/migrate', ['confirm' => false], $headers);
        $this->assertSame(422, $response['status'], 'Authorized requests still need explicit migration confirmation');
    }

    private static function headers(string $credential): array
    {
        if ($credential === 'anonymous') return [];
        if ($credential === 'session') return ['Cookie' => self::$credentials['sessions']['valid']];
        if ($credential === 'pending_two_factor') return ['Cookie' => self::$credentials['sessions']['pending_two_factor']];
        if ($credential === 'limited_with_session') return self::headers('limited') + self::headers('session');
        return ['X-API-Key' => self::$credentials['keys'][$credential]];
    }

    private function state(): array
    {
        $data = self::$host . '/user/data/botwarden';
        $db = new SQLite3($data . '/botwarden.sqlite', SQLITE3_OPEN_READONLY);
        $state = [];
        try {
            foreach (['local', 'history', 'abuseipdb_bulk', 'report_queue', 'dark_visitors_agents', 'ip_cache', 'metadata', 'sqlite_sequence'] as $table) {
                $result = $db->query('SELECT * FROM ' . $table . ' ORDER BY rowid');
                $state[$table] = [];
                while ($row = $result->fetchArray(SQLITE3_ASSOC)) $state[$table][] = $row;
                $result->finalize();
            }
        } finally { $db->close(); }
        $state['legacy_config'] = hash_file('sha256', self::$host . '/user/config/plugins/grvhulk.yaml');
        $state['legacy_database'] = hash_file('sha256', self::$host . '/user/data/grvhulk/grvhulk.sqlite');
        $state['destination_config'] = file_exists(self::$host . '/user/config/plugins/botwarden.yaml');
        $state['backups'] = is_dir($data . '/backups');
        return $state;
    }

    private static function request(string $method, string $path, ?array $body = null, array $headers = [], bool $form = false): array
    {
        $curl = curl_init(self::$base . $path);
        $headers = ['Accept' => 'application/json'] + $headers;
        $options = [
            CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 15,
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line): int {
                // Track only cookies issued by this test's own HTTP responses.
                if (preg_match('/^Set-Cookie:\s*grav-site-[a-f0-9]+(?:-admin)?=([a-zA-Z0-9,-]{20,128})(?:;|\s*$)/i', $line, $matches)) {
                    self::$responseSessions[dirname(self::$work) . '/sessions/sess_' . $matches[1]] = true;
                }
                return strlen($line);
            },
        ];
        if ($body !== null) {
            $headers['Content-Type'] = $form ? 'application/x-www-form-urlencoded' : 'application/json';
            $options[CURLOPT_POSTFIELDS] = $form ? http_build_query($body) : json_encode($body, JSON_THROW_ON_ERROR);
        }
        $options[CURLOPT_HTTPHEADER] = array_map(static fn(string $key, string $value): string => $key . ': ' . $value, array_keys($headers), array_values($headers));
        curl_setopt_array($curl, $options);
        $raw = curl_exec($curl);
        if ($raw === false) throw new \RuntimeException('Loopback HTTP transport failed: ' . curl_errno($curl));
        $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $type = curl_getinfo($curl, CURLINFO_CONTENT_TYPE);
        curl_close($curl);
        return ['status' => $status, 'type' => $type, 'body' => $raw, 'json' => json_decode($raw, true)];
    }

    private static function login(): array
    {
        $response = self::request('POST', '/api/v1/auth/token', self::$credentials['accounts']['super']);
        self::assertSame(200, $response['status']);
        return $response['json']['data'];
    }

    private static function revoke(array $tokens): void
    {
        self::assertSame(204, self::request('POST', '/api/v1/auth/revoke', ['refresh_token' => $tokens['refresh_token']])['status']);
    }

    private static function helper(string $operation): void
    {
        $command = [PHP_BINARY, __DIR__ . '/fixtures/ApiSecurityFixture.php', $operation, self::$host, self::$work];
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, self::$host, array_merge(getenv(), ['GRAV_ENVIRONMENT' => 'migration-test']));
        if (!is_resource($process)) throw new \RuntimeException('Could not start local fixture helper');
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]); fclose($pipes[1]);
        $error = stream_get_contents($pipes[2]); fclose($pipes[2]);
        $exit = proc_close($process);
        if ($exit !== 0 || $error !== '') {
            file_put_contents(self::$work . '/helper-diagnostic.log', $operation . "\n" . $out . "\n" . $error);
            chmod(self::$work . '/helper-diagnostic.log', 0600);
            throw new \RuntimeException('Local fixture helper failed; private diagnostics saved in the fixture work directory');
        }
    }
}
