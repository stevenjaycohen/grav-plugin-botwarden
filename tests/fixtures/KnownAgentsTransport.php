<?php
declare(strict_types=1);

// Router for the disposable localhost Grav server, never an installed plugin.
namespace Grav\Plugin\Botwarden {

function curl_init(?string $url = null): \CurlHandle|false
{
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
    if (PHP_SAPI !== 'cli-server' || realpath(getcwd()) !== $host) {
        throw new \RuntimeException('Only the repository-local disposable Grav server is allowed');
    }
    header('X-Botwarden-Fixture-Transport: loopback');
    return require $host . '/system/router.php';
}
