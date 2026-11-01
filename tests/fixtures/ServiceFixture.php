<?php
declare(strict_types=1);

namespace Grav\Plugin\Botwarden {
    // Test-only URL redirection. The production cURL transport still performs
    // the HTTP request; fixed provider URLs can reach only the loopback stub.
    function curl_init(?string $url = null): \CurlHandle|false
    {
        $host = parse_url((string)$url, PHP_URL_HOST);
        $service = match ($host) {
            'api.abuseipdb.com' => str_contains((string)$url, '/report') ? 'report' : 'bulk',
            'cti.api.crowdsec.net' => 'cti',
            'api.knownagents.com' => parse_url((string)$url, PHP_URL_PATH) === '/visits' ? 'visits' : 'agents',
            '127.0.0.1' => 'lapi',
            default => throw new \RuntimeException('Unexpected provider in disposable test'),
        };
        $scenario = $GLOBALS['botwarden_service_scenario'] ?? 'valid';
        if ($scenario === 'network') return \curl_init('http://127.0.0.1:1/');
        return \curl_init('http://127.0.0.1:8778/mock/' . $service . '/' . rawurlencode($scenario));
    }
}

namespace {
    $repo = dirname(__DIR__, 2);
    $host = $repo . '/vendor/.botwarden-migration/grav';
    $work = $repo . '/vendor/.botwarden-migration/services';
    if (is_link($work) || !is_dir($work) || realpath($host) !== $host || !is_file($host . '/system/router.php')) {
        throw new RuntimeException('Disposable repository-local service fixture is required');
    }

