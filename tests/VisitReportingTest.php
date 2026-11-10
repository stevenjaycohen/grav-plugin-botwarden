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

    private function record(bool $accepted): void
    {
        (new \ReflectionMethod(BotwardenPlugin::class, 'recordVisitOutcome'))->invoke($this->plugin, $accepted);
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
