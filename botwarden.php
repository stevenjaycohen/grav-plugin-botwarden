<?php
namespace Grav\Plugin;

use Composer\Autoload\ClassLoader;
use Grav\Common\Grav;
use Grav\Common\Plugin;
use Grav\Common\Filesystem\Folder;
use Grav\Common\Utils;
use Grav\Framework\Psr7\Response;
use Grav\Plugin\Botwarden\AbuseIpDbClient;
use Grav\Plugin\Botwarden\CrowdSecClient;
use Grav\Plugin\Botwarden\DarkVisitorsClient;
use Grav\Plugin\Botwarden\BotwardenDatabase;
use Grav\Plugin\Botwarden\IpDetector;
use Grav\Plugin\Botwarden\LegacyGuard;
use RocketTheme\Toolbox\Event\Event;
use SQLite3;

/**
 * Class BotwardenPlugin
 *
 * Advanced IP security plugin for Grav 2.
 * Features: local blacklist, AbuseIPDB (bulk + live), CrowdSec (CTI/LAPI),
 * Known Agents AI crawler blocking, CIDR whitelist, URI filtering.
 *
 * Based on the design of grav-plugin-ip-blacklist by Ari Cooper-Davis.
 */
class BotwardenPlugin extends Plugin
{
    private static ?SQLite3 $dbInstance = null;

    public static function getSubscribedEvents(): array
    {
        return [
            'onPluginsInitialized' => ['onPluginsInitialized', 0],
            'onApiRegisterRoutes' => ['onApiRegisterRoutes', 0],
            'onApiSidebarItems' => ['onApiSidebarItems', 0],
            'onApiPluginPageInfo' => ['onApiPluginPageInfo', 0],
        ];
    }

    public function autoload(): ClassLoader
    {
        if (file_exists(__DIR__ . '/vendor/autoload.php')) {
            return require __DIR__ . '/vendor/autoload.php';
        }

        // No local vendor is required for runtime HTTP; only
        // to register our own namespace with Composer's already-loaded ClassLoader.
        $loader = new ClassLoader();
        $loader->addPsr4('Grav\\Plugin\\Botwarden\\', [__DIR__ . '/classes/']);
        $loader->register();
        return $loader;
    }

    public function onPluginsInitialized(): void
    {
        // Conditionally load vendor autoload if not already provided by Grav
        if (file_exists(__DIR__ . '/vendor/autoload.php')) {
            require_once __DIR__ . '/vendor/autoload.php';
        }

        $this->enable([
            'onRequestHandlerInit' => ['onRequestHandlerInit', 1000],
            'onSchedulerInitialized' => ['onSchedulerInitialized', 0],
        ]);


    }

