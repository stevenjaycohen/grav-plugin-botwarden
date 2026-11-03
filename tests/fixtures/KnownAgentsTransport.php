<?php
declare(strict_types=1);

// Router for the disposable localhost Grav server, never an installed plugin.
namespace Grav\Plugin\Botwarden {

function curl_init(?string $url = null): \CurlHandle|false
{
    if ((parse_url((string)$url, PHP_URL_HOST) === 'api.knownagents.com'
        && parse_url((string)$url, PHP_URL_PATH) === '/robots-txts')
        || parse_url((string)$url, PHP_URL_HOST) === 'api.abuseipdb.com') {
        $service = parse_url((string)$url, PHP_URL_HOST) === 'api.knownagents.com' ? 'agents' : 'bulk';
        $scenario = $_SERVER['HTTP_X_BOTWARDEN_FEED_SCENARIO'] ?? 'valid';
        if (!in_array($scenario, ['valid', 'unauthorized'], true)) throw new \RuntimeException('Unexpected feed test scenario');
        return \curl_init('http://127.0.0.1:8778/mock/' . $service . '/' . $scenario);
    }
    if (parse_url((string)$url, PHP_URL_HOST) === 'cti.api.crowdsec.net'
        && preg_match('~^/v2/smoke/[0-9.]+$~', (string)parse_url((string)$url, PHP_URL_PATH))) {
        $scenario = $_SERVER['HTTP_X_BOTWARDEN_CTI_SCENARIO'] ?? 'block';
        if (!in_array($scenario, ['block', 'clean', 'rate_limit'], true)) {
            throw new \RuntimeException('Unexpected CTI test scenario');
        }
        return \curl_init('http://127.0.0.1:8778/mock/cti/' . $scenario);
    }
    if (parse_url((string)$url, PHP_URL_HOST) === 'api.knownagents.com'
        && parse_url((string)$url, PHP_URL_PATH) === '/visits') {
        $scenario = $_SERVER['HTTP_X_BOTWARDEN_VISIT_SCENARIO'] ?? 'valid';
        if (!in_array($scenario, ['valid', 'unauthorized', 'server_error', 'malformed'], true)) {
            throw new \RuntimeException('Unexpected Known Agents test scenario');
        }
        return \curl_init('http://127.0.0.1:8778/mock/visits/' . $scenario);
    }
    if (parse_url((string)$url, PHP_URL_HOST) !== '127.0.0.1') {
        throw new \RuntimeException('Only loopback providers are allowed in the disposable web fixture');
    }
    return \curl_init($url);
}
}

namespace {
    $host = dirname(__DIR__, 2) . '/vendor/.botwarden-migration/grav';
    $mount = getenv('GRAV_BASEDIR') ?: '';
    if (PHP_SAPI !== 'cli-server'
        || !in_array($mount, ['', 'grav'], true)
        || realpath(getcwd()) !== ($mount === 'grav' ? dirname($host) : $host)) {
        throw new \RuntimeException('Only the repository-local disposable Grav server is allowed');
    }
    // Exercise the scripts' own guards without relying on Grav's PHP-file rule.
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    if ($mount === 'grav') {
        if ($path !== '/grav' && !str_starts_with($path, '/grav/')) {
            http_response_code(404);
            return true;
        }
        $path = substr($path, strlen('/grav')) ?: '/';
    }
    if (preg_match('~^/__botwarden-fixture/cli/(unblock|migrate)$~', $path, $match)) {
        $script = $host . '/user/plugins/botwarden/cli/' . $match[1] . '.php';
        $GLOBALS['argv'] = $match[1] === 'unblock' ? [$script, '--list'] : [$script];
        $GLOBALS['argc'] = count($GLOBALS['argv']);
        header('Content-Type: text/plain; charset=UTF-8');
        // An included CLI shebang is output outside PHP; buffer it so the
        // guard can set its status before any response bytes are emitted.
        ob_start();
        require $script;
        return true;
    }
    // Simulate a public proxy peer only in this loopback-only test router.
    if (isset($_SERVER['HTTP_X_BOTWARDEN_FIXTURE_PEER'])) {
        if (($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1'
            || !in_array($_SERVER['HTTP_X_BOTWARDEN_FIXTURE_PEER'], ['1.1.1.1', '173.245.48.1', '2606:4700:4700::1111'], true)) {
            throw new \RuntimeException('Unexpected disposable proxy fixture peer');
        }
        $_SERVER['REMOTE_ADDR'] = $_SERVER['HTTP_X_BOTWARDEN_FIXTURE_PEER'];
    }
    header_register_callback(static function (): void {
        if (!class_exists(\Grav\Common\Grav::class, false)) return;
        $config = \Grav\Common\Grav::instance()['config']->get('plugins.botwarden', []);
        header('X-Botwarden-Fixture-State: ' . json_encode([
            'sources' => $config['sources'] ?? [], 'filtering' => $config['enable_filtering'] ?? false,
            'opcache_enabled' => function_exists('opcache_get_status') && (opcache_get_status(false)['opcache_enabled'] ?? false),
            'client' => \Grav\Plugin\Botwarden\IpDetector::getClientIp(\Grav\Plugin\Botwarden\IpDetector::getTrustedProxies($config)),
        ]));
    });
    header('X-Botwarden-Fixture-Transport: loopback');
    return require $host . '/system/router.php';
}
