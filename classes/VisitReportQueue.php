<?php
namespace Grav\Plugin\Botwarden;

use SQLite3;
use Throwable;

/** Bounded visit storage and single-owner scheduler delivery. No network on enqueue. */
final class VisitReportQueue
{
    public const MAX_ROWS = 10000;
    public const MAX_BYTES = 8192;
    public const TTL = 86400;
    private $clock;

    public function __construct(private SQLite3 $db, ?callable $clock = null)
    {
        $this->clock = $clock ?? time(...);
    }

    public function enqueue(array $visit): bool
    {
        $payload = '';
        try {
            $payload = json_encode($visit, JSON_THROW_ON_ERROR);
            $valid = strlen($payload) <= self::MAX_BYTES && $this->decode($payload) !== null;
        } catch (Throwable $error) { $valid = false; }
        return $this->transaction(function () use ($valid, $payload): bool {
            if (!$valid) { $this->add('visits_dropped'); return false; }
            if ($this->pending() >= self::MAX_ROWS && (int)$this->meta('visits_flush_until') <= ($this->clock)()) $this->expireRows(25);
            $stmt = $this->db->prepare('INSERT INTO visit_queue (payload, queued_at)
                SELECT :payload, :at WHERE (SELECT COUNT(*) FROM visit_queue) < :cap');
            $stmt->bindValue(':payload', $payload, SQLITE3_TEXT);
            $stmt->bindValue(':at', ($this->clock)(), SQLITE3_INTEGER);
            $stmt->bindValue(':cap', self::MAX_ROWS, SQLITE3_INTEGER);
            $stmt->execute();
            $saved = $this->db->changes() === 1;
            $this->add($saved ? 'visits_queued' : 'visits_dropped');
            return $saved;
        });
    }

    public function expire(): int
    {
        return $this->transaction(fn (): int => (int)$this->meta('visits_flush_until') > ($this->clock)() ? 0 : $this->expireRows(self::MAX_ROWS));
    }

    /** $send must acknowledge the batch or throw; diagnostics must exclude customer data. */
    public function flush(callable $send, ?callable $diagnostics = null): array
    {
        $result = ['state' => 'idle', 'accepted' => 0, 'failed' => 0, 'expired' => 0, 'events' => []];
        $owner = bin2hex(random_bytes(16));
        $claimed = $this->transaction(function () use ($owner, &$result): bool {
            $now = ($this->clock)();
            if ((int)$this->meta('visits_flush_until') > $now) { $result['state'] = 'busy'; return false; }
            $result['expired'] = $this->expireRows(self::MAX_ROWS);
            if ((int)$this->meta('visits_retry_after') > $now) { $result['state'] = 'backoff'; return false; }
            $this->set('visits_flush_owner', $owner);
            $this->set('visits_flush_until', $now + 60);
            return true;
        });
        if (!$claimed) return $result + ['pending' => $this->pending(), 'retry_after' => (int)$this->meta('visits_retry_after')];
        $deadline = microtime(true) + 20;
        $attempted = 0;
        try {
            while ($attempted < 200 && ($remaining = (int)floor($deadline - microtime(true))) >= 1) {
                $this->transaction(function () use ($owner, &$result): void {
                    $this->requireOwner($owner);
                    $result['expired'] += $this->expireRows(self::MAX_ROWS);
                });
                $rows = $this->db->query('SELECT id, payload FROM visit_queue ORDER BY id LIMIT ' . min(25, 200 - $attempted));
                $visits = []; $ids = []; $invalid = [];
                while ($row = $rows->fetchArray(SQLITE3_ASSOC)) {
                    $visit = $this->decode($row['payload']);
                    if ($visit === null) { $invalid[] = (int)$row['id']; continue; }
                    $visits[] = $visit; $ids[] = (int)$row['id'];
                }
                $rows->finalize();
                if ($invalid) $this->transaction(function () use ($owner, $invalid): void {
                    $this->requireOwner($owner);
                    $this->remove($invalid); $this->add('visits_dropped', count($invalid));
                });
                if (!$ids) { if ($invalid) continue; break; }
                $attempted += count($ids);
                $failure = null;
                try {
                    if ($send($visits, min(3, $remaining)) === false) throw new \RuntimeException('Batch not acknowledged');
                } catch (Throwable $error) { $failure = $error; }
                $detail = $this->safeDiagnostics($diagnostics ? $diagnostics() : []);
                $this->transaction(function () use ($owner, $ids, $detail, $failure, &$result): void {
                    $this->requireOwner($owner);
                    $now = ($this->clock)();
                    $this->set('visits_last_attempt', $now);
                    $this->set('visits_last_diagnostics', json_encode($detail, JSON_THROW_ON_ERROR));
                    if ($failure !== null) {
                        $streak = (int)$this->meta('visits_failure_streak') + 1;
                        $delay = min(3600, 300 * (2 ** min(4, $streak - 1)));
                        $retry = $detail['headers']['retry-after'] ?? '';
                        if ($retry !== '') {
                            $seconds = ctype_digit($retry) ? (int)$retry : max(0, (strtotime($retry) ?: $now) - $now);
                            $delay = max($delay, min(self::TTL, $seconds));
                        }
                        $this->add('visits_failed', count($ids));
                        $this->set('visits_failure_streak', $streak);
                        $this->set('visits_retry_after', $now + $delay);
                        $error = !empty($detail['curl_error']) ? 'HTTP transport error (' . $detail['curl_error'] . ')'
                            : (!empty($detail['status']) ? 'HTTP ' . $detail['status'] : 'Delivery did not acknowledge the batch');
                        $this->set('visits_last_error', $error);
                        if ($streak === 1) $this->set('visits_outage_started', $now);
                        if ($streak === 1 || $now - (int)$this->meta('visits_last_warning') >= 3600) {
                            $this->set('visits_last_warning', $now);
                            $result['events'][] = ['level' => 'warning', 'message' => '[botWarden] Known Agents visit delivery failed: '
                                . $error . '; ' . $streak . ' failed batch attempt(s), ' . $this->pending()
                                . ' visits retained; retry in ' . $delay . 's; diagnostics=' . json_encode($detail, JSON_THROW_ON_ERROR)];
                        }
                        $result['failed'] += count($ids); $result['state'] = 'degraded';
                    } else {
                        $streak = (int)$this->meta('visits_failure_streak');
                        if ($streak) $result['events'][] = ['level' => 'info', 'message' => '[botWarden] Known Agents visit delivery recovered after '
                            . max(0, $now - (int)$this->meta('visits_outage_started')) . 's and ' . $streak . ' failed batch attempt(s)'];
                        $this->remove($ids); $this->add('visits_accepted', count($ids));
                        foreach (['visits_failure_streak', 'visits_retry_after', 'visits_outage_started', 'visits_last_warning'] as $key) $this->set($key, 0);
                        $this->set('visits_last_error', ''); $this->set('visits_last_success', $now);
                        $result['accepted'] += count($ids); $result['state'] = 'delivered';
                    }
                });
                if ($failure !== null) break;
            }
        } finally {
            // A late worker must not release another worker's lease.
            $this->transaction(function () use ($owner): void {
                if ($this->meta('visits_flush_owner') === $owner) {
                    $this->set('visits_flush_owner', ''); $this->set('visits_flush_until', 0);
                }
            });
        }
        return $result + ['pending' => $this->pending(), 'retry_after' => (int)$this->meta('visits_retry_after')];
    }

    public function pending(): int { return (int)$this->db->querySingle('SELECT COUNT(*) FROM visit_queue'); }

    public function diagnostics(): array
    {
        $raw = json_decode($this->meta('visits_last_diagnostics') ?? '{}', true);
        return $this->safeDiagnostics(is_array($raw) ? $raw : []);
    }

    private function safeDiagnostics(array $detail): array
    {
        $safe = [];
        foreach (['status', 'curl_error', 'dns_ms', 'connect_ms', 'tls_ms', 'first_byte_ms', 'total_ms'] as $key) {
            if (isset($detail[$key]) && is_numeric($detail[$key]) && is_finite((float)$detail[$key])) $safe[$key] = max(0, (float)$detail[$key]);
        }
        $safe['headers'] = [];
        foreach (['server', 'via', 'cf-ray', 'x-request-id', 'retry-after'] as $key) {
            if (isset($detail['headers'][$key]) && is_string($detail['headers'][$key])) {
                $safe['headers'][$key] = substr(preg_replace('/[^\x20-\x7e]/', '', $detail['headers'][$key]), 0, 160);
            }
        }
        return $safe;
    }

    private function decode(string $payload): ?array
    {
        try { $visit = json_decode($payload, false, 32, JSON_THROW_ON_ERROR); }
        catch (Throwable $error) { return null; }
        if (!is_object($visit) || !is_string($visit->request_path ?? null) || !is_string($visit->request_method ?? null)
            || !is_object($visit->request_headers ?? null)) return null;
        return (array)$visit;
    }

    private function expireRows(int $limit): int
    {
        $stmt = $this->db->prepare('DELETE FROM visit_queue WHERE id IN
            (SELECT id FROM visit_queue WHERE queued_at <= :at ORDER BY queued_at LIMIT :n)');
        $stmt->bindValue(':at', ($this->clock)() - self::TTL, SQLITE3_INTEGER);
        $stmt->bindValue(':n', $limit, SQLITE3_INTEGER); $stmt->execute();
        $count = $this->db->changes(); if ($count) $this->add('visits_expired', $count);
        return $count;
    }

    private function remove(array $ids): void { $this->db->exec('DELETE FROM visit_queue WHERE id IN (' . implode(',', array_map('intval', $ids)) . ')'); }
    private function meta(string $key): ?string { return BotwardenDatabase::getMeta($this->db, $key); }
    private function set(string $key, string|int $value): void { BotwardenDatabase::setMeta($this->db, $key, (string)$value); }
    private function add(string $key, int $count = 1): void { $this->set($key, (int)$this->meta($key) + $count); }

    private function requireOwner(string $owner): void
    {
        if ($this->meta('visits_flush_owner') !== $owner || (int)$this->meta('visits_flush_until') <= ($this->clock)()) {
            throw new \RuntimeException('Visit delivery lease changed; queued visits retained');
        }
    }

    private function transaction(callable $operation): mixed
    {
        $this->db->busyTimeout(100); $active = false;
        try {
            $this->db->exec('BEGIN IMMEDIATE'); $active = true;
            $result = $operation(); $this->db->exec('COMMIT'); $active = false;
            return $result;
        } catch (Throwable $error) { if ($active) $this->db->exec('ROLLBACK'); throw $error; }
        finally { $this->db->busyTimeout(5000); }
    }
}
