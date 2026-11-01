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
        $db = $this->existingDb();
        if ($db === null) {
            return ApiResponse::create(['local_count' => 0, 'history_count' => 0,
                'abuseipdb_bulk_count' => 0, 'dark_visitors_count' => 0]);
        }
        return ApiResponse::create([
            'local_count' => BotwardenDatabase::getLocalCount($db),
            'history_count' => BotwardenDatabase::getHistoryCount($db),
            'abuseipdb_bulk_count' => BotwardenDatabase::getAbuseipdbBulkCount($db),
            'dark_visitors_count' => BotwardenDatabase::getDarkVisitorsCount($db),
        ]);
    }

    public function legacy(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.super');
        return ApiResponse::create(LegacyMigration::preview($this->userDir()));
    }

    public function connectKnownAgents(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.super');
        $token = trim((string)Grav::instance()['config']->get('plugins.botwarden.dark_visitors.api_key', ''));
        if ($token === '') {
            throw new ValidationException('Save your Known Agents project access token in plugin settings first', [
                ['field' => 'dark_visitors.api_key', 'message' => 'Project access token required'],
            ]);
        }
        $visit = DarkVisitorsClient::visit($request->getServerParams());
        $visit['metadata'] = ['integration_test' => true];
        (new DarkVisitorsClient($token))->reportVisit($visit);
        return ApiResponse::create(['accepted' => true]);
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
            return ApiResponse::create(['entries' => [], 'total' => 0, 'limit' => $limit, 'offset' => $offset]);
        }
        return ApiResponse::create([
            'entries' => BotwardenDatabase::getLocalPaginated($db, $limit, $offset),
            'total' => BotwardenDatabase::getLocalCount($db),
            'limit' => $limit, 'offset' => $offset,
        ]);
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
        return ApiResponse::create([
            'entries' => BotwardenDatabase::getHistory($db, $limit, $offset),
            'total' => BotwardenDatabase::getHistoryCount($db),
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
}
