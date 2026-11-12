<?php
namespace Grav\Plugin\Botwarden\Tests;

use Grav\Plugin\Botwarden\BotwardenDatabase;
use Grav\Plugin\Botwarden\VisitReportQueue;
use Grav\Plugin\Botwarden\DarkVisitorsClient;
use PHPUnit\Framework\TestCase;
use SQLite3;

final class VisitReportingTest extends TestCase
{
    private string $directory;
    private SQLite3 $db;
    private VisitReportQueue $queue;
    private int $now = 1700000000;

    protected function setUp(): void
    {
        (new \ReflectionProperty(BotwardenDatabase::class, 'instance'))->setValue(null, null);
        $this->directory = sys_get_temp_dir() . '/botwarden_visits_' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        $this->db = BotwardenDatabase::getInstance($this->directory . '/botwarden');
        $this->queue = new VisitReportQueue($this->db, fn (): int => $this->now);
    }

    protected function tearDown(): void
    {
        $this->db->close();
        (new \ReflectionProperty(BotwardenDatabase::class, 'instance'))->setValue(null, null);
        foreach (glob($this->directory . '/botwarden/botwarden.sqlite*') as $path) unlink($path);
        rmdir($this->directory . '/botwarden');
        rmdir($this->directory);
    }

    private function visit(string $path = '/fixture'): array
    {
        return DarkVisitorsClient::visit(['REQUEST_URI' => $path, 'REMOTE_ADDR' => '198.51.100.7']);
    }

    public function testCaptureIsPrivateBoundedAndRetainsHeaderObjects(): void
    {
        $visit = DarkVisitorsClient::visit(['REQUEST_URI' => '/fixture?token=SECRET', 'HTTP_AUTHORIZATION' => 'SECRET',
            'HTTP_COOKIE' => 'SECRET', 'HTTP_REFERER' => 'https://user:SECRET@example.invalid/path?token=SECRET']);
        $this->assertTrue($this->queue->enqueue($visit));
        $payload = $this->db->querySingle('SELECT payload FROM visit_queue');
        $this->assertStringNotContainsString('SECRET', $payload);
        $this->assertFalse($this->queue->enqueue($this->visit(str_repeat('x', 8193))));
        $this->assertFalse($this->queue->enqueue($this->visit("/invalid\xff")));
        $this->assertSame('2', BotwardenDatabase::getMeta($this->db, 'visits_dropped'));
        $result = $this->queue->flush(function (array $batch, int $timeout): void {
            $this->assertIsObject($batch[0]['request_headers']);
            $this->assertSame(3, $timeout);
        });
        $this->assertSame(1, $result['accepted']);
        $this->assertSame(0, $result['pending']);
    }

    public function testFailureRetainsVisitsWithIncreasingBackoffAndPeriodicWarnings(): void
    {
        $this->queue->enqueue($this->visit());
        $send = static function (): void { throw new \RuntimeException('SECRET provider body'); };
        $detail = static fn (): array => ['status' => 502, 'curl_error' => 0, 'total_ms' => 12,
            'body' => 'SECRET', 'headers' => ['x-request-id' => 'fixture-123', 'authorization' => 'SECRET']];
        $warnings = [];
        foreach ([300, 600, 1200, 2400, 3600] as $i => $delay) {
            $result = $this->queue->flush($send, $detail);
            $warnings = array_merge($warnings, $result['events']);
            $this->assertSame('degraded', $result['state']);
            $this->assertSame($this->now + $delay, $result['retry_after']);
            $this->assertSame(1, $result['pending']);
            $backoff = $this->queue->flush(static function (): void { throw new \LogicException('Must not send during backoff'); });
            $this->assertSame('backoff', $backoff['state']);
            $this->assertSame([], $backoff['events']);
            if ($i < 4) $this->now += $delay;
        }
        $this->assertCount(2, $warnings, 'Warn once initially and summarize after an hour, not every retry');
        $this->assertStringNotContainsString('SECRET', json_encode($warnings));
        $this->assertSame('fixture-123', $this->queue->diagnostics()['headers']['x-request-id']);
        $this->assertArrayNotHasKey('authorization', $this->queue->diagnostics()['headers']);
        $this->now += 3600;
        $recovery = $this->queue->flush(static function (): void {});
        $this->assertSame(1, $recovery['accepted']);
        $this->assertSame(0, $recovery['pending']);
        $this->assertCount(1, $recovery['events']);
        $this->assertSame('info', $recovery['events'][0]['level']);
        $this->assertSame('0', BotwardenDatabase::getMeta($this->db, 'visits_failure_streak'));
        $this->assertSame('5', BotwardenDatabase::getMeta($this->db, 'visits_failed'));
        $this->queue->enqueue($this->visit());
        $this->assertSame([], $this->queue->flush(static function (): void {})['events']);
    }

