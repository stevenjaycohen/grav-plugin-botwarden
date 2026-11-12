<?php
declare(strict_types=1);
namespace Grav\Plugin\Botwarden;

use Grav\Plugin\Api\Controllers\AbstractApiController;
use Grav\Plugin\Api\Response\ApiResponse;
use Grav\Plugin\Api\Exceptions\ValidationException;
use Grav\Common\Grav;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** API handlers for Admin2. All routes require superuser permission. */
final class BotwardenApiController extends AbstractApiController
{
    private function userDir(): string
    {
        if (defined('GRAV_ROOT')) return GRAV_ROOT . '/user';
        $path = Grav::instance()['locator']->findResource('user://', true);
        if (!is_string($path) || $path === '') throw new \RuntimeException('Could not find Grav user directory');
        return $path;
    }

    private function db(): \SQLite3
    {
        return BotwardenDatabase::getInstance($this->userDir() . '/data/botwarden');
    }

    /** Non-creating read: dashboard must not block an eligible import. */
    private function existingDb(): ?\SQLite3
    {
        $dir = $this->userDir() . '/data/botwarden';
        if (!is_file($dir . '/botwarden.sqlite')) return null;
        return BotwardenDatabase::getInstance($dir);
    }

    public function status(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.super');
        $config = (array)Grav::instance()['config']->get('plugins.botwarden', []);
        $trustedProxies = IpDetector::getTrustedProxies($config);
        $proxyWarning = null;
        if ($trustedProxies === []) {
            $proxyWarning = 'No trusted proxies are configured. If this site uses a CDN or reverse proxy, configure Trusted Proxies or select the Cloudflare preset in Plugin settings so botWarden can identify visitors correctly.';
        } elseif (IpDetector::hasUntrustedForwardingHeaders($trustedProxies)) {
            $proxyWarning = 'Forwarding headers on this request came from a peer outside Trusted Proxies. Check Trusted Proxies and the proxy preset in Plugin settings; botWarden is using the direct connection address.';
        }
        $db = $this->existingDb();
        if ($db === null) {
            return ApiResponse::create(['local_count' => 0, 'history_count' => 0,
                'abuseipdb_bulk_count' => 0, 'dark_visitors_count' => 0, 'proxy_warning' => $proxyWarning] + $this->integrationStatus($config, null));
        }
        return ApiResponse::create([
            'local_count' => BotwardenDatabase::getLocalCount($db),
            'history_count' => BotwardenDatabase::getHistoryCount($db),
            'abuseipdb_bulk_count' => BotwardenDatabase::getAbuseipdbBulkCount($db),
            'dark_visitors_count' => BotwardenDatabase::getDarkVisitorsCount($db),
            'proxy_warning' => $proxyWarning,
        ] + $this->integrationStatus($config, $db));
    }

    public function legacy(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.super');
        return ApiResponse::create(LegacyMigration::preview($this->userDir()));
    }

    public function migrateLegacy(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.super');
        $body = $this->getRequestBody($request);
        if (($body['confirm'] ?? null) !== true) {
            throw new ValidationException('Explicit migration confirmation is required', [
                ['field' => 'confirm', 'message' => 'Must be true'],
            ]);
        }
        return ApiResponse::create(LegacyMigration::migrate($this->userDir(), true));
    }

    public function blocks(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.super');
        $query = $request->getQueryParams();
        $limit = max(1, min(200, (int)($query['limit'] ?? 50)));
        $offset = max(0, (int)($query['offset'] ?? 0));
        $db = $this->existingDb();
        if ($db === null) {
            return ApiResponse::create(['entries' => [], 'total' => 0, 'limit' => $limit, 'offset' => 0]);
        }
        $search = trim((string)($query['search'] ?? ''));
        $total = $search === '' ? BotwardenDatabase::getLocalCount($db) : 0;
        if ($search !== '') {
            $row = BotwardenDatabase::searchLocal($db, $search);
            $entries = $row ? [$row] : [];
            $total = count($entries);
            $offset = 0;
        } else {
            $entries = BotwardenDatabase::getLocalPaginated($db, $limit, $offset);
        }
        $ttl = (int)Grav::instance()['config']->get('plugins.botwarden.local_ttl', 86400);
        foreach ($entries as &$entry) {
            $entry['is_active'] = (bool)$entry['is_manual'] || $ttl <= 0 || (int)$entry['added_at'] > time() - $ttl;
        }
        unset($entry);
        return ApiResponse::create(['entries' => $entries, 'total' => $total, 'limit' => $limit, 'offset' => $offset]);
    }

