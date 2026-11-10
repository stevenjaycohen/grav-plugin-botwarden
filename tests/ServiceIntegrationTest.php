<?php
declare(strict_types=1);
namespace Grav\Plugin\Botwarden\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use SQLite3;

/** Synthetic providers, real cURL, real Grav scheduler and localhost requests. */
#[Group('integration')]
final class ServiceIntegrationTest extends TestCase
{
    private static string $host;
    private static string $base;
    private static string $work;
    private static string $data;
    private static string $configPath;
    private static ?string $originalConfig;
    private static ?int $originalMode;
    private static ?int $originalMtime;
    private static bool $prepared = false;
    private static bool $ownsRobotsFixture = false;
    private static array $observations = [];
    private static string $bundledFilters;
    private SQLite3 $db;

    public static function setUpBeforeClass(): void
    {
        self::$base = getenv('BOTWARDEN_TEST_URL') ?: '';
        if (!in_array(self::$base, ['http://127.0.0.1:8777', 'http://127.0.0.1:8779/grav'], true)) {
            self::markTestSkipped('Opt in to the disposable Grav and loopback service fixtures.');
        }
        $repo = dirname(__DIR__);
        self::$host = $repo . '/vendor/.botwarden-migration/grav';
        self::$work = $repo . '/vendor/.botwarden-migration/services';
        self::$data = self::$host . '/user/data/botwarden';
        self::$configPath = self::$host . '/user/config/plugins/botwarden.yaml';
        if (realpath(self::$host) !== self::$host || file_exists(self::$work) || file_exists(self::$data) || is_link(self::$configPath)
            || file_exists(self::$host . '/robots.txt') || is_link(self::$host . '/robots.txt')) {
            throw new \RuntimeException('Disposable service fixture paths are occupied or unsafe');
        }
        $response = self::http('/');
        if ($response['status'] !== 200 || !str_contains($response['body'], 'Synthetic botWarden migration test site.')) {
            throw new \RuntimeException('Expected repository-local Grav fixture was not found');
        }
        $diagnostics = json_decode($response['diagnostics'] ?? '{}', true);
        if (!empty($diagnostics['opcache_enabled'])) throw new \RuntimeException('Start the disposable Grav server with -d opcache.enable=0; CLI cache clearing cannot invalidate the web process opcode cache');
        self::$originalConfig = is_file(self::$configPath) ? file_get_contents(self::$configPath) : null;
        self::$originalMode = is_file(self::$configPath) ? fileperms(self::$configPath) & 0777 : null;
        self::$originalMtime = is_file(self::$configPath) ? filemtime(self::$configPath) : null;
        mkdir(self::$work, 0700);
        mkdir(self::$data, 0700);
        file_put_contents(self::$data . '/migration-complete.json', '{"purpose":"service-integration-fixture"}');
        self::$prepared = true;
        self::$bundledFilters = self::helper(['operation' => 'init'])['bundled_filters'];
    }

    public static function tearDownAfterClass(): void
    {
        if (!self::$prepared) return;
        if (self::$originalConfig === null) { if (is_file(self::$configPath)) unlink(self::$configPath); }
        else {
            file_put_contents(self::$configPath, self::$originalConfig);
            chmod(self::$configPath, self::$originalMode);
            touch(self::$configPath, self::$originalMtime);
        }
        self::helper(['operation' => 'clear']);
        $marker = json_decode(file_get_contents(self::$data . '/migration-complete.json'), true);
        if (($marker['purpose'] ?? '') !== 'service-integration-fixture') throw new \RuntimeException('Fixture identity changed; preserve evidence');
        self::clearRobotsFixture();
        foreach (scandir(self::$data) as $name) {
            if ($name === '.' || $name === '..') continue;
            if (!in_array($name, ['migration-complete.json', 'botwarden.sqlite', 'botwarden.sqlite-wal', 'botwarden.sqlite-shm'], true)) {
                throw new \RuntimeException('Unexpected fixture data; preserve evidence');
            }
            unlink(self::$data . '/' . $name);
        }
        rmdir(self::$data);
        if (is_file(self::$work . '/requests.jsonl')) unlink(self::$work . '/requests.jsonl');
        if (is_file(self::$work . '/bulk-ready')) unlink(self::$work . '/bulk-ready');
        rmdir(self::$work);
        echo "\nService fixture observations: " . json_encode(self::$observations, JSON_THROW_ON_ERROR) . "\n";
    }

    protected function setUp(): void
    {
        $this->db = new SQLite3(self::$data . '/botwarden.sqlite');
        $this->db->enableExceptions(true);
        $this->db->busyTimeout(5000);
        foreach (['local', 'history', 'abuseipdb_bulk', 'dark_visitors_agents', 'report_queue', 'ip_cache', 'metadata'] as $table) {
            $this->db->exec('DELETE FROM ' . $table);
        }
        file_put_contents(self::$work . '/requests.jsonl', '');
        if (is_file(self::$work . '/bulk-ready')) unlink(self::$work . '/bulk-ready');
        $this->configure([]);
    }

    protected function tearDown(): void
    {
        $this->db->close();
        self::clearRobotsFixture();
    }

    private static function clearRobotsFixture(): void
    {
        $path = self::$host . '/robots.txt';
        if (self::$ownsRobotsFixture) {
            if (is_link($path) || !is_file($path)) throw new \RuntimeException('Unexpected robots fixture state; preserve evidence');
            unlink($path);
            self::$ownsRobotsFixture = false;
        }
        $backups = self::$data . '/backups';
        if (is_dir($backups)) {
            if (is_link($backups)) throw new \RuntimeException('Unexpected backup symlink; preserve evidence');
            foreach (scandir($backups) as $name) {
                if ($name === '.' || $name === '..') continue;
                $file = $backups . '/' . $name;
                if (($name !== '.robots.lock' && !preg_match('/^robots-\d{8}-\d{6}-[a-f0-9]{10}\.txt$/', $name)) || is_link($file) || !is_file($file)) {
                    throw new \RuntimeException('Unexpected backup fixture; preserve evidence');
                }
                unlink($file);
            }
            rmdir($backups);
        }
    }

    private function writeRobotsFixture(string $content): string
    {
        $path = self::$host . '/robots.txt';
        $handle = fopen($path, 'x');
        if (!$handle) throw new \RuntimeException('Robots fixture path is occupied');
        self::$ownsRobotsFixture = true;
        try {
            if (fwrite($handle, $content) !== strlen($content)) throw new \RuntimeException('Incomplete robots fixture');
        } finally { fclose($handle); }
        chmod($path, 0640);
        return $path;
    }

    private static function baseline(): array
    {
        return [
            'enabled' => true, 'auto_cache' => false, 'auto_clean' => false,
            'enable_blacklisting' => true, 'enable_filtering' => false, 'enable_reporting' => false,
            'logging' => false, 'sources' => ['local' => false, 'abuseipdb_bulk' => false, 'crowdsec' => false, 'dark_visitors' => false],
            'abuseipdb' => ['api_key' => null], 'dark_visitors' => ['api_key' => null],
            'crowdsec' => ['cti_api_key' => null, 'lapi_api_key' => null],
            'robots_txt' => ['enabled' => false], 'trusted_proxies' => '127.0.0.1',
            'filters' => '/fixture-report(?:\\?|$)',
        ];
    }

    private function configure(array $config): void
    {
        file_put_contents(self::$configPath, json_encode(array_replace_recursive(self::baseline(), $config), JSON_THROW_ON_ERROR));
        clearstatcache();
        self::helper(['operation' => 'clear']);
    }

    private static function helper(array $input): array
    {
        $input['config'] = array_replace_recursive(self::baseline(), $input['config'] ?? []);
        $process = proc_open([PHP_BINARY, __DIR__ . '/fixtures/ServiceFixture.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) throw new \RuntimeException('Cannot run service fixture');
        fwrite($pipes[0], json_encode($input, JSON_THROW_ON_ERROR));
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]); fclose($pipes[1]);
        $err = stream_get_contents($pipes[2]); fclose($pipes[2]);
        $code = proc_close($process);
        if ($code !== 0) throw new \RuntimeException('Service fixture failed: ' . $err . $out);
        // Expected provider failures are logged by the production client.
        if ($err !== '' && !preg_match('/^\[botWarden\] CrowdSec (CTI|LAPI) lookup failed: [^\n]+\n$/', $err)) {
            throw new \RuntimeException('Unexpected service fixture stderr: ' . $err);
        }
        return json_decode($out, true, 512, JSON_THROW_ON_ERROR);
    }

    private function job(string $id, string $scenario = 'valid', array $config = []): array
    {
        $common = ['auto_cache' => true, 'auto_clean' => true, 'enable_reporting' => true,
            'sources' => ['abuseipdb_bulk' => true, 'dark_visitors' => true],
            'abuseipdb' => ['api_key' => 'synthetic-fixture-key'], 'dark_visitors' => ['api_key' => 'synthetic-fixture-key']];
        return self::helper(['operation' => 'job', 'id' => $id, 'scenario' => $scenario,
            'config' => array_replace_recursive($common, $config)]);
    }