    public function testRetryAfterIsHonoredAndExpiredVisitsAreNotDelivered(): void
    {
        $this->queue->enqueue($this->visit());
        $result = $this->queue->flush(static fn () => false, static fn () => ['status' => 429, 'headers' => ['retry-after' => '7200']]);
        $this->assertSame($this->now + 7200, $result['retry_after']);
        $this->now += VisitReportQueue::TTL;
        $result = $this->queue->flush(static function (): void { throw new \LogicException('Expired visit sent'); });
        $this->assertSame(1, $result['expired']);
        $this->assertSame(0, $result['pending']);
        $this->assertSame('1', BotwardenDatabase::getMeta($this->db, 'visits_expired'));
    }

    public function testFlushUsesSmallBatchesAndALimitedRun(): void
    {
        for ($i = 0; $i < 230; ++$i) $this->queue->enqueue($this->visit('/' . $i));
        $batches = [];
        $result = $this->queue->flush(static function (array $visits) use (&$batches): void { $batches[] = count($visits); });
        $this->assertSame(array_fill(0, 8, 25), $batches);
        $this->assertSame(200, $result['accepted']);
        $this->assertSame(30, $result['pending']);
    }

    public function testOtherWorkersCannotFlushAndVisitorWritesContinueDuringDelivery(): void
    {
        $otherDb = new SQLite3($this->directory . '/botwarden/botwarden.sqlite');
        $otherDb->enableExceptions(true);
        try {
            $other = new VisitReportQueue($otherDb, fn (): int => $this->now);
            $this->queue->enqueue($this->visit('/first'));
            $calls = 0;
            $result = $this->queue->flush(function () use ($other, &$calls): void {
                $this->assertSame('busy', $other->flush(static function (): void { throw new \LogicException('Concurrent send'); })['state']);
                if ($calls++ === 0) $this->assertTrue($other->enqueue($this->visit('/during-delivery')));
            });
            $this->assertSame(2, $result['accepted']);
        } finally { $otherDb->close(); }
    }

    public function testDamagedEntriesAreCountedWithoutExceedingTheDeliveryBudget(): void
    {
        for ($i = 0; $i < 24; ++$i) $this->db->exec("INSERT INTO visit_queue (payload, queued_at) VALUES ('damaged-json', {$this->now})");
        for ($i = 0; $i < 201; ++$i) $this->queue->enqueue($this->visit());
        $result = $this->queue->flush(static function (): void {});
        $this->assertSame(200, $result['accepted']);
        $this->assertSame(1, $result['pending']);
        $this->assertSame('24', BotwardenDatabase::getMeta($this->db, 'visits_dropped'));
    }

    public function testVisitsExpiringDuringAFlushAreNotSentInALaterBatch(): void
    {
        for ($i = 0; $i < 26; ++$i) $this->queue->enqueue($this->visit());
        $this->db->exec('UPDATE visit_queue SET queued_at = ' . ($this->now - VisitReportQueue::TTL + 1));
        $result = $this->queue->flush(function (): void { $this->now += 2; });
        $this->assertSame(25, $result['accepted']);
        $this->assertSame(1, $result['expired']);
        $this->assertSame(0, $result['pending']);
    }

    public function testLateWorkerCannotAcknowledgeOrReleaseAnotherWorkersClaim(): void
    {
        $this->queue->enqueue($this->visit());
        try {
            $this->queue->flush(function (): void {
                BotwardenDatabase::setMeta($this->db, 'visits_flush_owner', 'replacement-owner');
                BotwardenDatabase::setMeta($this->db, 'visits_flush_until', (string)($this->now + 60));
            });
            $this->fail('Late worker acknowledged another claim');
        } catch (\RuntimeException $e) { $this->assertStringContainsString('lease changed', $e->getMessage()); }
        $this->assertSame(1, $this->queue->pending());
        $this->assertSame('replacement-owner', BotwardenDatabase::getMeta($this->db, 'visits_flush_owner'));
        $this->assertNull(BotwardenDatabase::getMeta($this->db, 'visits_accepted'));
    }