    public function history(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.super');
        $query = $request->getQueryParams();
        $limit = max(1, min(200, (int)($query['limit'] ?? 50)));
        $offset = max(0, (int)($query['offset'] ?? 0));
        $db = $this->existingDb();
        if ($db === null) {
            return ApiResponse::create(['entries' => [], 'total' => 0, 'limit' => $limit, 'offset' => $offset]);
        }
        $total = BotwardenDatabase::getHistoryCount($db);
        return ApiResponse::create([
            'entries' => BotwardenDatabase::getHistory($db, $limit, $offset),
            'total' => $total,
            'limit' => $limit, 'offset' => $offset,
        ]);
    }

    public function addBlock(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.super');
        $body = $this->getRequestBody($request);
        $ip = trim((string)($body['ip'] ?? ''));
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            throw new ValidationException('Invalid IP', [['field' => 'ip', 'message' => 'Valid IPv4 or IPv6 address required']]);
        }
        if (LegacyGuard::mustDefer($this->userDir())) {
            throw new \RuntimeException('Migrate GRVHulk before adding botWarden blocks');
        }
        BotwardenDatabase::addIpToLocal($this->db(), $ip, substr(trim((string)($body['reason'] ?? 'Manual')), 0, 200), true);
        return ApiResponse::create(['success' => true, 'ip' => $ip]);
    }

    public function removeBlock(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.super');
        $body = $this->getRequestBody($request);
        $ip = trim((string)($body['ip'] ?? ''));
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            throw new ValidationException('Invalid IP', [['field' => 'ip', 'message' => 'Valid IPv4 or IPv6 address required']]);
        }
        if (LegacyGuard::mustDefer($this->userDir())) {
            throw new \RuntimeException('Migrate GRVHulk before editing botWarden blocks');
        }
        BotwardenDatabase::removeIpFromLocal($this->db(), $ip);
        return ApiResponse::create(['success' => true, 'ip' => $ip]);
    }
    private function integrationStatus(array $config, ?\SQLite3 $db): array
    {
        $integrations = [];
        foreach (['abuseipdb_bulk', 'dark_visitors'] as $source) {
            $section = $source === 'abuseipdb_bulk' ? 'abuseipdb' : 'dark_visitors';
            $enabled = !empty($config['sources'][$source]) || ($source === 'dark_visitors' && !empty($config['robots_txt']['enabled']));
            $last = $db ? (int)BotwardenDatabase::getMeta($db, $source . '_updated') : 0;
            $error = $db ? BotwardenDatabase::getMeta($db, $source . '_error') : null;
            $ttl = $source === 'dark_visitors' ? max(3600, (int)($config['dark_visitors']['cache_ttl'] ?? 86400)) : 86400;
            $scheduled = $source !== 'abuseipdb_bulk' || !empty($config['auto_cache']);
            $state = !$enabled ? 'disabled' : (empty($config[$section]['api_key']) ? 'missing credentials'
                : (!$scheduled ? 'automatic refresh disabled' : ($error ? 'refresh failed'
                    : (!$last ? 'never refreshed' : ($last <= time() - $ttl ? 'stale' : 'fresh')))));
            $integrations[$source] = ['state' => $state, 'last_success' => $last ?: null,
                'error' => $error ?: null, 'can_refresh' => $enabled && $scheduled && !empty($config[$section]['api_key'])];
        }
        $integrations['crowdsec'] = ['state' => empty($config['sources']['crowdsec']) ? 'disabled'
            : (empty($config['crowdsec'][($config['crowdsec']['mode'] ?? 'cti') . '_api_key']) ? 'missing credentials' : 'configured; decisions checked on request')];
        $diagnostics = [];
        foreach (['reports_sent', 'reports_failed', 'reports_dropped', 'reports_expired', 'reports_not_queued', 'reports_retry_after',
            'visits_accepted', 'visits_failed', 'visits_last_attempt', 'visits_queued', 'visits_dropped', 'visits_expired',
            'visits_retry_after', 'visits_failure_streak', 'visits_last_success'] as $key) {
            $diagnostics[$key] = $db ? (int)BotwardenDatabase::getMeta($db, $key) : 0;
        }
        $visits = $db ? new VisitReportQueue($db) : null;
        $diagnostics['visits_pending'] = $visits ? $visits->pending() : 0;
        $visitState = empty($config['dark_visitors']['report_visits']) ? 'disabled'
            : (empty($config['dark_visitors']['api_key']) ? 'missing credentials'
                : ($diagnostics['visits_failure_streak'] ? 'degraded; last delivery attempt failed'
                    : ($diagnostics['visits_pending'] ? 'queued for scheduled delivery'
                        : ($diagnostics['visits_last_success'] ? 'healthy' : 'waiting for visits'))));
        $errors = [];
        foreach (['whitelist', 'trusted_proxies'] as $field) {
            $entries = $config[$field] ?? [];
            if (is_string($entries)) $entries = preg_split('/\r\n|\r|\n/', $entries) ?: [];
            foreach ((array)$entries as $entry) {
                $entry = trim((string)$entry);
                if ($entry === '' || str_starts_with($entry, '#')) continue;
                $network = explode('/', $entry, 2)[0];
                if (!filter_var($network, FILTER_VALIDATE_IP) || !IpDetector::isWhitelisted($network, [$entry])) {
                    $errors[] = 'Invalid IP/CIDR in ' . $field . '; correct this entry in Plugin settings.';
                    break;
                }
            }
        }
        $filters = $config['filters'] ?? '';
        foreach (is_array($filters) ? $filters : (preg_split('/\r\n|\r|\n/', $filters) ?: []) as $filter) {
            $filter = trim((string)$filter);
            if ($filter !== '' && !str_starts_with($filter, '#') && @preg_match('~' . str_replace('~', '\\~', $filter) . '~i', '') === false) {
                $errors[] = 'Invalid filter expression; correct the pattern in Plugin settings.';
                break;
            }
        }
        return ['integrations' => $integrations, 'delivery' => $diagnostics, 'configuration_errors' => $errors,
            'visit_reporting' => $visitState,
            'visit_diagnostics' => $visits ? $visits->diagnostics() : [],
            'visit_error' => $db ? BotwardenDatabase::getMeta($db, 'visits_last_error') : null];
    }

    public function refreshIntegration(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.super');
        $body = $this->getRequestBody($request);
        $jobs = ['abuseipdb_bulk' => 'botwarden-abuseipdb-cache', 'dark_visitors' => 'botwarden-dark-visitors'];
        $source = $body['source'] ?? '';
        if (!is_string($source) || !isset($jobs[$source])) throw new ValidationException('Choose AbuseIPDB bulk or Known Agents');
        $config = (array)Grav::instance()['config']->get('plugins.botwarden', []);
        $status = $this->integrationStatus($config, $this->existingDb());
        if (!isset($jobs[$source]) || empty($status['integrations'][$source]['can_refresh']) || LegacyGuard::mustDefer($this->userDir())) {
            throw new ValidationException('Enable the source, credentials and automatic refresh; complete any pending migration first');
        }
        $scheduler = Grav::instance()['scheduler'];
        $scheduler->initializeJobs();
        $job = $scheduler->setRunTrigger('manual')->runJob($jobs[$source]);
        return ApiResponse::create(['success' => $job && $job->isSuccessful(),
            'message' => $job && $job->isSuccessful() ? 'Refresh completed (fresh Known Agents data may be reused).' : 'Refresh failed; inspect scheduler output and credentials.']);
    }

    public function withdrawRobots(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.super');
        $body = $this->getRequestBody($request);
        if (Grav::instance()['config']->get('plugins.botwarden.robots_txt.enabled', false)) {
            throw new ValidationException('Disable robots.txt publication in Plugin settings before withdrawal');
        }
        $path = dirname($this->userDir()) . '/robots.txt';
        if (($body['confirm'] ?? false) !== true) return ApiResponse::create(RobotsTxtManager::withdrawalPreview($path));
        $hash = $body['sha256'] ?? '';
        if (!is_string($hash) || !preg_match('/\A[a-f0-9]{64}\z/', $hash)) throw new ValidationException('Preview the current policy before confirming withdrawal');
        try {
            return ApiResponse::create(RobotsTxtManager::withdraw($path, $this->userDir() . '/data/botwarden/backups', $hash));
        } catch (\RuntimeException $error) {
            throw new ValidationException($error->getMessage());
        }
    }

}
