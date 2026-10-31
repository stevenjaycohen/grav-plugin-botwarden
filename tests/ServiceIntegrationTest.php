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
    private static string $work;
    private static string $data;
    private static string $configPath;
    private static ?string $originalConfig;
    private static ?int $originalMode;
    private static ?int $originalMtime;
    private static bool $prepared = false;
    private static bool $ownsRobotsFixture = false;
    private static array $observations = [];
    private SQLite3 $db;

    public static function setUpBeforeClass(): void
    {
        if (getenv('BOTWARDEN_TEST_URL') !== 'http://127.0.0.1:8777') {
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
        self::$originalConfig = is_file(self::$configPath) ? file_get_contents(self::$configPath) : null;
        self::$originalMode = is_file(self::$configPath) ? fileperms(self::$configPath) & 0777 : null;
        self::$originalMtime = is_file(self::$configPath) ? filemtime(self::$configPath) : null;
        mkdir(self::$work, 0700);
        mkdir(self::$data, 0700);
        file_put_contents(self::$data . '/migration-complete.json', '{"purpose":"service-integration-fixture"}');
        self::$prepared = true;
        self::helper(['operation' => 'init']);
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
                if (!preg_match('/^robots-\d{8}-\d{6}-[a-f0-9]{10}\.txt$/', $name) || is_link($file) || !is_file($file)) {
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

    private static function http(string $path, string $ip = '8.8.8.8', string $agent = 'FixtureBrowser'): array
    {
        $handle = curl_init('http://127.0.0.1:8777' . $path);
        curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => ['X-Forwarded-For: ' . $ip], CURLOPT_USERAGENT => $agent]);
        $body = curl_exec($handle);
        if ($body === false) throw new \RuntimeException('Local Grav request failed: ' . curl_error($handle));
        $result = ['status' => curl_getinfo($handle, CURLINFO_RESPONSE_CODE), 'type' => curl_getinfo($handle, CURLINFO_CONTENT_TYPE), 'body' => $body];
        curl_close($handle);
        return $result;
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

    public function testSuccessfulFeedJobsReplaceListsAndRecordSuccess(): void
    {
        $started = time();
        $bulk = $this->job('botwarden-abuseipdb-cache');
        $agents = $this->job('botwarden-dark-visitors');
        $this->assertTrue($bulk['success']);
        $this->assertTrue($agents['success']);
        $this->assertCount(3, $this->rows('abuseipdb_bulk'));
        $this->assertCount(2, $this->rows('dark_visitors_agents'));
        $this->assertCount(3, $this->rows('metadata'));
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
            foreach (['network', 'unauthorized', 'server_error', 'rate_limit', 'empty', 'malformed'] as $scenario) {
                yield $service . ' ' . $scenario => [$job, $scenario];
            }
        }
    }

    #[DataProvider('failedFeeds')]
    public function testFailedFeedsPreserveDataAndSchedulerReportsFailure(string $job, string $scenario): void
    {
        $this->job('botwarden-abuseipdb-cache');
        $this->job('botwarden-dark-visitors');
        $before = [$this->rows('abuseipdb_bulk'), $this->rows('dark_visitors_agents'), $this->rows('metadata')];
        $result = $this->job($job, $scenario);
        $this->assertSame($before, [$this->rows('abuseipdb_bulk'), $this->rows('dark_visitors_agents'), $this->rows('metadata')]);
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
            $result = $this->job('botwarden-flush-reports', 'rate_limit');
            $this->assertFalse($result['success'], 'Deferred failed reports must be visible as a failed scheduler run');
            $this->assertStringContainsString('0 queued', $result['output']);
            $queue = $this->rows('report_queue');
            if ($attempt < 5) $this->assertSame($attempt, $queue[0]['attempts']);
            else $this->assertSame([], $queue);
        }
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

    public function testMaintenanceJobsPruneOnlyExpiredAutomaticData(): void
    {
        $this->db->exec("INSERT INTO local (ip, is_manual, added_at) VALUES ('198.51.100.40', 0, 1), ('198.51.100.41', 1, 1)");
        $this->db->exec("INSERT INTO history (ip, action, acted_at) VALUES ('198.51.100.40', 'block', 1)");
        $this->db->exec("INSERT INTO ip_cache (ip, source, decision, expires_at) VALUES ('198.51.100.40', 'crowdsec_lapi', 'clean', 1)");
        $result = $this->job('botwarden-auto-clean');
        $this->assertTrue($result['success']);
        $this->assertSame(['198.51.100.41'], array_column($this->rows('local'), 'ip'));
        $this->assertSame([], $this->rows('history'));
        $this->assertSame([], $this->rows('ip_cache'));
        $this->assertSame('ok', $this->db->querySingle('PRAGMA integrity_check'));
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
