<?php
namespace Grav\Plugin\Botwarden\Tests;

use Grav\Plugin\Botwarden\BotwardenDatabase;
use Grav\Plugin\BotwardenPlugin;
use PHPUnit\Framework\TestCase;
use SQLite3;

final class VisitReportingTest extends TestCase
{
    private string $directory;
    private SQLite3 $db;
    private BotwardenPlugin $plugin;
    private object $log;

    protected function setUp(): void
    {
        (new \ReflectionProperty(BotwardenDatabase::class, 'instance'))->setValue(null, null);
        $this->directory = sys_get_temp_dir() . '/botwarden_visits_' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        $this->db = BotwardenDatabase::getInstance($this->directory . '/botwarden');
        $this->log = new class {
            public array $warnings = [];
            public function warning(string $message): void { $this->warnings[] = $message; }
            public function debug(string $message): void {}
        };
        $this->plugin = new class($this->directory, $this->log) extends BotwardenPlugin {
            protected $grav;
            public function __construct(string $directory, object $log) {
                $this->grav = ['log' => $log, 'locator' => new class($directory) {
                    public function __construct(private string $directory) {}
                    public function findResource(string $resource, bool $absolute): string { return $this->directory; }
                }];
            }
        };
    }

    protected function tearDown(): void
    {
        $this->db->close();
        (new \ReflectionProperty(BotwardenDatabase::class, 'instance'))->setValue(null, null);
        foreach (glob($this->directory . '/botwarden/botwarden.sqlite*') as $path) unlink($path);
        rmdir($this->directory . '/botwarden');
        rmdir($this->directory);
    }

    private function record(bool $accepted): ?bool
    {
        return (new \ReflectionMethod(BotwardenPlugin::class, 'recordVisitOutcome'))->invoke($this->plugin, $accepted);
    }

    public function testInFlightFailuresShareOnePauseAndWarning(): void
    {
        $this->assertTrue($this->record(false));
        $retryAfter = BotwardenDatabase::getMeta($this->db, 'visits_retry_after');
        $this->assertGreaterThan(time(), (int)$retryAfter);
        // An earlier in-flight request can finish after the first failure.
        for ($i = 0; $i < 8; ++$i) { $this->assertFalse($this->record(false)); }
        $this->assertSame('9', BotwardenDatabase::getMeta($this->db, 'visits_failed'));
        $this->assertSame($retryAfter, BotwardenDatabase::getMeta($this->db, 'visits_retry_after'));
    }

    public function testLateSuccessDoesNotCancelAnActiveFailurePause(): void
    {
        $this->assertTrue($this->record(false));
        $retryAfter = BotwardenDatabase::getMeta($this->db, 'visits_retry_after');
        $this->assertFalse($this->record(true));
        $this->assertSame('1', BotwardenDatabase::getMeta($this->db, 'visits_accepted'));
        $this->assertSame($retryAfter, BotwardenDatabase::getMeta($this->db, 'visits_retry_after'));
        $this->assertTrue((new \ReflectionMethod(BotwardenPlugin::class, 'visitReportingDeferred'))->invoke($this->plugin));
        $this->assertFalse($this->record(false));
    }

    public function testExpiredPauseAllowsRecoveryOrANewFailureWarning(): void
    {
        BotwardenDatabase::setMeta($this->db, 'visits_retry_after', (string)(time() - 1));
        $this->assertFalse($this->record(true));
        $this->assertSame('0', BotwardenDatabase::getMeta($this->db, 'visits_retry_after'));
        $this->assertTrue($this->record(false));
        BotwardenDatabase::setMeta($this->db, 'visits_retry_after', (string)(time() - 1));
        $this->assertTrue($this->record(false));
        $this->assertSame('2', BotwardenDatabase::getMeta($this->db, 'visits_failed'));
    }

    public function testConcurrentFailuresOnlyOneWorkerStartsThePause(): void
    {
        $code = <<<'PHP'
require $argv[1];
$plugin = new class($argv[2]) extends \Grav\Plugin\BotwardenPlugin {
    protected $grav;
    public function __construct(string $directory) {
        $this->grav = ['locator' => new class($directory) {
            public function __construct(private string $directory) {}
            public function findResource(string $resource, bool $absolute): string { return $this->directory; }
        }, 'log' => new class {
            public function warning(string $message): void {}
            public function debug(string $message): void {}
        }];
    }
};
$deferred = (new \ReflectionMethod(\Grav\Plugin\BotwardenPlugin::class, 'visitReportingDeferred'))->invoke($plugin);
fwrite(STDOUT, $deferred ? "paused\n" : "ready\n"); fflush(STDOUT);
fgets(STDIN);
$started = (new \ReflectionMethod(\Grav\Plugin\BotwardenPlugin::class, 'recordVisitOutcome'))->invoke($plugin, false);
echo json_encode($started), "\n";
PHP;
        $workers = [];
        try {
            for ($i = 0; $i < 8; ++$i) {
                $process = proc_open([PHP_BINARY, '-r', $code, __DIR__ . '/bootstrap.php', $this->directory],
                    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
                $this->assertIsResource($process);
                $workers[] = [$process, $pipes];
            }
            foreach ($workers as [$process, $pipes]) { $this->assertSame("ready\n", fgets($pipes[1])); }
            foreach ($workers as [$process, $pipes]) { fwrite($pipes[0], "finish\n"); fflush($pipes[0]); }
            $claims = [];
            foreach ($workers as [$process, $pipes]) {
                $claims[] = json_decode(stream_get_contents($pipes[1]), true, 512, JSON_THROW_ON_ERROR);
            }
            $this->assertSame(1, count(array_filter($claims, static fn ($claim) => $claim === true)));
            $this->assertSame(7, count(array_filter($claims, static fn ($claim) => $claim === false)));
            $this->assertSame('8', BotwardenDatabase::getMeta($this->db, 'visits_failed'));
        } finally {
            foreach ($workers as [$process, $pipes]) {
                fclose($pipes[0]);
                fclose($pipes[1]);
                $error = stream_get_contents($pipes[2]);
                fclose($pipes[2]);
                $this->assertSame(0, proc_close($process), $error);
            }
        }
    }

    public function testDiagnosticsRecoverFromBriefWriterContention(): void
    {
        $this->withWriterLock(50000, function (): void {
            $this->record(true);
        });
        $this->assertSame('1', BotwardenDatabase::getMeta($this->db, 'visits_accepted'));
        $this->assertNotNull(BotwardenDatabase::getMeta($this->db, 'visits_last_attempt'));
        $this->assertSame([], $this->log->warnings);
    }

    public function testSustainedWriterContentionIsBoundedAndDoesNotFloodWarnings(): void
    {
        $this->withWriterLock(400000, function (): void {
            $started = microtime(true);
            $this->record(true);
            $this->assertLessThan(0.35, microtime(true) - $started);
        });
        $this->assertNull(BotwardenDatabase::getMeta($this->db, 'visits_accepted'));
        $this->assertSame([], $this->log->warnings);
    }

    public function testDiagnosticsRollBackWhenTimestampCannotBeSaved(): void
    {
        $this->db->exec("CREATE TRIGGER fail_visit_timestamp BEFORE INSERT ON metadata
            WHEN NEW.key = 'visits_last_attempt' BEGIN SELECT RAISE(ABORT, 'synthetic failure'); END");
        $this->record(false);
        $this->assertNull(BotwardenDatabase::getMeta($this->db, 'visits_failed'));
        $this->assertNull(BotwardenDatabase::getMeta($this->db, 'visits_last_attempt'));
        $this->assertCount(1, $this->log->warnings);
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