    public function testAcknowledgementRollsBackWhenDiagnosticsCannotBeSaved(): void
    {
        $this->queue->enqueue($this->visit());
        $this->db->exec("CREATE TRIGGER fail_visit_timestamp BEFORE INSERT ON metadata
            WHEN NEW.key = 'visits_last_attempt' BEGIN SELECT RAISE(ABORT, 'synthetic failure'); END");
        try { $this->queue->flush(static function (): void {}); $this->fail('Expected a persistence failure'); }
        catch (\SQLite3Exception $error) {}
        $this->assertSame(1, $this->queue->pending());
        $this->assertNull(BotwardenDatabase::getMeta($this->db, 'visits_accepted'));
        $this->assertSame('0', BotwardenDatabase::getMeta($this->db, 'visits_flush_until'));
    }

    public function testCaptureRecoversFromBriefWriterContention(): void
    {
        $this->withWriterLock(50000, function (): void { $this->assertTrue($this->queue->enqueue($this->visit())); });
        $this->assertSame(1, $this->queue->pending());
    }

    public function testConcurrentCapturesCannotExceedTheQueueLimit(): void
    {
        $insert = $this->db->prepare('INSERT INTO visit_queue (payload, queued_at) VALUES (:payload, :at)');
        $insert->bindValue(':payload', json_encode($this->visit()), SQLITE3_TEXT);
        $insert->bindValue(':at', $this->now, SQLITE3_INTEGER);
        $this->db->exec('BEGIN');
        for ($i = 0; $i < VisitReportQueue::MAX_ROWS - 1; ++$i) $insert->execute();
        $this->db->exec('COMMIT');
        $code = <<<'PHP'
require $argv[1];
$db = \Grav\Plugin\Botwarden\BotwardenDatabase::getInstance($argv[2] . '/botwarden');
$queue = new \Grav\Plugin\Botwarden\VisitReportQueue($db, static fn () => 1700000000);
fwrite(STDOUT, "ready\n"); fflush(STDOUT); fgets(STDIN);
echo json_encode($queue->enqueue(\Grav\Plugin\Botwarden\DarkVisitorsClient::visit([]))), "\n";
PHP;
        $workers = [];
        try {
            for ($i = 0; $i < 8; ++$i) {
                $process = proc_open([PHP_BINARY, '-r', $code, __DIR__ . '/bootstrap.php', $this->directory],
                    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
                $this->assertIsResource($process); $workers[] = [$process, $pipes];
            }
            foreach ($workers as [$process, $pipes]) $this->assertSame("ready\n", fgets($pipes[1]));
            foreach ($workers as [$process, $pipes]) { fwrite($pipes[0], "capture\n"); fflush($pipes[0]); }
            $results = [];
            foreach ($workers as [$process, $pipes]) $results[] = json_decode(stream_get_contents($pipes[1]), true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame(1, count(array_filter($results)));
            $this->assertSame(VisitReportQueue::MAX_ROWS, $this->queue->pending());
            $this->assertSame('7', BotwardenDatabase::getMeta($this->db, 'visits_dropped'));
        } finally {
            foreach ($workers as [$process, $pipes]) {
                fclose($pipes[0]); fclose($pipes[1]); $error = stream_get_contents($pipes[2]); fclose($pipes[2]);
                $this->assertSame(0, proc_close($process), $error);
            }
        }
    }

    public function testSustainedWriterContentionIsBounded(): void
    {
        $this->withWriterLock(400000, function (): void {
            $started = microtime(true);
            try { $this->queue->enqueue($this->visit()); $this->fail('Expected a bounded busy error'); }
            catch (\SQLite3Exception $error) { $this->assertContains($error->getCode() & 0xff, [5, 6]); }
            $this->assertLessThan(0.35, microtime(true) - $started);
        });
        $this->assertSame(0, $this->queue->pending());
    }

    public function testUpgradeAddsTheQueueWithoutChangingExistingData(): void
    {
        BotwardenDatabase::setMeta($this->db, 'retained', 'owner-data');
        $this->db->exec('DROP TABLE visit_queue; PRAGMA user_version=4');
        $this->db->close();
        (new \ReflectionProperty(BotwardenDatabase::class, 'instance'))->setValue(null, null);
        $this->db = BotwardenDatabase::getInstance($this->directory . '/botwarden');
        $this->assertSame(5, $this->db->querySingle('PRAGMA user_version'));
        $this->assertSame(0, $this->db->querySingle('SELECT COUNT(*) FROM visit_queue'));
        $this->assertSame('owner-data', BotwardenDatabase::getMeta($this->db, 'retained'));
    }

    private function withWriterLock(int $microseconds, callable $check): void
    {
        $code = '$db = new SQLite3($argv[1], SQLITE3_OPEN_READWRITE); $db->enableExceptions(true);'
            . '$db->exec("BEGIN IMMEDIATE"); fwrite(STDOUT, "locked\\n"); fflush(STDOUT);'
            . 'usleep((int)$argv[2]); $db->exec("ROLLBACK");';
        $process = proc_open([PHP_BINARY, '-r', $code, $this->directory . '/botwarden/botwarden.sqlite', (string)$microseconds],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);
        try {
            $this->assertSame("locked\n", fgets($pipes[1]));
            $check();
        } finally {
            fclose($pipes[1]);
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[2]);
            $this->assertSame(0, proc_close($process), $error);
        }
    }
}