    /**
     * Main request interception hook.
     * $request is the RequestHandlerEvent (or equivalent) that exposes:
     *   - getRoute()->getRoute() for the path
     *   - getRequest()->getParsedBody() for POST data
     *   - setResponse($response) to short-circuit with a custom response
     */
    public function onRequestHandlerInit($request): void
    {
        $config = $this->config();

        // The API/Admin2 endpoints authenticate independently; never block our
        // management interface or its login/authentication requests.
        try {
            $path = $request->getRoute()->getRoute();
        } catch (\Throwable $e) {
            $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        }
        if ($this->isAdmin() || preg_match('~^/(?:admin|api/v1)(?:/|$)~', $path)) {
            return;
        }

        // Detect client IP (forwarding headers honored only behind trusted proxies)
        $trustedProxies = $this->parseLineList($config['trusted_proxies'] ?? '');
        $ip = IpDetector::getClientIp($trustedProxies);

        // Skip private/reserved IPs (localhost, LAN, etc.)
        if (!IpDetector::isValidPublicIp($ip)) {
            return;
        }

        // Whitelist takes absolute priority
        $whitelist = $this->parseLineList($config['whitelist'] ?? '');
        if (!empty($whitelist) && IpDetector::isWhitelisted($ip, $whitelist)) {
            if ($config['logging'] ?? false) {
                $this->grav['log']->debug("[Warden] Whitelisted: {$ip}");
            }
            return;
        }

        // An unmigrated GRVHulk install must not acquire a second active
        // request blocker or create an empty botWarden DB before import.
        $userDirectory = $this->grav['locator']->findResource('user://', true);
        if (is_string($userDirectory)
            && LegacyGuard::mustDefer($userDirectory)) {
            return;
        }

        // Everything below touches the SQLite DB. If a check throws (e.g. the DB
        // is briefly locked by the scheduler), fail OPEN — never 500 a visitor
        // because of an internal error in the security layer.
        try {
            $db = $this->getDb();

            if ($config['enable_blacklisting'] ?? true) {
                // --- Known Agents: User-Agent check ---
                if (!empty($config['sources']['dark_visitors'])) {
                    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
                    if ($ua !== '' && BotwardenDatabase::isDarkVisitorsAgentBlocked($db, $ua)) {
                        if ($config['logging'] ?? false) {
                            $this->grav['log']->debug("[Warden] Blocked AI crawler UA: {$ua} ({$ip})");
                        }
                        $this->rejectRequest($request);
                        return;
                    }
                }

                // --- Local blacklist ---
                if (!empty($config['sources']['local'])) {
                    $localTtl = (int)($config['local_ttl'] ?? 86400);
                    if (BotwardenDatabase::isIpInLocal($db, $ip, $localTtl)) {
                        if ($config['logging'] ?? false) {
                            $this->grav['log']->debug("[Warden] Blocked by local blacklist: {$ip}");
                        }
                        $this->rejectRequest($request);
                        return;
                    }
                }

                // --- AbuseIPDB bulk blacklist ---
                if (!empty($config['sources']['abuseipdb_bulk'])) {
                    if (BotwardenDatabase::isIpInAbuseipdbBulk($db, $ip)) {
                        if ($config['logging'] ?? false) {
                            $this->grav['log']->debug("[Warden] Blocked by AbuseIPDB bulk: {$ip}");
                        }
                        $this->rejectRequest($request);
                        return;
                    }
                }

                // --- CrowdSec check ---
                if (!empty($config['sources']['crowdsec'])) {
                    $mode   = $config['crowdsec']['mode'] ?? 'cti';
                    $source = 'crowdsec_' . $mode;
                    $ttl    = $mode === 'cti'
                        ? (int)($config['crowdsec']['cti_cache_ttl'] ?? 21600)
                        : (int)($config['crowdsec']['lapi_cache_ttl'] ?? 60);
                    $hasKey = $mode === 'cti'
                        ? !empty($config['crowdsec']['cti_api_key'])
                        : !empty($config['crowdsec']['lapi_api_key']);

                    if ($hasKey) {
                        $cached = BotwardenDatabase::getCachedDecision($db, $ip, $source);
                        if ($cached === null) {
                            // Single-flight: a brief sentinel stops concurrent FPM
                            // workers from all calling the API at once and burning
                            // the CTI free-tier quota (50/day).
                            BotwardenDatabase::setCachedDecision($db, $ip, $source, 'clean', 0, 10);
                            $client = new CrowdSecClient($config['crowdsec'], $db);
                            $result = $client->checkIp($ip);
                            // Cache only authoritative results; a fail-open "clean"
                            // from a quota lock or network error must not pin a bad
                            // IP as clean for the full TTL (it keeps the 10s sentinel
                            // and is retried shortly).
                            if (!empty($result['cacheable'])) {
                                BotwardenDatabase::setCachedDecision($db, $ip, $source, $result['decision'], $result['score'], $ttl);
                            }
                            $cached = $result;
                        }
                        if (($cached['decision'] ?? 'clean') === 'block') {
                            if ($config['logging'] ?? false) {
                                $this->grav['log']->debug("[Warden] Blocked by CrowdSec {$mode} (score:{$cached['score']}): {$ip}");
                            }
                            $this->rejectRequest($request);
                            return;
                        }
                    }
                }
            }

            // --- URI filter ---
            if (!empty($config['enable_filtering'])) {
                $uri = $_SERVER['REQUEST_URI'] ?? '';
                // Cap the subject length to bound regex backtracking (ReDoS) on the
                // request hot path; real probe paths are short.
                $subject = substr($uri, 0, 2048);
                foreach ($this->parseLineList($config['filters'] ?? '') as $filter) {
                    // Escape the delimiter so patterns containing '~' don't break the regex.
                    $pattern = '~' . str_replace('~', '\~', $filter) . '~i';
                    $match   = preg_match($pattern, $subject);
                    if ($match === false) {
                        $err = preg_last_error();
                        if ($err === PREG_BACKTRACK_LIMIT_ERROR || $err === PREG_RECURSION_LIMIT_ERROR) {
                            // Runtime backtracking/recursion failure (possible ReDoS)
                            // — fail closed for this request so a crafted URI cannot
                            // bypass the filter. (Malformed patterns instead surface
                            // as PREG_NO_ERROR/INTERNAL and are skipped below.)
                            if ($config['logging'] ?? false) {
                                $this->grav['log']->error("[Warden] Regex runtime error (code {$err}) on filter '{$filter}' — failing closed: {$uri}");
                            }
                            if ($config['enable_blacklisting'] ?? true) {
                                $this->rejectRequest($request);
                            }
                            return;
                        }
                        // Invalid pattern (compile error) — skip just this filter.
                        if ($config['logging'] ?? false) {
                            $this->grav['log']->warning("[Warden] Invalid filter pattern skipped: '{$filter}'");
                        }
                        continue;
                    }
                    if ($match === 1) {
                        if ($config['logging'] ?? false) {
                            $this->grav['log']->debug("[Warden] URI matched filter '{$filter}': {$uri}");
                        }
                        BotwardenDatabase::addIpToLocal($db, $ip, 'URI filter: ' . $filter);
                        if (!empty($config['enable_reporting']) && !empty($config['abuseipdb']['api_key'])) {
                            // Queue the report (path only — the query string may carry
                            // secrets) for the scheduler to send, so we never block the
                            // request on a synchronous external API call.
                            $pathOnly = parse_url($uri, PHP_URL_PATH) ?: '/';
                            BotwardenDatabase::enqueueReport($db, $ip, $pathOnly);
                        }
                        if ($config['enable_blacklisting'] ?? true) {
                            $this->rejectRequest($request);
                        }
                        return;
                    }
                }
            }
        } catch (\Throwable $e) {
            if ($config['logging'] ?? false) {
                $this->grav['log']->error('[Warden] Security check error, allowing request (fail-open): ' . $e->getMessage());
            }
            return;
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Parse a multiline string into a trimmed array, skipping blank lines and # comments.
     * Accepts both a plain string (from textarea/editor fields) and an already-decoded array.
     */
    private function parseLineList(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_filter(array_map('trim', $value)));
        }
        $lines = explode("\n", (string)$value);
        $result = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line !== '' && $line[0] !== '#') {
                $result[] = $line;
            }
        }
        return $result;
    }

    private function rejectRequest($request): void
    {
        $texts = [
            400 => 'Bad Request',
            403 => 'Forbidden',
            418 => "I'm a teapot",
            503 => 'Service Unavailable',
        ];
        $code     = (int)($this->config()['response'] ?? 403);
        $response = new Response($code, [], $texts[$code] ?? 'Blocked');
        $request->setResponse($response);
    }

    private function getDb(): SQLite3
    {
        if (self::$dbInstance === null) {
            $dataDir         = $this->grav['locator']->findResource('user://data', true) . '/botwarden';
            self::$dbInstance = BotwardenDatabase::getInstance($dataDir);
        }
        return self::$dbInstance;
    }

    /**
     * Normalize the agent_types config into a plain list of label strings.
     *
     * Grav's `checkboxes` field with `use: keys` saves data as a `label => bool`
     * map, while hulk.yaml ships a plain list. Accept both and always return a
     * list of selected labels (the shape the Known Agents API expects).
     */
    private static function normalizeAgentTypes($types): array
    {
        $default = ['AI Assistant', 'AI Data Scraper', 'AI Search Crawler', 'Undocumented AI Agent'];
        if (!is_array($types) || $types === []) {
            return $default;
        }
        // Associative map (label => bool) from the admin checkboxes field.
        if (array_keys($types) !== range(0, count($types) - 1)) {
            $selected = array_keys(array_filter($types));
            return $selected !== [] ? $selected : $default;
        }
        // Already a plain list of labels.
        return array_values(array_filter($types, 'is_string'));
    }

    // -------------------------------------------------------------------------
    // Grav 2 API / Admin2 integration (no classic Admin hooks or Twig routes).
    // -------------------------------------------------------------------------

    public function onApiRegisterRoutes(Event $event): void
    {
        $routes = $event['routes'];
        $controller = \Grav\Plugin\Botwarden\BotwardenApiController::class;
        $routes->get('/botwarden/status', [$controller, 'status']);
        $routes->get('/botwarden/legacy', [$controller, 'legacy']);
        $routes->post('/botwarden/migrate', [$controller, 'migrateLegacy']);
        $routes->get('/botwarden/blocks', [$controller, 'blocks']);
        $routes->get('/botwarden/history', [$controller, 'history']);
        $routes->post('/botwarden/blocks', [$controller, 'addBlock']);
        $routes->post('/botwarden/unblock', [$controller, 'removeBlock']);
    }

    public function onApiSidebarItems(Event $event): void
    {
        $items = $event['items'] ?? [];
        $items[] = [
            'id' => 'botwarden',
            'plugin' => 'botwarden',
            'label' => 'botWarden',
            'icon' => 'fa-shield',
            'route' => '/plugin/botwarden',
            'authorize' => 'api.super',
            'priority' => 10,
        ];
        $event['items'] = $items;
    }

    public function onApiPluginPageInfo(Event $event): void
    {
        if (($event['plugin'] ?? '') !== 'botwarden') return;
        $event['definition'] = [
            'id' => 'botwarden',
            'plugin' => 'botwarden',
            'title' => 'botWarden Security',
            'icon' => 'fa-shield',
            'page_type' => 'component',
        ];
    }

    // -------------------------------------------------------------------------
    // Scheduler
    // -------------------------------------------------------------------------

    public function onSchedulerInitialized(Event $e): void
    {
        $scheduler = $e['scheduler'];
        $config    = $this->config();

        // Do not let an always-on prune task create the new database before
        // the old GRVHulk data has been imported.
        $userDirectory = $this->grav['locator']->findResource('user://', true);
        if (is_string($userDirectory) && LegacyGuard::mustDefer($userDirectory)) {
            return;
        }

        if (!empty($config['auto_clean'])) {
            $job = $scheduler->addFunction('Grav\Plugin\BotwardenPlugin::taskCleanBlacklists', [], 'botwarden-auto-clean');
            $job->backlink('plugins/botwarden');
            $job->at('35 2 * * *');
        }

        // History pruning runs independently of auto_clean: the audit log is
        // written on every block/unblock, so it must be bounded even when the
        // blacklist auto-clean is disabled.
        $job = $scheduler->addFunction('Grav\Plugin\BotwardenPlugin::taskPruneHistory', [], 'botwarden-prune-history');
        $job->backlink('plugins/botwarden');
        $job->at('30 3 * * *');

        if (!empty($config['auto_cache']) && !empty($config['sources']['abuseipdb_bulk']) && !empty($config['abuseipdb']['api_key'])) {
            $job = $scheduler->addFunction('Grav\Plugin\BotwardenPlugin::taskUpdateAbuseipdbBulk', [], 'botwarden-abuseipdb-cache');
            $job->backlink('plugins/botwarden');
            $job->at('45 2 * * *');
        }

        if ((!empty($config['sources']['dark_visitors']) || !empty($config['robots_txt']['enabled'])) && !empty($config['dark_visitors']['api_key'])) {
            $job = $scheduler->addFunction('Grav\Plugin\BotwardenPlugin::taskUpdateDarkVisitors', [], 'botwarden-dark-visitors');
            $job->backlink('plugins/botwarden');
            $job->at('0 3 * * *');
        }

        if (!empty($config['enable_reporting']) && !empty($config['abuseipdb']['api_key'])) {
            $job = $scheduler->addFunction('Grav\Plugin\BotwardenPlugin::taskFlushReports', [], 'botwarden-flush-reports');
            $job->backlink('plugins/botwarden');
            $job->at('*/10 * * * *');
        }
    }

    // -------------------------------------------------------------------------
    // Static scheduler task methods (called in separate PHP process by Grav scheduler)
    // -------------------------------------------------------------------------

    public static function taskCleanBlacklists(): void
    {
        $grav    = Grav::instance();
        $config  = $grav['config']->get('plugins.botwarden');
        $dataDir = $grav['locator']->findResource('user://data', true) . '/botwarden';
        $db      = BotwardenDatabase::getInstance($dataDir);
        $limit   = (int)($config['auto_clean_limit'] ?? 10000);

        $localTtl    = (int)($config['local_ttl'] ?? 86400);
        $historyTtl  = (int)($config['history_ttl'] ?? 2592000);
        $historyMax  = (int)($config['history_max_rows'] ?? 50000);

        $expired     = BotwardenDatabase::pruneExpiredLocal($db, $localTtl);
        BotwardenDatabase::trimLocal($db, $limit);
        BotwardenDatabase::pruneExpiredCache($db);
        // Prune history here too so the VACUUM below reclaims its pages, but the
        // authoritative schedule is the dedicated botwarden-prune-history job,
        // which runs even when auto_clean is disabled.
        $histPruned  = BotwardenDatabase::pruneHistory($db, $historyTtl, $historyMax);
        $db->exec('VACUUM');

        echo "Warden: pruned {$expired} expired local entries (ttl:{$localTtl}s), trimmed to {$limit}, pruned {$histPruned} history rows, vacuumed DB.\n";
    }

    /**
     * Prune the block-history audit log on its own schedule. History rows are
     * written on every block/unblock regardless of auto_clean, so this job must
     * run independently of it — otherwise the table would grow unbounded for the
     * default (auto_clean: false) configuration.
     */
    public static function taskPruneHistory(): void
    {
        $grav    = Grav::instance();
        $config  = $grav['config']->get('plugins.botwarden');
        $dataDir = $grav['locator']->findResource('user://data', true) . '/botwarden';
        $db      = BotwardenDatabase::getInstance($dataDir);

        $historyTtl = (int)($config['history_ttl'] ?? 2592000);
        $historyMax = (int)($config['history_max_rows'] ?? 50000);
        $pruned     = BotwardenDatabase::pruneHistory($db, $historyTtl, $historyMax);

        echo "Warden: pruned {$pruned} history rows (ttl:{$historyTtl}s, max:{$historyMax}).\n";
    }

    public static function taskUpdateAbuseipdbBulk(): void
    {
        $grav   = Grav::instance();
        $config = $grav['config']->get('plugins.botwarden');

        if (empty($config['abuseipdb']['api_key'])) {
            echo "Warden: AbuseIPDB api_key not configured, skipping bulk update.\n";
            return;
        }

        $dataDir = $grav['locator']->findResource('user://data', true) . '/botwarden';
        $db      = BotwardenDatabase::getInstance($dataDir);
        $client  = new AbuseIpDbClient($config['abuseipdb']);
        $limit   = (int)($config['abuseipdb']['bulk_limit'] ?? 10000);

        try {
            $ips   = $client->fetchBulkBlacklist($limit);
            $count = BotwardenDatabase::replaceBulkList($db, $ips);
            BotwardenDatabase::setMeta($db, 'abuseipdb_bulk_updated', (string)time());
            BotwardenDatabase::setMeta($db, 'abuseipdb_bulk_count', (string)$count);
            echo "Warden: AbuseIPDB bulk updated ({$count} IPs).\n";
        } catch (\Throwable $e) {
            echo "Warden: AbuseIPDB bulk update failed: " . $e->getMessage() . "\n";
        }
    }

    public static function taskUpdateDarkVisitors(): void
    {
        $grav   = Grav::instance();
        $config = $grav['config']->get('plugins.botwarden');

        if (empty($config['dark_visitors']['api_key'])) {
            echo "Warden: Known Agents api_key not configured, skipping update.\n";
            return;
        }

        $dataDir = $grav['locator']->findResource('user://data', true) . '/botwarden';
        $db      = BotwardenDatabase::getInstance($dataDir);
        $client  = new DarkVisitorsClient($config['dark_visitors']['api_key']);
        $types   = self::normalizeAgentTypes($config['dark_visitors']['agent_types'] ?? []);

        try {
            $text = $client->fetchRobotsTxt($types);
            $agents = DarkVisitorsClient::parseAgents($text);
            BotwardenDatabase::replaceDarkVisitorsAgents($db, $agents);
            if (!empty($config['robots_txt']['enabled'])) {
                if (!defined('GRAV_ROOT')) {
                    throw new \RuntimeException('GRAV_ROOT is unavailable for robots.txt');
                }
                \Grav\Plugin\Botwarden\RobotsTxtManager::publish(
                    GRAV_ROOT . '/robots.txt',
                    $agents,
                    $dataDir . '/backups'
                );
            }
            BotwardenDatabase::setMeta($db, 'dark_visitors_updated', (string)time());
            $count = count($agents);
            echo "Warden: Known Agents list updated ({$count} user-agents).\n";
        } catch (\Throwable $e) {
            echo "Warden: Known Agents update failed: " . $e->getMessage() . "\n";
        }
    }

    /**
     * Flush queued AbuseIPDB reports. Reporting is deferred off the request hot
     * path (a synchronous external POST per matched request would expose the
     * site to PHP-FPM worker exhaustion), so this scheduler job sends them.
     */
    public static function taskFlushReports(): void
    {
        $grav   = Grav::instance();
        $config = $grav['config']->get('plugins.botwarden');

        if (empty($config['abuseipdb']['api_key'])) {
            echo "Warden: AbuseIPDB api_key not configured, skipping report flush.\n";
            return;
        }

        $dataDir = $grav['locator']->findResource('user://data', true) . '/botwarden';
        $db      = BotwardenDatabase::getInstance($dataDir);
        $client  = new AbuseIpDbClient($config['abuseipdb']);

        $sent = 0;
        $failed = 0;
        foreach (BotwardenDatabase::getPendingReports($db, 200) as $report) {
            if ($client->reportIp($report['ip'], $report['path'])) {
                BotwardenDatabase::deleteReport($db, (int)$report['id']);
                $sent++;
            } else {
                // Keep the row for a later retry (dropped after maxAttempts).
                BotwardenDatabase::failReport($db, (int)$report['id']);
                $failed++;
            }
        }

        echo "Warden: flushed {$sent} queued AbuseIPDB report(s), {$failed} deferred.\n";
    }
}