    private function rows(string $table): array
    {
        $result = $this->db->query('SELECT * FROM ' . $table . ' ORDER BY rowid');
        $rows = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) $rows[] = $row;
        return $rows;
    }

    private function requests(): array
    {
        return array_map(static fn($line) => json_decode($line, true, 512, JSON_THROW_ON_ERROR),
            file(self::$work . '/requests.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
    }

    private static function http(string $path, string $ip = '8.8.8.8', string $agent = 'FixtureBrowser', array $headers = [], string $method = 'GET', ?string $requestBody = null, bool $forwarded = true): array
    {
        $handle = curl_init(self::$base . $path);
        $diagnostics = null;
        $options = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$diagnostics): int {
                if (str_starts_with($line, 'X-Botwarden-Fixture-State: ')) $diagnostics = trim(substr($line, 26));
                return strlen($line);
            },
            CURLOPT_HTTPHEADER => array_merge($forwarded ? ['X-Forwarded-For: ' . $ip] : [], $headers),
            CURLOPT_USERAGENT => $agent, CURLOPT_CUSTOMREQUEST => $method];
        if ($requestBody !== null) $options[CURLOPT_POSTFIELDS] = $requestBody;
        curl_setopt_array($handle, $options);
        $body = curl_exec($handle);
        if ($body === false) throw new \RuntimeException('Local Grav request failed: ' . curl_error($handle));
        $result = ['status' => curl_getinfo($handle, CURLINFO_RESPONSE_CODE), 'type' => curl_getinfo($handle, CURLINFO_CONTENT_TYPE), 'body' => $body, 'diagnostics' => $diagnostics];
        curl_close($handle);
        return $result;
    }

    private function waitForVisits(int $count): array
    {
        $deadline = microtime(true) + 4;
        do {
            $requests = array_values(array_filter($this->requests(), static fn($request) => $request['service'] === 'visits'));
            if (count($requests) >= $count) return $requests;
            usleep(10000);
        } while (microtime(true) < $deadline);
        return $requests;
    }

    public function testProviderRequestsUseTheManifestVersionInTheirUserAgent(): void
    {
        require_once self::$host . '/vendor/autoload.php';
        $this->assertFileDoesNotExist(self::$host . '/user/plugins/botwarden/vendor/autoload.php');
        $manifest = \Symfony\Component\Yaml\Yaml::parseFile(dirname(__DIR__) . '/blueprints.yaml');
        $this->assertTrue($this->job('botwarden-abuseipdb-cache')['success']);
        $this->assertTrue($this->job('botwarden-dark-visitors')['success']);
        self::helper(['operation' => 'cti', 'config' => ['crowdsec' => ['cti_api_key' => 'synthetic-fixture-key']]]);
        self::helper(['operation' => 'lapi', 'config' => ['crowdsec' => ['mode' => 'lapi',
            'lapi_api_key' => 'synthetic-fixture-key', 'lapi_url' => 'http://127.0.0.1:8778']]]);
        $this->db->exec("INSERT INTO report_queue (ip, path) VALUES ('198.51.100.30', '/synthetic-version-check')");
        $this->assertTrue($this->job('botwarden-flush-reports')['success']);
        $this->assertTrue(self::helper(['operation' => 'visit'])['accepted']);
        $this->configure(['dark_visitors' => ['api_key' => 'synthetic-fixture-key', 'report_visits' => true]]);
        $page = self::http('/');
        $this->assertSame(200, $page['status'], $page['diagnostics'] ?? 'No fixture diagnostics');
        $this->assertStringContainsString('text/html', (string)$page['type']);
        $this->assertStringContainsString('Synthetic botWarden migration test site.', $page['body']);
        $this->assertCount(2, $this->waitForVisits(2));
        $requests = $this->requests();
        $this->assertSame(['bulk', 'agents', 'cti', 'lapi', 'report', 'visits', 'visits'], array_column($requests, 'service'));
        foreach ($requests as $request) {
            $this->assertSame('botWarden/' . $manifest['version'], $request['user_agent'], $request['service']);
        }
    }

    public function testKnownAgentsVisitUsesBearerTokenJsonBatchAnd204Acceptance(): void
    {
        $this->assertTrue(self::helper(['operation' => 'visit'])['accepted']);
        $requests = $this->requests();
        $this->assertCount(1, $requests);
        $this->assertSame('POST', $requests[0]['method']);
        $this->assertSame('application/json', $requests[0]['content_type']);
        $this->assertTrue($requests[0]['project_token_matches']);
        $visit = json_decode($requests[0]['body'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('/fixture-visit', $visit[0]['request_path']);
        $this->assertStringNotContainsString('private', $requests[0]['body']);
    }

    public static function visitFailures(): iterable
    {
        yield ['unauthorized', 401];
        yield ['server_error', 503];
        yield ['malformed', 200];
    }

    #[DataProvider('visitFailures')]
    public function testKnownAgentsRejectsVisitResponsesOtherThan204(string $scenario, int $status): void
    {
        try {
            self::helper(['operation' => 'visit', 'scenario' => $scenario]);
            $this->fail('Unaccepted visit must not report success');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('Known Agents visits HTTP ' . $status, $error->getMessage());
        }
    }

    public function testKnownAgentsReportingRequiresItsOwnToggleAndToken(): void
    {
        foreach ([['api_key' => 'synthetic-fixture-key', 'report_visits' => false],
            ['api_key' => null, 'report_visits' => true]] as $config) {
            $this->configure(['dark_visitors' => $config]);
            $this->assertSame(200, self::http('/')['status']);
            $this->assertSame([], $this->requests());
        }
        $this->configure(['dark_visitors' => ['api_key' => 'synthetic-fixture-key', 'report_visits' => true]]);
        $response = self::http('/?token=private', '198.51.100.8', 'FixtureBrowser',
            ['Authorization: Bearer private', 'Cookie: private=private', 'X-API-Key: private',
                'Referer: https://example.invalid/page?token=private']);
        $this->assertSame(200, $response['status']);
        $requests = $this->waitForVisits(1);
        $this->assertCount(1, $requests);
        $visit = json_decode($requests[0]['body'], true, 512, JSON_THROW_ON_ERROR)[0];
        $this->assertSame('/', $visit['request_path']);
        $this->assertSame('FixtureBrowser', $visit['request_headers']['user-agent']);
        $this->assertSame('198.51.100.8', $visit['request_headers']['x-forwarded-for']);
        $this->assertSame(200, $visit['response_status_code']);
        $this->assertStringContainsString('text/html', $visit['response_headers']['content-type']);
        $this->assertGreaterThanOrEqual(0, $visit['response_duration_in_milliseconds']);
        $this->assertStringNotContainsString('private', $requests[0]['body']);
        $this->configure(['dark_visitors' => ['api_key' => 'synthetic-fixture-key', 'report_visits' => false]]);
        $this->assertSame(200, self::http('/')['status']);
        $this->assertCount(1, $this->requests());
    }

    public function testKnownAgentsTracksPublicRequestsAndProtocolHeadersWithoutBodies(): void
    {
        $this->configure(['dark_visitors' => ['api_key' => 'synthetic-fixture-key', 'report_visits' => true]]);
        $response = self::http('/?token=private', '198.51.100.8', 'FixtureAgent', [
            'MCP-Protocol-Version: 2025-06-18', 'API-Version: 2026-01-30', 'UCP-Agent: FixtureCommerce',
            'Content-Type: application/json',
        ], 'POST', '{"private":"must-not-be-reported"}');
        $requests = $this->waitForVisits(1);
        $this->assertCount(1, $requests);
        $visit = json_decode($requests[0]['body'], true, 512, JSON_THROW_ON_ERROR)[0];
        $this->assertSame('/', $visit['request_path']);
        $this->assertSame('POST', $visit['request_method']);
        $this->assertSame($response['status'], $visit['response_status_code']);
        $this->assertSame('2025-06-18', $visit['request_headers']['mcp-protocol-version']);
        $this->assertSame('2026-01-30', $visit['request_headers']['api-version']);
        $this->assertSame('FixtureCommerce', $visit['request_headers']['ucp-agent']);
        $this->assertStringNotContainsString('must-not-be-reported', $requests[0]['body']);
    }

    public static function visitReportingRoutes(): iterable
    {
        yield 'default routes' => ['/admin', '/api', 'v1', '/admin', '/api/v1'];
        yield 'custom routes' => ['/control', '/service', 'v2', '/control', '/service/v2'];
        yield 'nested routes' => ['/control/panel/', '/service/internal/', 'v3', '/control/panel', '/service/internal/v3'];
    }

    #[DataProvider('visitReportingRoutes')]
    public function testKnownAgentsSkipsManagementRoutesAndReportsPublicLookalikes(string $adminRoute, string $apiRoute, string $version, string $adminPath, string $apiPath): void
    {
        require_once self::$host . '/vendor/autoload.php';
        // The API plugin disables page processing under its base, even for a
        // different version. Keep these synthetic public aliases outside it.
        $lookalikes = [$adminPath . 'istrator', '/' . trim($apiRoute, '/') . 'ary/' . $version . '/ping'];
        if ($adminPath !== '/admin') $lookalikes[] = '/admin';
        if ($apiPath !== '/api/v1') $lookalikes[] = '/api/v1/ping';
        $pageDirectory = self::$host . '/user/pages/98.botwarden-reporting-fixture';
        $this->assertDirectoryDoesNotExist($pageDirectory);
        $this->assertFalse(is_link($pageDirectory));
        $pageContent = "---\n" . \Symfony\Component\Yaml\Yaml::dump([
            'title' => 'Synthetic reporting fixture', 'routes' => ['aliases' => $lookalikes],
        ]) . "---\nSynthetic botWarden public reporting page.\n";
        $pageCreated = false;
        $originals = [];
        foreach (['admin2', 'api'] as $plugin) {
            $path = self::$host . '/user/config/plugins/' . $plugin . '.yaml';
            $this->assertFalse(is_link($path));
            $originals[$plugin] = is_file($path)
                ? ['content' => file_get_contents($path), 'mode' => fileperms($path) & 0777, 'mtime' => filemtime($path)]
                : null;
        }
        try {
            if (!mkdir($pageDirectory, 0700)) throw new \RuntimeException('Could not create synthetic reporting page');
            $pageCreated = true;
            $handle = fopen($pageDirectory . '/default.md', 'x');
            if ($handle === false) throw new \RuntimeException('Synthetic page path is occupied');
            try {
                if (fwrite($handle, $pageContent) !== strlen($pageContent)) throw new \RuntimeException('Incomplete synthetic reporting page');
            } finally { fclose($handle); }
            foreach (['admin2' => ['route' => $adminRoute], 'api' => ['route' => $apiRoute, 'version_prefix' => $version]] as $plugin => $changes) {
                $config = $originals[$plugin] === null ? [] : \Symfony\Component\Yaml\Yaml::parse($originals[$plugin]['content']);
                file_put_contents(self::$host . '/user/config/plugins/' . $plugin . '.yaml',
                    json_encode(array_replace_recursive($config, $changes), JSON_THROW_ON_ERROR));
            }
            $this->configure(['dark_visitors' => ['api_key' => 'synthetic-fixture-key', 'report_visits' => true]]);
            foreach ([$adminPath, $adminPath . '/plugin/botwarden'] as $path) {
                $this->assertContains(self::http($path)['status'], [200, 302], $path);
            }
            $ping = self::http($apiPath . '/ping');
            $this->assertSame(200, $ping['status']);
            $this->assertStringContainsString('application/json', (string)$ping['type']);
            $this->assertSame(401, self::http($apiPath)['status']);
            $this->assertSame(401, self::http($apiPath . '/botwarden/status')['status']);
            $this->assertSame(422, self::http($apiPath . '/auth/token', '8.8.8.8', 'FixtureBrowser',
                ['Content-Type: application/json'], 'POST', '{}')['status']);
            // The single-worker server handles this after earlier shutdown hooks.
            // Its one public visit proves the reporting transport is active.
            $this->assertSame(200, self::http('/?token=private')['status']);
            $requests = $this->waitForVisits(1);
            $this->assertCount(1, $requests, 'Management requests must not send automatic visits');
            $mount = parse_url(self::$base, PHP_URL_PATH) ?: '';
            $this->assertSame($mount . '/', json_decode($requests[0]['body'], true, 512, JSON_THROW_ON_ERROR)[0]['request_path']);

            foreach ($lookalikes as $path) {
                $response = self::http($path);
                $this->assertSame(200, $response['status'], $path);
                $this->assertStringContainsString('text/html', (string)$response['type']);
                $this->assertStringContainsString('Synthetic botWarden public reporting page.', $response['body']);
            }
            $this->assertSame(200, self::http('/')['status']);
            $requests = $this->waitForVisits(count($lookalikes) + 2);
            $this->assertSame(array_map(static fn(string $path): string => $mount . $path, array_merge(['/'], $lookalikes, ['/'])), array_map(
                static fn(array $request): string => json_decode($request['body'], true, 512, JSON_THROW_ON_ERROR)[0]['request_path'],
                $requests));
        } finally {
            foreach ($originals as $plugin => $original) {
                $path = self::$host . '/user/config/plugins/' . $plugin . '.yaml';
                if ($original === null) { if (is_file($path)) unlink($path); }
                else {
                    file_put_contents($path, $original['content']);
                    chmod($path, $original['mode']);
                    touch($path, $original['mtime']);
                }
            }
            if ($pageCreated) {
                if (is_link($pageDirectory) || is_link($pageDirectory . '/default.md')
                    || scandir($pageDirectory) !== ['.', '..', 'default.md']) {
                    throw new \RuntimeException('Unexpected synthetic page contents; preserve evidence');
                }
                unlink($pageDirectory . '/default.md');
                rmdir($pageDirectory);
            }
            self::helper(['operation' => 'clear']);
        }
    }

    public function testKnownAgentsReportingFailureLeavesThePageResponseUnchanged(): void
    {
        $this->configure(['dark_visitors' => ['api_key' => 'synthetic-fixture-key', 'report_visits' => true]]);
        $response = self::http('/', '198.51.100.8', 'FixtureBrowser', ['X-Botwarden-Visit-Scenario: server_error']);
        $this->assertSame(200, $response['status']);
        $this->assertStringContainsString('Synthetic botWarden migration test site.', $response['body']);
        $this->assertStringNotContainsString('HTTP 503', $response['body']);
        $this->assertCount(1, $this->waitForVisits(1));
    }

    public function testKnownAgentsTimeoutPausesNewReportsAndRecoversAfterCooldown(): void
    {
        $this->configure(['dark_visitors' => ['api_key' => 'synthetic-fixture-key', 'report_visits' => true]]);
        $response = self::http('/', '198.51.100.8', 'FixtureBrowser', ['X-Botwarden-Visit-Scenario: timeout']);
        $this->assertSame(200, $response['status']);
        $this->assertStringContainsString('Synthetic botWarden migration test site.', $response['body']);
        // The next request waits for the preceding shutdown hook to finish.
        $this->assertSame(200, self::http('/')['status']);
        $this->assertSame(200, self::http('/api/v1/ping')['status']);
        $this->assertCount(1, $this->waitForVisits(1), 'A timeout must not trigger another delivery on the next page');
        $this->assertSame('1', \Grav\Plugin\Botwarden\BotwardenDatabase::getMeta($this->db, 'visits_failed'));
        $retryAfter = (int)\Grav\Plugin\Botwarden\BotwardenDatabase::getMeta($this->db, 'visits_retry_after');
        $this->assertGreaterThan(time(), $retryAfter);
        $this->assertLessThanOrEqual(time() + 300, $retryAfter);

        \Grav\Plugin\Botwarden\BotwardenDatabase::setMeta($this->db, 'visits_retry_after', (string)(time() - 1));
        $this->assertSame(200, self::http('/')['status']);
        $this->assertSame(200, self::http('/api/v1/ping')['status']);
        $this->assertCount(2, $this->waitForVisits(2));
        $this->assertSame('1', \Grav\Plugin\Botwarden\BotwardenDatabase::getMeta($this->db, 'visits_accepted'));
        $this->assertSame('0', \Grav\Plugin\Botwarden\BotwardenDatabase::getMeta($this->db, 'visits_retry_after'));
    }

    public function testDefaultAndEnabledSchedulerJobs(): void
    {
        $defaults = self::helper(['operation' => 'jobs']);
        $this->assertArrayHasKey('botwarden-prune-history', $defaults);
        $this->assertCount(1, array_filter(array_keys($defaults), static fn($id) => str_starts_with($id, 'botwarden-')));
        $this->assertSame([], array_filter(array_keys($defaults), static fn($id) => str_starts_with($id, 'hulk-')));
        $result = $this->job('botwarden-prune-history');
        $this->assertTrue($result['success']);
        $this->assertStringContainsString('pruned', $result['output']);
    }

    public function testConcurrentMissesReproduceOldDuplicatesAndNewClaimsSendOneLookup(): void
    {
        foreach ([true => 6, false => 1] as $legacy => $expectedCalls) {
            $this->db->exec('DELETE FROM ip_cache');
            file_put_contents(self::$work . '/requests.jsonl', '');
            $workers = [];
            try {
                for ($worker = 0; $worker < 6; $worker++) {
                    $process = proc_open([PHP_BINARY, __DIR__ . '/fixtures/ServiceFixture.php'],
                        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
                    $this->assertIsResource($process);
                    fwrite($pipes[0], json_encode(['operation' => 'lookup-race', 'worker' => $worker, 'legacy' => (bool)$legacy]));
                    fclose($pipes[0]);
                    $workers[] = [$process, $pipes];
                }
                $deadline = microtime(true) + 10;
                while (count(glob(self::$work . '/race-*.ready')) < 6 && microtime(true) < $deadline) usleep(10000);
                $this->assertCount(6, glob(self::$work . '/race-*.ready'));
                file_put_contents(self::$work . '/race.go', 'go');
            } finally {
                // Release all workers even when the readiness assertion fails.
                if (!is_file(self::$work . '/race.go')) file_put_contents(self::$work . '/race.go', 'go');
                foreach ($workers as [$process, $pipes]) {
                    $out = stream_get_contents($pipes[1]); fclose($pipes[1]);
                    $err = stream_get_contents($pipes[2]); fclose($pipes[2]);
                    $code = proc_close($process);
                    $this->assertSame(0, $code, $err . $out);
                    $this->assertSame('', $err);
                }
                foreach (glob(self::$work . '/race-*.ready') as $file) unlink($file);
                unlink(self::$work . '/race.go');
            }
            $this->assertCount($expectedCalls, $this->requests());
            if (!$legacy) $this->assertSame('block', $this->rows('ip_cache')[0]['decision']);
        }
    }

    public function testKnownAgentsBlueprintAndFreshDefaultsExposeFiveCategories(): void
    {
        require_once self::$host . '/vendor/autoload.php';
        $repo = dirname(__DIR__);
        $blueprint = \Symfony\Component\Yaml\Yaml::parseFile($repo . '/blueprints.yaml');
        $field = $blueprint['form']['fields']['dark_visitors.agent_types'];
        $expected = ['AI Assistant', 'AI Data Scraper', 'AI Data Provider', 'AI Search Crawler', 'Undocumented AI Agent'];
        $this->assertSame($expected, array_keys($field['options']));
        $this->assertSame('AI Data Provider (third-party AI data suppliers)', $field['options']['AI Data Provider']);
        $this->assertSame(array_fill_keys($expected, true), $field['default']);
        $defaults = \Symfony\Component\Yaml\Yaml::parseFile($repo . '/botwarden.yaml');
        $this->assertSame($expected, $defaults['dark_visitors']['agent_types']);
    }

    public static function knownAgentsCategorySelections(): iterable
    {
        $four = ['AI Assistant', 'AI Data Scraper', 'AI Search Crawler', 'Undocumented AI Agent'];
        $five = ['AI Assistant', 'AI Data Scraper', 'AI Data Provider', 'AI Search Crawler', 'Undocumented AI Agent'];
        yield 'fresh defaults' => [null, $five];
        yield 'existing four-category list' => [$four, $four];
        yield 'existing four-category checkbox map' => [[
            'AI Assistant' => false, 'AI Data Scraper' => true, 'AI Search Crawler' => false, 'Undocumented AI Agent' => true,
        ], ['AI Data Scraper', 'Undocumented AI Agent']];
        yield 'provider only list' => [['AI Data Provider'], ['AI Data Provider']];
        $map = array_fill_keys($five, false);
        $map['AI Data Provider'] = true;
        yield 'provider only checkbox map' => [$map, ['AI Data Provider']];
        $map['AI Data Provider'] = false; $map['AI Data Scraper'] = true; $map['Undocumented AI Agent'] = true;
        yield 'provider disabled independently' => [$map, ['AI Data Scraper', 'Undocumented AI Agent']];
        $map['AI Data Provider'] = true;
        yield 'intended site policy' => [$map, ['AI Data Scraper', 'AI Data Provider', 'Undocumented AI Agent']];
    }

    #[DataProvider('knownAgentsCategorySelections')]
    public function testSelectedCategoriesReachApiAndDriveBlockingAndRobots(?array $saved, array $expected): void
    {
        $config = ['sources' => ['dark_visitors' => true], 'robots_txt' => ['enabled' => true],
            'dark_visitors' => ['api_key' => 'synthetic-fixture-key']];
        if ($saved !== null) $config['dark_visitors']['agent_types'] = $saved;
        $this->configure($config);
        $this->assertSame($saved ?? $expected, self::helper(['operation' => 'agent-config'])['agent_types'], 'Grav must preserve an explicit selection');
        $owner = "# Independent owner policy\nUser-agent: OwnerBot\nAllow: /\n";
        $this->writeRobotsFixture($owner);
        $result = self::helper(['operation' => 'configured-job', 'id' => 'botwarden-dark-visitors', 'scenario' => 'category_rules']);
        $this->assertTrue($result['success'], $result['output']);
        $requests = $this->requests();
        $this->assertCount(1, $requests);
        $this->assertSame('POST', $requests[0]['method']);
        $this->assertSame('application/json', $requests[0]['content_type']);
        $this->assertSame($expected, json_decode($requests[0]['body'], true)['agent_types']);
        $rules = array_column($this->rows('dark_visitors_agents'), 'user_agent');
        $robots = self::http('/robots.txt');
        $this->assertSame(200, $robots['status']);
        $this->assertStringStartsWith($owner, $robots['body']);
        $agents = ['AI Assistant' => 'FixtureAssistant', 'AI Data Scraper' => 'FixtureScraper',
            'AI Data Provider' => 'FixtureDataProvider', 'AI Search Crawler' => 'FixtureSearch', 'Undocumented AI Agent' => 'FixtureUndocumented'];
        $this->assertCount(count($expected), $rules);
        foreach ($agents as $type => $agent) {
            $selected = in_array($type, $expected, true);
            $this->assertSame($selected, in_array($agent, $rules, true), $type);
            $directive = "User-agent: {$agent}\nDisallow: /";
            if ($selected) $this->assertStringContainsString($directive, $robots['body']);
            else $this->assertStringNotContainsString($directive, $robots['body']);
            $this->assertSame($selected ? 403 : 200, self::http('/', '8.8.8.8', $agent . '/1.0')['status'], $type);
        }
    }

    public function testDisablingDataProviderRemovesItsRulesOnTheNextDueRefresh(): void
    {
        $map = ['AI Assistant' => false, 'AI Data Scraper' => true, 'AI Data Provider' => true,
            'AI Search Crawler' => false, 'Undocumented AI Agent' => false];
        $config = ['sources' => ['dark_visitors' => true], 'robots_txt' => ['enabled' => true],
            'dark_visitors' => ['api_key' => 'synthetic-fixture-key', 'agent_types' => $map]];
        $this->writeRobotsFixture("# Owner policy\n");
        $this->configure($config);
        $this->assertTrue(self::helper(['operation' => 'configured-job', 'id' => 'botwarden-dark-visitors', 'scenario' => 'category_rules'])['success']);
        $this->assertSame(403, self::http('/', '8.8.8.8', 'FixtureDataProvider/1.0')['status']);
        $config['dark_visitors']['agent_types']['AI Data Provider'] = false;
        $this->configure($config);
        // Advance only this disposable cache timestamp; scheduling/TTL stay unchanged.
        $this->db->exec("UPDATE metadata SET value = '1' WHERE key = 'dark_visitors_updated'");
        $this->assertTrue(self::helper(['operation' => 'configured-job', 'id' => 'botwarden-dark-visitors', 'scenario' => 'category_rules'])['success']);
        $this->assertSame(['AI Data Scraper'], json_decode($this->requests()[1]['body'], true)['agent_types']);
        $this->assertSame(['FixtureScraper'], array_column($this->rows('dark_visitors_agents'), 'user_agent'));
        $this->assertStringNotContainsString('FixtureDataProvider', self::http('/robots.txt')['body']);
        $this->assertSame(200, self::http('/', '8.8.8.8', 'FixtureDataProvider/1.0')['status']);
        $this->assertSame(403, self::http('/', '8.8.8.8', 'FixtureScraper/1.0')['status']);
    }

    public function testFailedDataProviderRefreshPreservesBlockingAndPublishedRules(): void
    {
        $this->writeRobotsFixture("# Owner policy\n");
        $this->configure(['sources' => ['dark_visitors' => true], 'robots_txt' => ['enabled' => true],
            'dark_visitors' => ['api_key' => 'synthetic-fixture-key', 'agent_types' => ['AI Data Provider']]]);
        $this->assertTrue(self::helper(['operation' => 'configured-job', 'id' => 'botwarden-dark-visitors', 'scenario' => 'category_rules'])['success']);
        $rules = $this->rows('dark_visitors_agents'); $robots = self::http('/robots.txt')['body'];
        $this->db->exec("UPDATE metadata SET value = '1' WHERE key = 'dark_visitors_updated'");
        foreach (['unauthorized', 'server_error', 'empty', 'malformed'] as $scenario) {
            $result = self::helper(['operation' => 'configured-job', 'id' => 'botwarden-dark-visitors', 'scenario' => $scenario]);
            $this->assertFalse($result['success'], $scenario);
            $this->assertSame($rules, $this->rows('dark_visitors_agents'));
            $this->assertSame($robots, self::http('/robots.txt')['body']);
            $this->assertSame(403, self::http('/', '8.8.8.8', 'FixtureDataProvider/1.0')['status']);
        }
    }

    public function testKnownAgentsTtlControlsDownloadsAndPublishingCanUseFreshRules(): void
    {
        $this->assertTrue($this->job('botwarden-dark-visitors')['success']);
        $this->assertCount(1, $this->requests());
        $this->assertTrue($this->job('botwarden-dark-visitors')['success']);
        $this->assertCount(1, $this->requests(), 'Fresh data must not trigger a download');
        $this->db->exec("UPDATE metadata SET value = strftime('%s', 'now') - 7200 WHERE key = 'dark_visitors_updated'");
        $this->assertTrue($this->job('botwarden-dark-visitors', 'valid', ['dark_visitors' => ['cache_ttl' => 3600]])['success']);
        $this->assertCount(2, $this->requests());
        $this->assertTrue($this->job('botwarden-dark-visitors', 'valid', ['dark_visitors' => ['cache_ttl' => 86400]])['success']);
        $this->assertCount(2, $this->requests());
    }

    public function testReportBackoffStopsRequestsWithoutDroppingPendingRows(): void
    {
        $this->db->exec("INSERT INTO report_queue (ip, path) VALUES ('8.8.8.8', '/probe'), ('1.1.1.1', '/other')");
        $started = time();
        $this->assertFalse($this->job('botwarden-flush-reports', 'rate_limit')['success']);
        $this->assertCount(1, $this->requests());
        $this->assertGreaterThanOrEqual($started + 3600, (int)$this->db->querySingle("SELECT value FROM metadata WHERE key = 'reports_retry_after'"));
        $this->assertTrue($this->job('botwarden-flush-reports', 'rate_limit')['success']);
        $this->assertCount(1, $this->requests());
        $this->assertCount(2, $this->rows('report_queue'));
    }

    public function testMalformedFiltersDoNotEmitVisitorWarningsAndQueriesStayOutOfLogs(): void
    {
        $this->configure(['enable_filtering' => true, 'filters' => '[', 'logging' => true]);
        $response = self::http('/?private=synthetic-secret');
        $this->assertSame(200, $response['status']);
        $this->assertStringNotContainsString('Warning', $response['body']);
        $log = self::$host . '/logs/grav.log';
        clearstatcache(true, $log);
        $offset = is_file($log) ? filesize($log) : 0;
        $this->configure(['enable_filtering' => true, 'filters' => 'fixture-private', 'logging' => true]);
        $this->assertSame(403, self::http('/fixture-private?private=synthetic-secret')['status']);
        $handle = fopen($log, 'r'); fseek($handle, $offset); $newLog = stream_get_contents($handle); fclose($handle);
        $this->assertStringNotContainsString('synthetic-secret', $newLog);
    }

    public function testSuccessfulFeedJobsReplaceListsAndRecordSuccess(): void
    {
        $started = time();
        $bulk = $this->job('botwarden-abuseipdb-cache');
        $agents = $this->job('botwarden-dark-visitors');
        $this->assertTrue($bulk['success']);
        $this->assertTrue($agents['success']);
        $this->assertCount(3, $this->rows('abuseipdb_bulk'));
        $this->assertCount(2, $this->rows('dark_visitors_agents'));
        $this->assertCount(5, $this->rows('metadata'));
        $meta = array_column($this->rows('metadata'), 'value', 'key');
        $this->assertSame('3', $meta['abuseipdb_bulk_count']);
        $this->assertGreaterThanOrEqual($started, (int)$meta['abuseipdb_bulk_updated']);
        $this->assertGreaterThanOrEqual($started, (int)$meta['dark_visitors_updated']);
        $this->assertSame('success', $bulk['state']);
        $this->assertSame('success', $agents['state']);
        self::$observations['bulk'] = ['http' => 200, 'count' => 3, 'duration_ms' => $bulk['duration_ms'], 'last_success' => $meta['abuseipdb_bulk_updated']];
        self::$observations['agents'] = ['http' => 200, 'count' => 2, 'duration_ms' => $agents['duration_ms'], 'last_success' => $meta['dark_visitors_updated']];
        $requests = $this->requests();
        $this->assertSame(['bulk', 'agents'], array_column($requests, 'service'));
        $this->assertSame(['GET', 'POST'], array_column($requests, 'method'));
        $this->assertSame('/', json_decode($requests[1]['body'], true)['disallow']);
        $this->assertSame('ok', $this->db->querySingle('PRAGMA integrity_check'));
    }

    public static function failedFeeds(): iterable
    {
        foreach (['bulk' => 'botwarden-abuseipdb-cache', 'agents' => 'botwarden-dark-visitors'] as $service => $job) {
            foreach (['network', 'unauthorized', 'server_error', 'rate_limit', 'empty', 'malformed', 'oversized'] as $scenario) {
                yield $service . ' ' . $scenario => [$job, $scenario];
            }
        }
    }

    #[DataProvider('failedFeeds')]
    public function testFailedFeedsPreserveDataAndSchedulerReportsFailure(string $job, string $scenario): void
    {
        $this->job('botwarden-abuseipdb-cache');
        $this->job('botwarden-dark-visitors');
        if ($job === 'botwarden-dark-visitors') $this->db->exec("UPDATE metadata SET value = '1' WHERE key = 'dark_visitors_updated'");
        $before = [$this->rows('abuseipdb_bulk'), $this->rows('dark_visitors_agents')];
        $metaBefore = array_column($this->rows('metadata'), 'value', 'key');
        $result = $this->job($job, $scenario);
        $this->assertSame($before, [$this->rows('abuseipdb_bulk'), $this->rows('dark_visitors_agents')]);
        $meta = array_column($this->rows('metadata'), 'value', 'key');
        $source = $job === 'botwarden-dark-visitors' ? 'dark_visitors' : 'abuseipdb_bulk';
        $this->assertSame($metaBefore[$source . '_updated'], $meta[$source . '_updated']);
        $this->assertNotEmpty($meta[$source . '_error']);
        $this->assertStringContainsString('failed', $result['output']);
        $this->assertFalse($result['success'], 'A failed fetch must not be recorded as a successful scheduler job');
        $this->assertSame('failure', $result['state']);
    }

    public static function intelligenceResponses(): iterable
    {
        foreach (['cti', 'lapi'] as $mode) {
            foreach (['block', 'clean', 'network', 'unauthorized', 'server_error', 'malformed'] as $scenario) {
                yield $mode . ' ' . $scenario => [$mode, $scenario, $scenario === 'block' ? 'block' : 'clean', in_array($scenario, ['block', 'clean'], true)];
            }
        }
        yield 'CTI 404' => ['cti', 'not_found', 'clean', true];
        yield 'CTI quota' => ['cti', 'rate_limit', 'clean', false];
        yield 'LAPI empty' => ['lapi', 'empty', 'clean', true];
    }

    #[DataProvider('intelligenceResponses')]
    public function testIntelligenceResponsePolicy(string $mode, string $scenario, string $decision, bool $cacheable): void
    {
        $result = self::helper(['operation' => $mode, 'scenario' => $scenario, 'config' => ['crowdsec' => [
            'mode' => $mode, 'cti_api_key' => 'synthetic-fixture-key', 'lapi_api_key' => 'synthetic-fixture-key',
            'lapi_url' => 'http://127.0.0.1:8778']]]);
        $this->assertSame($decision, $result['decision']);
        $this->assertSame($cacheable, $result['cacheable']);
        if ($scenario === 'rate_limit') {
            $this->assertSame((string)(new \DateTimeImmutable('tomorrow', new \DateTimeZone('UTC')))->getTimestamp(), $this->rows('metadata')[0]['value']);
        }
    }

    public function testQuotaBlocksFurtherCallsUntilReset(): void
    {
        $input = ['operation' => 'cti', 'scenario' => 'rate_limit', 'config' => ['crowdsec' => ['cti_api_key' => 'synthetic-fixture-key']]];
        self::helper($input);
        $input['scenario'] = 'block';
        $result = self::helper($input);
        $this->assertSame('CTI quota exceeded', $result['reason']);
        $this->assertCount(1, $this->requests());
        $this->db->exec("UPDATE metadata SET value = '1' WHERE key = 'crowdsec_cti_quota_reset'");
        $result = self::helper($input);
        $this->assertSame('block', $result['decision']);
        $this->assertCount(2, $this->requests());
    }

    public function testLapiCacheHitExpirationAndFailureSentinelThroughGrav(): void
    {
        $config = ['sources' => ['crowdsec' => true], 'crowdsec' => ['mode' => 'lapi', 'lapi_api_key' => 'synthetic-fixture-key',
            'lapi_url' => 'http://127.0.0.1:8778/mock/lapi/block', 'lapi_cache_ttl' => 60]];
        $this->configure($config);
        $this->assertSame(403, self::http('/')['status']);
        $this->assertSame(403, self::http('/')['status']);
        $this->assertCount(1, $this->requests());
        $this->assertSame('block', $this->rows('ip_cache')[0]['decision']);
        $this->db->exec('UPDATE ip_cache SET expires_at = 1');
        $config['crowdsec']['lapi_url'] = 'http://127.0.0.1:8778/mock/lapi/clean';
        $this->configure($config);
        $this->assertSame(200, self::http('/')['status']);
        $this->assertCount(2, $this->requests());
        $this->db->exec('DELETE FROM ip_cache');
        $config['crowdsec']['lapi_url'] = 'http://127.0.0.1:8778/mock/lapi/server_error';
        $this->configure($config);
        $response = self::http('/');
        $this->assertSame(200, $response['status']);
        $this->assertStringContainsString('text/html', $response['type']);
        $this->assertLessThanOrEqual(time() + 10, (int)$this->rows('ip_cache')[0]['expires_at']);
        $this->assertSame(200, self::http('/')['status']);
        $this->assertCount(3, $this->requests(), 'The short sentinel prevents immediate retry after failure');
        $this->db->exec('UPDATE ip_cache SET expires_at = 1');
        $this->assertSame(200, self::http('/')['status']);
        $this->assertCount(4, $this->requests());
    }

    public static function trustedProxyAttributionCases(): iterable
    {
        foreach (['173.245.48.1', '2606:4700:4700::1111'] as $peer) {
            $cases = [
                'missing client headers' => [[], null],
                'empty Cloudflare header' => [['CF-Connecting-IP;'], null],
                'invalid Cloudflare header' => [['CF-Connecting-IP: invalid'], null],
                'empty real-IP header' => [['X-Real-IP;'], null],
                'invalid real-IP header' => [['X-Real-IP: invalid'], null],
                'empty forwarded chain' => [['X-Forwarded-For;'], null],
                'invalid forwarded chain' => [['X-Forwarded-For: invalid, also-invalid'], null],
                'only trusted proxy hops' => [['X-Forwarded-For: 173.245.48.1, ' . $peer], null],
                'client header names the peer' => [['CF-Connecting-IP: ' . $peer], null],
                'valid Cloudflare client' => [['CF-Connecting-IP: 8.8.8.8'], '8.8.8.8'],
                'valid real-IP fallback' => [['CF-Connecting-IP: invalid', 'X-Real-IP: 8.8.8.8'], '8.8.8.8'],
                'valid forwarded-chain fallback' => [['X-Real-IP: invalid', 'X-Forwarded-For: 8.8.8.8, ' . $peer], '8.8.8.8'],
            ];
            if (str_contains($peer, ':')) {
                $cases['equivalent IPv6 peer address'] = [['CF-Connecting-IP: 2606:4700:4700:0:0:0:0:1111'], null];
            }
            foreach ($cases as $name => [$headers, $client]) {
                foreach ([true, false] as $preset) {
                    yield $peer . ' ' . $name . ($preset ? ' preset' : ' custom trust') => [$peer, $headers, $client, $preset];
                }
            }
        }
    }

    #[DataProvider('trustedProxyAttributionCases')]
    public function testTrustedProxyFallbackDoesNotCreatePersistentBans(string $peer, array $forwardingHeaders, ?string $client, bool $preset): void
    {
        $this->configure(['trusted_proxies' => $preset ? '' : $peer . "\n173.245.48.1", 'trusted_proxy_preset' => $preset ? 'cloudflare' : 'none',
            'enable_filtering' => true, 'enable_reporting' => true, 'sources' => ['local' => true],
            'abuseipdb' => ['api_key' => 'synthetic-fixture-key']]);
        $headers = array_merge(['X-Botwarden-Fixture-Peer: ' . $peer], $forwardingHeaders);
        $response = self::http('/fixture-report', '8.8.8.8', 'FixtureBrowser', $headers, 'GET', null, false);
        $this->assertSame(403, $response['status']);
        $this->assertSame('Forbidden', $response['body']);
        $expected = $client === null ? [] : [$client];
        $this->assertSame([$expected, $expected, $expected],
            [array_column($this->rows('local'), 'ip'), array_column($this->rows('history'), 'ip'),
                array_column($this->rows('report_queue'), 'ip')],
            'Only a visitor address, never the unresolved proxy, may be persisted');
        $this->assertSame($client === null ? 200 : 403,
            self::http('/', '8.8.8.8', 'FixtureBrowser', $headers, 'GET', null, false)['status']);
        $this->assertSame(200, self::http('/', '9.9.9.9', 'FixtureBrowser',
            ['X-Botwarden-Fixture-Peer: ' . $peer, 'CF-Connecting-IP: 9.9.9.9'], 'GET', null, false)['status']);
        $this->assertSame([], $this->requests());
    }

    public function testUnresolvedTrustedProxyDoesNotBypassManualBlocks(): void
    {
        $this->configure(['trusted_proxies' => '', 'trusted_proxy_preset' => 'cloudflare',
            'enable_filtering' => true, 'enable_reporting' => true, 'sources' => ['local' => true],
            'abuseipdb' => ['api_key' => 'synthetic-fixture-key']]);
        $this->db->exec("INSERT INTO local (ip, is_manual) VALUES ('173.245.48.1', 1)");
        $before = $this->rows('local');
        $response = self::http('/fixture-report', '8.8.8.8', 'FixtureBrowser',
            ['X-Botwarden-Fixture-Peer: 173.245.48.1'], 'GET', null, false);
        $this->assertSame(403, $response['status']);
        $this->assertSame($before, $this->rows('local'));
        $this->assertSame([], $this->rows('history'));
        $this->assertSame([], $this->rows('report_queue'));
    }

    public static function bundledFilterCases(): iterable
    {
        foreach (['/.env', '/.env.backup', '/.env.local'] as $path) {
            yield $path . ' untrusted shared peer' => [$path, 'none', ['X-Forwarded-For: 8.8.8.8'], null];
            yield $path . ' unresolved trusted peer' => [$path, 'cloudflare', [], null];
            yield $path . ' attributed direct probe' => [$path, 'cloudflare', ['CF-Connecting-IP: 8.8.8.8'], '8.8.8.8'];
            yield $path . ' attributed cross-site probe' => [$path, 'cloudflare', ['CF-Connecting-IP: 8.8.8.8', 'Sec-Fetch-Site: cross-site'], null];
        }
    }

    #[DataProvider('bundledFilterCases')]
    public function testBundledEnvironmentFiltersKeepSharedProxyVisitorsIndependent(string $path, string $preset, array $forwardingHeaders, ?string $client): void
    {
        $this->configure(['filters' => self::$bundledFilters, 'trusted_proxies' => '',
            'trusted_proxy_preset' => $preset, 'enable_filtering' => true, 'enable_reporting' => true,
            'sources' => ['local' => true], 'abuseipdb' => ['api_key' => 'synthetic-fixture-key']]);
        $headers = array_merge(['X-Botwarden-Fixture-Peer: 173.245.48.1'], $forwardingHeaders);
        $probe = self::http($path, '8.8.8.8', 'FixtureBrowser', $headers, 'GET', null, false);
        $this->assertSame(403, $probe['status']);
        $this->assertSame('Forbidden', $probe['body']);
        foreach (['local', 'history', 'report_queue'] as $table) {
            $this->assertSame($client === null ? [] : [$client], array_column($this->rows($table), 'ip'), $table);
        }
        if ($client !== null) $this->assertSame($path, $this->rows('report_queue')[0]['path']);
        $this->assertSame($client === null ? 200 : 403, self::http('/', '8.8.8.8', 'FixtureBrowser', $headers, 'GET', null, false)['status']);
        $this->assertSame(200, self::http('/', '9.9.9.9', 'FixtureBrowser',
            ['X-Botwarden-Fixture-Peer: 173.245.48.1', 'CF-Connecting-IP: 9.9.9.9'])['status']);
        $this->assertSame([], $this->requests());
    }

    public static function browserFilterCases(): iterable
    {
        foreach (['cross-site', 'same-site', 'same-origin'] as $site) {
            foreach (['image', 'link'] as $action) yield $site . ' ' . $action => [$site, $action];
        }
        yield 'direct navigation' => ['none', 'navigate'];
    }

    #[DataProvider('browserFilterCases')]
    public function testBrowserOwnedMetadataProtectsReadersOfEnvironmentProbes(string $site, string $action): void
    {
        if (getenv('BOTWARDEN_BROWSER_URL') !== 'http://127.0.0.1:8877') {
            $this->markTestSkipped('Opt in to the isolated local Chrome fixture.');
        }
        $this->configure(['filters' => self::$bundledFilters, 'trusted_proxies' => '', 'trusted_proxy_preset' => 'cloudflare',
            'enable_filtering' => true, 'enable_reporting' => true, 'sources' => ['local' => true],
            'abuseipdb' => ['api_key' => 'synthetic-fixture-key']]);
        $process = proc_open(['node', __DIR__ . '/fixtures/BrowserProbe.mjs', $site, $action],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);
        $out = stream_get_contents($pipes[1]); fclose($pipes[1]);
        $err = stream_get_contents($pipes[2]); fclose($pipes[2]);
        $this->assertSame(0, proc_close($process), $err . $out);
        $this->assertSame('', $err);
        $result = json_decode($out, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('http://127.0.0.1:8777/.env', $result['url']);
        $this->assertSame(403, $result['status']);
        $this->assertSame($site, $result['fetchSite']);
        $this->assertSame($action === 'image' ? 'no-cors' : 'navigate', $result['fetchMode']);
        $this->assertSame($action === 'image' ? 'image' : 'document', $result['fetchDest']);
        $persist = in_array($site, ['none', 'same-origin'], true);
        foreach (['local', 'history', 'report_queue'] as $table) {
            $this->assertSame($persist ? ['8.8.8.8'] : [], array_column($this->rows($table), 'ip'), $table);
        }
        $this->assertSame($persist ? 403 : 200, self::http('/', '8.8.8.8', 'FixtureBrowser',
            ['X-Botwarden-Fixture-Peer: 173.245.48.1', 'CF-Connecting-IP: 8.8.8.8'])['status']);
        $this->assertSame(200, self::http('/', '9.9.9.9', 'FixtureBrowser',
            ['X-Botwarden-Fixture-Peer: 173.245.48.1', 'CF-Connecting-IP: 9.9.9.9'])['status']);
        $this->assertSame([], $this->requests());
        self::$observations[] = ['browser_probe' => $action, 'metadata' => $result];
    }

    public static function uriFilterProxyCases(): iterable
    {
        foreach (['1.1.1.1' => '1.1.1.0/24', '2606:4700:4700::1111' => '2606:4700::/32'] as $peer => $cidr) {
            foreach (['CF-Connecting-IP', 'X-Forwarded-For', 'X-Real-IP'] as $header) {
                yield $peer . ' ' . $header . ' empty trust' => [$peer, '', $header, false];
                yield $peer . ' ' . $header . ' nonmatching trust' => [$peer, '9.9.9.9', $header, false];
                yield $peer . ' ' . $header . ' trusted CIDR' => [$peer, $cidr, $header, true];
            }
            yield $peer . ' direct request' => [$peer, '', null, true];
        }
    }

    #[DataProvider('uriFilterProxyCases')]
    public function testUriFilterDoesNotPersistUntrustedProxyAddresses(string $peer, string $trusted, ?string $header, bool $persist): void
    {
        $this->configure(['enable_filtering' => true, 'enable_reporting' => true,
            'trusted_proxies' => $trusted, 'sources' => ['local' => true],
            'abuseipdb' => ['api_key' => 'synthetic-fixture-key']]);
        $headers = ['X-Botwarden-Fixture-Peer: ' . $peer];
        if ($header !== null) $headers[] = $header . ': 8.8.8.8';
        $response = self::http('/fixture-report', '8.8.8.8', 'FixtureBrowser', $headers, 'GET', null, false);
        $this->assertSame(403, $response['status']);
        $this->assertSame('Forbidden', $response['body']);
        foreach (['local', 'history', 'report_queue'] as $table) {
            $rows = $this->rows($table);
            $this->assertCount($persist ? 1 : 0, $rows, $table);
            if ($persist) $this->assertSame($header === null ? $peer : '8.8.8.8', $rows[0]['ip']);
        }
        $this->assertSame($persist ? 403 : 200,
            self::http('/', '8.8.8.8', 'FixtureBrowser', $headers, 'GET', null, false)['status']);
        $this->assertSame([], $this->requests(), 'URI matches must not report synchronously');
    }

    public function testUntrustedForwardingHeadersDoNotBypassExistingLocalBlocks(): void
    {
        $this->configure(['trusted_proxies' => '', 'sources' => ['local' => true]]);
        $this->db->exec("INSERT INTO local (ip, is_manual) VALUES ('1.1.1.1', 1)");
        $this->assertSame(403, self::http('/', '8.8.8.8', 'FixtureBrowser',
            ['X-Botwarden-Fixture-Peer: 1.1.1.1'])['status']);
        $this->assertCount(1, $this->rows('local'));
        $this->assertSame([], $this->rows('report_queue'));
    }

    public function testCloudflarePresetUsesTheVisitorForUriFilterPersistence(): void
    {
        $this->configure(['trusted_proxies' => '', 'trusted_proxy_preset' => 'cloudflare',
            'enable_filtering' => true, 'enable_reporting' => true, 'sources' => ['local' => true],
            'abuseipdb' => ['api_key' => 'synthetic-fixture-key']]);
        $headers = ['X-Botwarden-Fixture-Peer: 173.245.48.1', 'CF-Connecting-IP: 8.8.8.8'];
        $this->assertSame(403, self::http('/fixture-report', '8.8.8.8', 'FixtureBrowser', $headers)['status']);
        foreach (['local', 'history', 'report_queue'] as $table) {
            $this->assertSame(['8.8.8.8'], array_column($this->rows($table), 'ip'));
        }
        $headers[1] = 'CF-Connecting-IP: 9.9.9.9';
        $this->assertSame(200, self::http('/', '9.9.9.9', 'FixtureBrowser', $headers)['status']);
        $this->assertSame([], $this->requests());
    }

    public static function uriFilterFetchSiteCases(): iterable
    {
        foreach ([[null, true], ['none', true], ['same-origin', true], ['cross-site', false],
            ['same-site', false], ['unexpected', false], ['', false], ['same-origin, cross-site', false]] as [$site, $allow]) {
            foreach ([true, false] as $trusted) {
                yield ($site ?? 'absent') . ($trusted ? ' trusted proxy' : ' untrusted proxy') => [
                    $site, $trusted, $trusted && $allow,
                ];
            }
        }
    }

    #[DataProvider('uriFilterFetchSiteCases')]
    public function testUriFilterDoesNotPersistCrossSiteRequests(?string $site, bool $trusted, bool $persist): void
    {
        $this->configure(['enable_filtering' => true, 'enable_reporting' => true,
            'trusted_proxies' => $trusted ? '1.1.1.0/24' : '', 'sources' => ['local' => true],
            'abuseipdb' => ['api_key' => 'synthetic-fixture-key']]);
        $headers = ['X-Botwarden-Fixture-Peer: 1.1.1.1'];
        if ($site !== null) $headers[] = $site === '' ? 'Sec-Fetch-Site;' : 'Sec-Fetch-Site: ' . $site;
        $response = self::http('/fixture-report', '8.8.8.8', 'FixtureBrowser', $headers);
        $this->assertSame(403, $response['status']);
        $this->assertSame('Forbidden', $response['body']);
        foreach (['local', 'history', 'report_queue'] as $table) {
            $rows = $this->rows($table);
            $this->assertCount($persist ? 1 : 0, $rows, $table);
            if ($persist) $this->assertSame('8.8.8.8', $rows[0]['ip']);
        }
        $normal = self::http('/', '8.8.8.8', 'FixtureBrowser', $headers);
        $this->assertSame($persist ? 403 : 200, $normal['status']);
        if (!$persist) $this->assertStringContainsString('text/html', $normal['type']);
        $this->assertSame([], $this->requests());
    }

    public function testCrossSiteFilteringPreservesDisabledBlacklisting(): void
    {
        $this->configure(['enable_filtering' => true, 'enable_blacklisting' => false,
            'enable_reporting' => true, 'filters' => '^/$',
            'abuseipdb' => ['api_key' => 'synthetic-fixture-key']]);
        $response = self::http('/', '8.8.8.8', 'FixtureBrowser', ['Sec-Fetch-Site: cross-site']);
        $this->assertSame(200, $response['status']);
        foreach (['local', 'history', 'report_queue'] as $table) $this->assertSame([], $this->rows($table));
    }

    public function testCrossSiteHeadersDoNotBypassExistingLocalBlocks(): void
    {
        $this->configure(['sources' => ['local' => true]]);
        $this->db->exec("INSERT INTO local (ip, is_manual) VALUES ('8.8.8.8', 1)");
        $this->assertSame(403, self::http('/', '8.8.8.8', 'FixtureBrowser', ['Sec-Fetch-Site: cross-site'])['status']);
        $this->assertCount(1, $this->rows('local'));
        $this->assertSame([], $this->rows('report_queue'));
    }

    public function testReportingQueuesOnlyConfiguredUriEventsWithoutQueryStrings(): void
    {
        $config = ['enable_filtering' => true, 'enable_reporting' => true, 'abuseipdb' => ['api_key' => 'synthetic-fixture-key']];
        $this->configure($config);
        $this->assertSame(200, self::http('/')['status']);
        $this->assertCount(0, $this->rows('report_queue'));
        $this->assertSame(403, self::http('/fixture-report?synthetic_private_value=do-not-report')['status']);
        $queue = $this->rows('report_queue');
        $this->assertCount(1, $queue);
        $this->assertSame('/fixture-report', $queue[0]['path']);
        $this->assertSame([], $this->requests(), 'Request filtering must not send a synchronous report');
        $config['enable_reporting'] = false;
        $this->configure($config);
        $this->assertSame(403, self::http('/fixture-report', '1.1.1.1')['status']);
        $this->assertCount(1, $this->rows('report_queue'));
        $config['enable_reporting'] = true;
        $config['abuseipdb']['api_key'] = null;
        $this->configure($config);
        $this->assertSame(403, self::http('/fixture-report', '9.9.9.9')['status']);
        $this->assertCount(1, $this->rows('report_queue'));
    }

    public function testReportRetriesBoundedAndSuccessfulReportsRemoved(): void
    {
        $this->db->exec("INSERT INTO report_queue (ip, path) VALUES ('198.51.100.30', '/synthetic-report')");
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->db->exec("DELETE FROM metadata WHERE key = 'reports_retry_after'");
            $result = $this->job('botwarden-flush-reports', 'rate_limit');
            $this->assertFalse($result['success'], 'Deferred failed reports must be visible as a failed scheduler run');
            $this->assertStringContainsString('0 queued', $result['output']);
            $queue = $this->rows('report_queue');
            if ($attempt < 5) $this->assertSame($attempt, $queue[0]['attempts']);
            else $this->assertSame([], $queue);
        }
        $this->db->exec("DELETE FROM metadata WHERE key = 'reports_retry_after'");
        $this->db->exec("INSERT INTO report_queue (ip, path) VALUES ('198.51.100.31', '/synthetic-report')");
        $result = $this->job('botwarden-flush-reports');
        $this->assertTrue($result['success']);
        $this->assertSame([], $this->rows('report_queue'));
        $request = $this->requests()[5];
        parse_str($request['body'], $body);
        $this->assertSame('POST', $request['method']);
        $this->assertSame('198.51.100.31', $body['ip']);
        $this->assertSame('21', $body['categories']);
        $this->assertStringContainsString('/synthetic-report', $body['comment']);
    }

    public function testKnownAgentsEnforcementAndSourceToggle(): void
    {
        $this->job('botwarden-dark-visitors');
        $this->configure(['sources' => ['dark_visitors' => true]]);
        $this->assertSame(403, self::http('/', '8.8.8.8', 'FixtureBot/1.0')['status']);
        $this->assertSame(200, self::http('/')['status']);
        $this->configure([]);
        $this->assertSame(200, self::http('/', '8.8.8.8', 'FixtureBot/1.0')['status']);
    }

    public function testReportFlushProcessesAtMostTwoHundredRows(): void
    {
        $this->db->exec('BEGIN');
        for ($i = 0; $i < 201; $i++) $this->db->exec("INSERT INTO report_queue (ip, path) VALUES ('198.51.100.32', '/bounded-synthetic-report')");
        $this->db->exec('COMMIT');
        $result = $this->job('botwarden-flush-reports');
        $this->assertTrue($result['success']);
        $this->assertCount(200, $this->requests());
        $this->assertCount(1, $this->rows('report_queue'));
        $this->assertSame(0, $this->rows('report_queue')[0]['attempts']);
    }

    public function testHighCardinalityQuotaSentinelsExpireThroughRegisteredMaintenance(): void
    {
        $config = ['auto_clean' => false, 'sources' => ['local' => true, 'crowdsec' => true],
            'crowdsec' => ['mode' => 'cti', 'cti_api_key' => 'synthetic-fixture-key', 'cti_cache_ttl' => 3600]];
        $this->configure($config);
        $started = time();
        for ($i = 0; $i < 512; $i++) {
            $ip = '11.0.' . intdiv($i, 250) . '.' . ($i % 250 + 1);
            $this->assertSame(200, self::http('/', $ip, 'FixtureBrowser', ['X-Botwarden-Cti-Scenario: rate_limit'])['status'], $ip);
        }
        $cache = $this->rows('ip_cache');
        $this->assertCount(512, $cache);
        $this->assertSame(['crowdsec_cti'], array_values(array_unique(array_column($cache, 'source'))));
        $this->assertSame(['clean'], array_values(array_unique(array_column($cache, 'decision'))));
        $this->assertSame([0], array_values(array_unique(array_column($cache, 'score'))));
        $this->assertGreaterThanOrEqual($started + 10, min(array_column($cache, 'expires_at')));
        $this->assertLessThanOrEqual(time() + 10, max(array_column($cache, 'expires_at')));
        $this->assertSame(['cti'], array_column($this->requests(), 'service'), 'Quota lock must suppress all 511 later provider calls');
        $quota = $this->db->querySingle("SELECT value FROM metadata WHERE key = 'crowdsec_cti_quota_reset'");
        $this->assertGreaterThan(time(), (int)$quota);

        // Advance only the synthetic cache clock; avoid waiting for each TTL.
        $this->db->exec("UPDATE ip_cache SET expires_at = 1");
        $this->db->exec("INSERT INTO ip_cache (ip, source, decision, expires_at) VALUES ('9.9.9.9', 'crowdsec_cti', 'block', strftime('%s','now') + 3600), ('1.1.1.1', 'crowdsec_lapi', 'clean', strftime('%s','now') + 3600)");
        $this->db->exec("INSERT INTO local (ip, is_manual, added_at) VALUES ('8.8.8.8', 0, 1), ('9.9.9.9', 1, 1), ('1.1.1.1', 0, strftime('%s','now'))");
        $this->db->exec("INSERT INTO history (ip, action, acted_at) VALUES ('8.8.8.8', 'block', 1), ('1.1.1.1', 'block', strftime('%s','now'))");
        $result = self::helper(['operation' => 'job', 'id' => 'botwarden-prune-history', 'config' => $config]);
        $this->assertTrue($result['success']);
        $this->assertSame('success', $result['state']);
        $this->assertSame(['9.9.9.9', '1.1.1.1'], array_column($this->rows('ip_cache'), 'ip'));
        $this->assertSame(['9.9.9.9', '1.1.1.1'], array_column($this->rows('local'), 'ip'));
        $this->assertSame(['1.1.1.1'], array_column($this->rows('history'), 'ip'));
        $this->assertSame($quota, $this->db->querySingle("SELECT value FROM metadata WHERE key = 'crowdsec_cti_quota_reset'"));
        $before = [$this->rows('ip_cache'), $this->rows('local'), $this->rows('history')];
        $this->assertTrue(self::helper(['operation' => 'job', 'id' => 'botwarden-prune-history', 'config' => $config])['success']);
        $this->assertSame($before, [$this->rows('ip_cache'), $this->rows('local'), $this->rows('history')]);

        $this->assertSame(200, self::http('/', '11.4.0.1')['status']);
        $this->assertCount(1, $this->requests(), 'Maintenance must not prematurely clear the quota lock');
        $this->db->exec("UPDATE metadata SET value = '1' WHERE key = 'crowdsec_cti_quota_reset'");
        $this->db->exec("UPDATE ip_cache SET expires_at = 1 WHERE ip = '11.4.0.1'");
        $this->assertSame(403, self::http('/', '11.4.0.1')['status']);
        $this->assertCount(2, $this->requests());
        $recovered = $this->db->querySingle("SELECT decision, score, expires_at FROM ip_cache WHERE ip = '11.4.0.1'", true);
        $this->assertSame('block', $recovered['decision']);
        $this->assertSame(4, $recovered['score']);
        $this->assertGreaterThanOrEqual(time() + 3595, $recovered['expires_at']);
        $this->assertSame(403, self::http('/', '11.4.0.1')['status']);
        $this->assertCount(2, $this->requests(), 'Recovered authoritative answer must be cached');
        $this->assertSame('ok', $this->db->querySingle('PRAGMA integrity_check'));
        self::$observations['quota_spike'] = ['visitors' => 512, 'expired_sentinels_pruned' => 512, 'auto_clean' => false];
    }

    public static function maintenanceJobs(): iterable
    {
        yield 'expiry independent of auto_clean' => ['botwarden-prune-history', false, 86400, ['9.9.9.9', '1.1.1.1']];
        yield 'zero TTL preserves automatic blocks' => ['botwarden-prune-history', false, 0, ['8.8.8.8', '9.9.9.9', '1.1.1.1']];
        yield 'optional cleanup still prunes before vacuum' => ['botwarden-auto-clean', true, 86400, ['9.9.9.9', '1.1.1.1']];
    }

    #[DataProvider('maintenanceJobs')]
    public function testMaintenanceJobsPruneOnlyExpiredAutomaticData(string $job, bool $autoClean, int $localTtl, array $expectedLocal): void
    {
        $config = ['auto_clean' => $autoClean, 'local_ttl' => $localTtl, 'sources' => ['local' => true]];
        $this->configure($config);
        $this->db->exec("INSERT INTO local (ip, is_manual, added_at) VALUES ('8.8.8.8', 0, 1), ('9.9.9.9', 1, 1), ('1.1.1.1', 0, strftime('%s', 'now'))");
        $this->db->exec("INSERT INTO history (ip, action, acted_at) VALUES ('8.8.8.8', 'block', 1), ('1.1.1.1', 'block', strftime('%s', 'now'))");
        // Expired CTI sentinel, expired LAPI answer, and an unexpired answer.
        $this->db->exec("INSERT INTO ip_cache (ip, source, decision, expires_at) VALUES ('8.8.8.8', 'crowdsec_cti', 'clean', 1), ('9.9.9.9', 'crowdsec_lapi', 'clean', 1), ('1.1.1.1', 'crowdsec_cti', 'block', strftime('%s', 'now') + 3600)");
        $result = self::helper(['operation' => 'job', 'id' => $job, 'config' => $config]);
        $this->assertTrue($result['success']);
        $this->assertSame(['1.1.1.1'], array_column($this->rows('history'), 'ip'));
        $this->assertSame($expectedLocal, array_column($this->rows('local'), 'ip'));
        $this->assertSame(['1.1.1.1'], array_column($this->rows('ip_cache'), 'ip'));
        $this->assertSame('block', $this->rows('ip_cache')[0]['decision']);
        $pageBefore = self::http('/', '8.8.8.8');
        $this->assertSame($localTtl === 0 ? 403 : 200, $pageBefore['status'], json_encode([$result, $pageBefore['diagnostics']]));
        $this->assertSame(403, self::http('/', '9.9.9.9')['status']);
        $this->assertSame(403, self::http('/', '1.1.1.1')['status']);
        $page = self::http('/', '4.4.4.4');
        $this->assertSame(200, $page['status'], $page['diagnostics'] ?? 'No fixture diagnostics');
        $this->assertStringContainsString('text/html', (string)$page['type']);
        $this->assertStringContainsString('Synthetic botWarden migration test site.', $page['body']);
        $before = [$this->rows('local'), $this->rows('history'), $this->rows('ip_cache')];
        $this->assertTrue(self::helper(['operation' => 'job', 'id' => $job, 'config' => $config])['success']);
        $this->assertSame($before, [$this->rows('local'), $this->rows('history'), $this->rows('ip_cache')], 'Repeated maintenance must be idempotent');
        $this->assertSame('ok', $this->db->querySingle('PRAGMA integrity_check'));
        $this->assertSame([], $this->requests());
    }

    public function testCliScriptsRejectWebRequestsAndRetainCliPaths(): void
    {
        $this->db->exec("INSERT INTO local (ip, is_manual, reason) VALUES ('8.8.8.8', 1, 'Synthetic CLI guard test')");
        $before = [$this->rows('local'), $this->rows('history'), hash_file('sha256', self::$configPath)];
        foreach (['unblock', 'migrate'] as $name) {
            $response = self::http('/__botwarden-fixture/cli/' . $name);
            $this->assertSame(403, $response['status'], $name);
            $this->assertStringContainsString('text/plain', (string)$response['type']);
            $this->assertContains(trim($response['body']), ['', '#!/usr/bin/env php']);
        }
        $this->assertSame($before, [$this->rows('local'), $this->rows('history'), hash_file('sha256', self::$configPath)]);

        foreach (['unblock' => ['--list'], 'migrate' => []] as $name => $arguments) {
            $script = self::$host . '/user/plugins/botwarden/cli/' . $name . '.php';
            $process = proc_open(array_merge([PHP_BINARY, $script], $arguments),
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, self::$host);
            if (!is_resource($process)) throw new \RuntimeException('Cannot run CLI guard regression');
            fclose($pipes[0]);
            $out = stream_get_contents($pipes[1]); fclose($pipes[1]);
            $err = stream_get_contents($pipes[2]); fclose($pipes[2]);
            $this->assertSame(0, proc_close($process), $err);
            $this->assertSame('', $err);
            if ($name === 'unblock') $this->assertStringContainsString('8.8.8.8', $out);
            else {
                $preview = json_decode($out, true, 512, JSON_THROW_ON_ERROR);
                $this->assertFalse($preview['can_migrate']);
                $this->assertTrue($preview['migration_complete']);
            }
        }
        $this->assertSame($before, [$this->rows('local'), $this->rows('history'), hash_file('sha256', self::$configPath)]);
    }

    public function testWalReadersSeeCompleteListDuringBulkReplacement(): void
    {
        $this->db->exec("INSERT INTO abuseipdb_bulk (ip) VALUES ('8.8.8.8')");
        $this->configure(['sources' => ['abuseipdb_bulk' => true]]);
        $process = proc_open([PHP_BINARY, __DIR__ . '/fixtures/ServiceFixture.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) throw new \RuntimeException('Cannot run concurrent bulk fixture');
        fwrite($pipes[0], '{"operation":"bulk-load"}'); fclose($pipes[0]);
        try {
            $deadline = microtime(true) + 5;
            while (!is_file(self::$work . '/bulk-ready') && microtime(true) < $deadline) usleep(10000);
            $this->assertFileExists(self::$work . '/bulk-ready');
            $started = microtime(true);
            $response = self::http('/');
            $duration = microtime(true) - $started;
            self::$observations['wal_reader_ms'] = round($duration * 1000, 2);
            $this->assertSame(403, $response['status'], 'Readers retain the old complete list while staging is filled');
            $this->assertLessThan(5, $duration, 'A reader should not wait for SQLite busy_timeout');
            $this->assertSame(1, (int)$this->db->querySingle('SELECT COUNT(*) FROM abuseipdb_bulk'));
        } finally {
            $output = stream_get_contents($pipes[1]); fclose($pipes[1]);
            $error = stream_get_contents($pipes[2]); fclose($pipes[2]);
            $code = proc_close($process);
        }
        $this->assertSame(0, $code, $error);
        $this->assertSame('', $error);
        $this->assertSame(2000, json_decode($output, true)['count']);
        $this->assertSame(2000, (int)$this->db->querySingle('SELECT COUNT(*) FROM abuseipdb_bulk'));
        $this->assertSame(200, self::http('/')['status']);
        $this->assertSame('wal', $this->db->querySingle('PRAGMA journal_mode'));
        $this->assertSame('ok', $this->db->querySingle('PRAGMA integrity_check'));
    }

    public function testScheduledRobotsPublicationPreservesOwnerRulesAndDoesNotRepeatBackups(): void
    {
        $prefix = "# owner policy — preserve bytes\r\nUser-agent: GoodBot\r\nAllow: /\r\n\r\n# KNOWN HOSTS START\r\nUser-agent: ExistingBot\r\nDisallow: /private\r\n# KNOWN HOSTS END\r\n\r\n";
        $suffix = "\r\n\r\nSitemap: https://example.invalid/sitemap.xml\r\n\r\n";
        $original = $prefix . "# botWarden:START\r\nUser-agent: OldFixtureBot\r\nDisallow: /\r\n# botWarden:END" . $suffix;
        $path = $this->writeRobotsFixture($original);
        $result = $this->job('botwarden-dark-visitors', 'valid', ['robots_txt' => ['enabled' => true]]);
        $this->assertTrue($result['success']);
        $this->assertSame('success', $result['state']);
        $updated = file_get_contents($path);
        $this->assertStringStartsWith($prefix, $updated);
        $this->assertStringEndsWith($suffix, $updated);
        $this->assertSame(1, substr_count($updated, '# botWarden:START'));
        $this->assertStringNotContainsString('OldFixtureBot', $updated);
        $this->assertStringContainsString("User-agent: FixtureBot\nDisallow: /", $updated);
        clearstatcache();
        $this->assertSame(0640, fileperms($path) & 0777);
        $files = glob(self::$data . '/backups/robots-*.txt');
        $this->assertCount(1, $files);
        $this->assertSame($original, file_get_contents($files[0]));
        $this->assertSame(0600, fileperms($files[0]) & 0777);
        $response = self::http('/robots.txt');
        $this->assertSame(200, $response['status']);
        $this->assertStringContainsString('text/plain', $response['type']);
        $this->assertSame($updated, $response['body']);
        touch($path, time() - 120);
        clearstatcache();
        $mtime = filemtime($path);
        $result = $this->job('botwarden-dark-visitors', 'valid', ['robots_txt' => ['enabled' => true]]);
        $this->assertTrue($result['success']);
        clearstatcache();
        $this->assertSame($mtime, filemtime($path));
        $this->assertSame($files, glob(self::$data . '/backups/robots-*.txt'));
        $this->assertSame(hash('sha256', $updated), hash_file('sha256', $path));
        $this->assertSame($updated, self::http('/robots.txt')['body']);
    }

    public function testRobotsPublicationIsOptInAndSeparateFromEnforcement(): void
    {
        $original = "User-agent: GoodBot\nAllow: /\n";
        $path = $this->writeRobotsFixture($original);
        $result = $this->job('botwarden-dark-visitors');
        $this->assertTrue($result['success']);
        $this->assertSame($original, file_get_contents($path));
        $this->assertDirectoryDoesNotExist(self::$data . '/backups');
        $result = $this->job('botwarden-dark-visitors', 'valid', ['robots_txt' => ['enabled' => true], 'sources' => ['dark_visitors' => false]]);
        $this->assertTrue($result['success']);
        $this->assertStringContainsString("User-agent: FixtureBot\nDisallow: /", self::http('/robots.txt')['body']);
        $this->assertSame(200, self::http('/', '8.8.8.8', 'FixtureBot/1.0')['status']);
        $this->configure(['sources' => ['dark_visitors' => true]]);
        $this->assertSame(403, self::http('/', '8.8.8.8', 'FixtureBot/1.0')['status']);
    }

    public static function damagedRobotsPolicies(): iterable
    {
        yield 'missing end' => ["# owner policy\n# botWarden:START\nUser-agent: ExistingBot\n"];
        yield 'missing start' => ["# owner policy\nUser-agent: ExistingBot\n# botWarden:END\n"];
        yield 'duplicate' => ["# botWarden:START\n# botWarden:END\n# botWarden:START\n# botWarden:END\n"];
        yield 'embedded' => ["# botWarden:START\n# owner policy # botWarden:END damaged\n"];
    }

    #[DataProvider('damagedRobotsPolicies')]
    public function testDamagedRobotsPolicyProducesVisibleSchedulerFailure(string $original): void
    {
        $path = $this->writeRobotsFixture($original);
        $result = $this->job('botwarden-dark-visitors', 'valid', ['robots_txt' => ['enabled' => true]]);
        $this->assertFalse($result['success']);
        $this->assertSame('failure', $result['state']);
        $this->assertStringContainsString('markers', $result['output']);
        $this->assertSame($original, file_get_contents($path));
        $this->assertDirectoryDoesNotExist(self::$data . '/backups');
        $this->assertSame([], glob(self::$host . '/.botwarden-*'));
        $this->assertSame($original, self::http('/robots.txt')['body']);
    }
}