    if (PHP_SAPI === 'cli-server') {
        header('Content-Type: application/json');
        $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        if (!preg_match('~^/mock/(bulk|agents|visits|cti|lapi|report)/(valid|block|clean|empty|malformed|unauthorized|server_error|rate_limit|not_found)(?:/v1/decisions)?$~', $path, $match)) {
            http_response_code(404);
            echo '{}';
            return;
        }
        [$all, $service, $scenario] = $match;
        $body = file_get_contents('php://input');
        file_put_contents($work . '/requests.jsonl', json_encode([
            'service' => $service, 'scenario' => $scenario,
            'method' => $_SERVER['REQUEST_METHOD'], 'body' => $body,
            'content_type' => $_SERVER['CONTENT_TYPE'] ?? '',
            'project_token_matches' => ($_SERVER['HTTP_AUTHORIZATION'] ?? '') === 'Bearer synthetic-fixture-key',
        ], JSON_THROW_ON_ERROR) . "\n", FILE_APPEND | LOCK_EX);
        if (in_array($scenario, ['unauthorized', 'server_error', 'rate_limit', 'not_found'], true)) {
            http_response_code(['unauthorized' => 401, 'server_error' => 503, 'rate_limit' => 429, 'not_found' => 404][$scenario]);
            echo '{"error":"synthetic upstream failure"}';
            return;
        }
        if ($scenario === 'empty') { echo ''; return; }
        if ($scenario === 'malformed') { echo '<html>synthetic upstream error</html>'; return; }
        if ($service === 'visits') {
            $visits = json_decode($body, true);
            if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_SERVER['CONTENT_TYPE'] ?? '') !== 'application/json'
                || ($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'Bearer synthetic-fixture-key'
                || !is_array($visits) || !array_is_list($visits) || count($visits) !== 1
                || !isset($visits[0]['request_path'], $visits[0]['request_method'], $visits[0]['request_headers'])) {
                http_response_code(400);
                return;
            }
            http_response_code(204);
            return;
        }
        echo match ($service) {
            'bulk' => "198.51.100.10\n198.51.100.11\n198.51.100.10\n2001:db8::1\n",
            'agents' => "User-agent: FixtureBot\nDisallow: /\nUser-agent: FixtureCrawler\nDisallow: /\n",
            'cti' => json_encode(['scores' => ['overall' => ['total' => $scenario === 'clean' ? 1 : 4]], 'behaviors' => [['label' => 'synthetic signal']]]),
            'lapi' => $scenario === 'clean' ? '[]' : '[{"type":"ban","scenario":"synthetic ban"}]',
            'report' => '{"data":{"abuseConfidenceScore":0}}',
        };
        return;
    }

    if (PHP_SAPI !== 'cli') exit(1);
    $input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
    $GLOBALS['botwarden_service_scenario'] = $input['scenario'] ?? 'valid';
    chdir($host);
    define('GRAV_CLI', true);
    define('GRAV_REQUEST_TIME', microtime(true));
    $loader = require 'vendor/autoload.php';
    $grav = Grav\Common\Grav::instance(['loader' => $loader]);
    $grav->setup('migration-test')->initializeCli();
    if ($input['operation'] === 'clear') {
        $output = Grav\Common\Cache::clearCache('cache-only');
        foreach ($output as $line) if (str_contains($line, 'ERROR:')) throw new RuntimeException($line);
        echo json_encode(['cleared' => true]);
        exit;
    }
    $defaults = Symfony\Component\Yaml\Yaml::parseFile($repo . '/botwarden.yaml');
    $defaults['sources'] = array_fill_keys(array_keys($defaults['sources']), false);
    $config = array_replace_recursive($defaults, $input['config'] ?? []);
    $grav['config']->set('plugins.botwarden', $config);
    $grav['plugins']->init();
    $grav->fireEvent('onPluginsInitialized');
    $operation = $input['operation'];
    if ($operation === 'jobs' || $operation === 'job') {
        $scheduler = $grav['scheduler'];
        $scheduler->initializeJobs();
        if ($operation === 'jobs') {
            $result = [];
            foreach ($scheduler->getAllJobs() as $job) $result[$job->getId()] = $job->getAt();
        } else {
            if (!str_starts_with($input['id'], 'botwarden-')) throw new RuntimeException('Only botWarden jobs may run');
            $started = microtime(true);
            $job = $scheduler->setRunTrigger('manual')->runJob($input['id']);
            if (!$job) throw new RuntimeException('Expected botWarden job was not registered');
            $result = ['success' => $job->isSuccessful(), 'output' => $job->getOutput(),
                'state' => $scheduler->getJobStates()->content()[$input['id']]['state'],
                'duration_ms' => round((microtime(true) - $started) * 1000, 2)];
        }
    } elseif ($operation === 'init') {
        $db = Grav\Plugin\Botwarden\BotwardenDatabase::getInstance($host . '/user/data/botwarden');
        $result = ['integrity' => $db->querySingle('PRAGMA integrity_check')];
    } elseif ($operation === 'bulk-load') {
        $db = Grav\Plugin\Botwarden\BotwardenDatabase::getInstance($host . '/user/data/botwarden');
        $ips = (static function () use ($work) {
            for ($i = 1; $i <= 2000; $i++) {
                if ($i === 501) {
                    file_put_contents($work . '/bulk-ready', 'staging');
                    usleep(500000);
                }
                yield '198.18.' . intdiv($i, 256) . '.' . ($i % 256);
            }
        })();
        $result = ['count' => Grav\Plugin\Botwarden\BotwardenDatabase::replaceBulkList($db, $ips)];
    } elseif ($operation === 'visit') {
        $client = new Grav\Plugin\Botwarden\DarkVisitorsClient('synthetic-fixture-key');
        $client->reportVisit(Grav\Plugin\Botwarden\DarkVisitorsClient::visit([
            'REQUEST_URI' => '/fixture-visit?token=private', 'REQUEST_METHOD' => 'GET',
            'HTTP_USER_AGENT' => 'FixtureAgent',
        ]));
        $result = ['accepted' => true];
    } elseif ($operation === 'cti' || $operation === 'lapi') {
        $db = Grav\Plugin\Botwarden\BotwardenDatabase::getInstance($host . '/user/data/botwarden');
        $client = new Grav\Plugin\Botwarden\CrowdSecClient($config['crowdsec'], $db);
        $result = $client->checkIp('198.51.100.20');
    } else {
        throw new RuntimeException('Unknown service fixture operation');
    }
    echo json_encode($result, JSON_THROW_ON_ERROR);
}
