<?php
namespace Grav\Plugin\Botwarden\Tests;

use Grav\Plugin\Botwarden\LegacyMigration;
use Grav\Plugin\Botwarden\RobotsTxtManager;
use PHPUnit\Framework\TestCase;
use SQLite3;

final class LegacyMigrationTest extends TestCase
{
    private string $userDir;

    protected function setUp(): void
    {
        $this->userDir = sys_get_temp_dir() . '/botwarden_import_' . bin2hex(random_bytes(8));
        mkdir($this->userDir . '/config/plugins', 0700, true);
        mkdir($this->userDir . '/data/grvhulk', 0700, true);
    }

    protected function tearDown(): void
    {
        // Test-only isolated fixture directory created with an unpredictable name.
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->userDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->userDir);
    }

    public function testSettingsImportedDisabledAndOldSettingsPreserved(): void
    {
        $path = $this->userDir . '/config/plugins/grvhulk.yaml';
        $original = "enabled: true\nlogging: true\nwhitelist: |\n  198.51.100.1\n";
        file_put_contents($path, $original);
        $this->assertTrue(LegacyMigration::preview($this->userDir)['can_migrate']);
        $import = LegacyMigration::migrate($this->userDir, true);
        $this->assertTrue($import['success']);
        $this->assertFalse($import['botwarden_enabled']);
        $this->assertSame($original, file_get_contents($path));
        $new = file_get_contents($this->userDir . '/config/plugins/botwarden.yaml');
        $this->assertStringStartsWith("enabled: false\n", $new);
        $this->assertStringContainsString("logging: true\n", $new);
        $this->assertNotEmpty(glob($this->userDir . '/data/botwarden/backups/grvhulk-*.yaml'));
        $this->assertFalse(LegacyMigration::preview($this->userDir)['can_migrate']);
        $this->assertFileExists($this->userDir . '/data/botwarden/migration-complete.json');
    }

    public function testConfirmationRequired(): void
    {
        file_put_contents($this->userDir . '/config/plugins/grvhulk.yaml', "enabled: true\n");
        $this->expectException(\LogicException::class);
        LegacyMigration::migrate($this->userDir);
    }

    public function testOlderDatabaseWithoutHistoryTableMigrates(): void
    {
        $old = $this->userDir . '/data/grvhulk/grvhulk.sqlite';
        $db = new SQLite3($old);
        $db->exec('CREATE TABLE local (ip TEXT PRIMARY KEY, reason TEXT)');
        $db->exec("INSERT INTO local (ip, reason) VALUES ('198.51.100.12', 'manual')");
        $db->exec('CREATE TABLE abuseipdb_bulk (ip TEXT PRIMARY KEY)');
        $db->exec('PRAGMA user_version=1');
        $db->close();

        $import = LegacyMigration::migrate($this->userDir, true);
        $this->assertTrue($import['database_copied']);
        $this->assertFalse($import['config_copied']);
        $this->assertSame("enabled: false\n",
            file_get_contents($this->userDir . '/config/plugins/botwarden.yaml'));
        $this->assertFileExists($old);
        $copy = new SQLite3($this->userDir . '/data/botwarden/botwarden.sqlite', SQLITE3_OPEN_READONLY);
        try {
            $this->assertSame('198.51.100.12', $copy->querySingle('SELECT ip FROM local'));
            $this->assertSame('ok', $copy->querySingle('PRAGMA integrity_check'));
        } finally { $copy->close(); }
    }

    public function testInvalidLegacyDatabaseRollsBackNewConfig(): void
    {
        file_put_contents($this->userDir . '/config/plugins/grvhulk.yaml', "enabled: true\n");
        file_put_contents($this->userDir . '/data/grvhulk/grvhulk.sqlite', 'not a database');
        try {
            LegacyMigration::migrate($this->userDir, true);
            $this->fail('Bad database must not migrate');
        } catch (\Throwable $error) {
            $this->assertStringContainsString('not a database', $error->getMessage());
            $this->assertFileDoesNotExist($this->userDir . '/config/plugins/botwarden.yaml');
            $this->assertFileDoesNotExist($this->userDir . '/data/botwarden/botwarden.sqlite');
        }
    }

    public function testExistingDestinationIsNeverOverwritten(): void
    {
        file_put_contents($this->userDir . '/config/plugins/grvhulk.yaml', "enabled: true\n");
        file_put_contents($this->userDir . '/config/plugins/botwarden.yaml', "enabled: false\nmarker: keep\n");
        $this->assertFalse(LegacyMigration::preview($this->userDir)['can_migrate']);
        $this->expectException(\RuntimeException::class);
        try { LegacyMigration::migrate($this->userDir, true); }
        finally {
            $this->assertStringContainsString('marker: keep',
                file_get_contents($this->userDir . '/config/plugins/botwarden.yaml'));
        }
    }

    public function testVersion11WalImportPreservesAllTablesAndOriginals(): void
    {
        $config = "enabled: true\nlogging: true\nfilters: |\n  /fixture-probe$\n";
        file_put_contents($this->userDir . '/config/plugins/grvhulk.yaml', $config);
        mkdir($this->userDir . '/plugins/grvhulk', 0700, true);
        $source = $this->createVersion11Database();
        $path = $this->userDir . '/data/grvhulk/grvhulk.sqlite';
        $this->assertFileExists($path . '-wal');
        $this->assertGreaterThan(0, filesize($path . '-wal'));
        $originalHashes = [hash_file('sha256', $path), hash_file('sha256', $path . '-wal')];
        $expected = $this->tableContents($source);

        try {
            $this->assertTrue(LegacyMigration::preview($this->userDir)['can_migrate']);
            $this->assertDirectoryDoesNotExist($this->userDir . '/data/botwarden');
            $result = LegacyMigration::migrate($this->userDir, true);
            $this->assertTrue($result['success']);
            $this->assertFalse($result['botwarden_enabled']);
            $this->assertSame($config, file_get_contents($this->userDir . '/config/plugins/grvhulk.yaml'));
            $this->assertSame($originalHashes, [hash_file('sha256', $path), hash_file('sha256', $path . '-wal')]);
            $this->assertSame(str_replace('enabled: true', 'enabled: false', $config),
                file_get_contents($this->userDir . '/config/plugins/botwarden.yaml'));

            $destination = $this->userDir . '/data/botwarden/botwarden.sqlite';
            $copy = new SQLite3($destination, SQLITE3_OPEN_READONLY);
            try {
                $this->assertSame('ok', $copy->querySingle('PRAGMA integrity_check'));
                $this->assertSame(4, $copy->querySingle('PRAGMA user_version'));
                $this->assertSame($expected, $this->tableContents($copy));
            } finally { $copy->close(); }

            $backup = $this->userDir . '/data/botwarden/backups/grvhulk-' . $result['backup_id'];
            $this->assertSame($config, file_get_contents($backup . '.yaml'));
            $copy = new SQLite3($backup . '.sqlite', SQLITE3_OPEN_READONLY);
            try { $this->assertSame($expected, $this->tableContents($copy)); }
            finally { $copy->close(); }

            $marker = json_decode(file_get_contents($this->userDir . '/data/botwarden/migration-complete.json'), true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame('grvhulk', $marker['source']);
            $this->assertSame($result['backup_id'], $marker['backup_id']);
            $destinationHash = hash_file('sha256', $destination);
            try {
                LegacyMigration::migrate($this->userDir, true);
                $this->fail('A second import must be refused');
            } catch (\RuntimeException $error) {
                $this->assertStringContainsString('Migration not possible', $error->getMessage());
            }
            $this->assertSame($destinationHash, hash_file('sha256', $destination));
            $this->assertCount(1, glob($this->userDir . '/data/botwarden/backups/*.yaml'));
            $this->assertCount(1, glob($this->userDir . '/data/botwarden/backups/*.sqlite'));
            $this->assertSame($expected, $this->tableContents($source));
        } finally { $source->close(); }
    }

    public function testExistingDatabaseIsNeverOverwritten(): void
    {
        $config = "enabled: true\n";
        file_put_contents($this->userDir . '/config/plugins/grvhulk.yaml', $config);
        $source = $this->createVersion11Database();
        mkdir($this->userDir . '/data/botwarden', 0700, true);
        $path = $this->userDir . '/data/botwarden/botwarden.sqlite';
        $destination = new SQLite3($path);
        $destination->exec("CREATE TABLE sentinel (value TEXT); INSERT INTO sentinel VALUES ('preserve')");
        $destination->close();
        $hash = hash_file('sha256', $path);

        try {
            $this->assertFalse(LegacyMigration::preview($this->userDir)['can_migrate']);
            try {
                LegacyMigration::migrate($this->userDir, true);
                $this->fail('An occupied database destination must be refused');
            } catch (\RuntimeException $error) {
                $this->assertStringContainsString('Migration not possible', $error->getMessage());
            }
            $this->assertSame($hash, hash_file('sha256', $path));
            $this->assertSame($config, file_get_contents($this->userDir . '/config/plugins/grvhulk.yaml'));
            $this->assertFileDoesNotExist($this->userDir . '/config/plugins/botwarden.yaml');
            $this->assertDirectoryDoesNotExist($this->userDir . '/data/botwarden/backups');
        } finally { $source->close(); }
    }

    public function testLateFailureRollsBackImportedFilesAndKeepsBackups(): void
    {
        $config = "enabled: true\nlogging: true\n";
        file_put_contents($this->userDir . '/config/plugins/grvhulk.yaml', $config);
        $source = $this->createVersion11Database();
        $expected = $this->tableContents($source);
        $marker = $this->userDir . '/data/botwarden/migration-complete.json';
        mkdir($marker, 0700, true);
        file_put_contents($marker . '/sentinel', 'preserve');

        try {
            try {
                LegacyMigration::migrate($this->userDir, true);
                $this->fail('An unwritable marker destination must fail after copying the database');
            } catch (\RuntimeException $error) {
                $this->assertStringContainsString('Cannot write migration marker', $error->getMessage());
            }
            $this->assertFileDoesNotExist($this->userDir . '/config/plugins/botwarden.yaml');
            $this->assertFileDoesNotExist($this->userDir . '/data/botwarden/botwarden.sqlite');
            $this->assertSame('preserve', file_get_contents($marker . '/sentinel'));
            $this->assertSame($config, file_get_contents($this->userDir . '/config/plugins/grvhulk.yaml'));
            $this->assertSame($expected, $this->tableContents($source));
            $backups = glob($this->userDir . '/data/botwarden/backups/*.sqlite');
            $this->assertCount(1, $backups);
            $copy = new SQLite3($backups[0], SQLITE3_OPEN_READONLY);
            try {
                $this->assertSame('ok', $copy->querySingle('PRAGMA integrity_check'));
                $this->assertSame($expected, $this->tableContents($copy));
            } finally { $copy->close(); }
        } finally { $source->close(); }
    }

    public function testVersion11RollbackUsesUntouchedOriginalDatabase(): void
    {
        $config = "enabled: true\n";
        file_put_contents($this->userDir . '/config/plugins/grvhulk.yaml', $config);
        $source = $this->createVersion11Database();
        $expected = $this->tableContents($source);
        $source->close();
        LegacyMigration::migrate($this->userDir, true);

        $copy = new SQLite3($this->userDir . '/data/botwarden/botwarden.sqlite');
        $copy->exec("INSERT INTO local (ip, reason, is_manual) VALUES ('198.51.100.200', 'post-import fixture', 1)");
        $copy->close();

        // Rollback keeps botWarden disabled and resumes GRVHulk with its originals.
        $this->assertSame('enabled: false', trim(file_get_contents($this->userDir . '/config/plugins/botwarden.yaml')));
        $this->assertSame($config, file_get_contents($this->userDir . '/config/plugins/grvhulk.yaml'));
        $original = new SQLite3($this->userDir . '/data/grvhulk/grvhulk.sqlite', SQLITE3_OPEN_READONLY);
        try {
            $this->assertSame('ok', $original->querySingle('PRAGMA integrity_check'));
            $this->assertSame(4, $original->querySingle('PRAGMA user_version'));
            $this->assertSame($expected, $this->tableContents($original));
        } finally { $original->close(); }
    }

    private function createVersion11Database(): SQLite3
    {
        $db = new SQLite3($this->userDir . '/data/grvhulk/grvhulk.sqlite');
        $db->enableExceptions(true);
        $db->exec(file_get_contents(__DIR__ . '/fixtures/grvhulk-1.1.sql'));
        $db->exec('PRAGMA journal_mode=WAL');
        $db->exec("INSERT INTO local (ip, reason, is_manual, added_at) VALUES ('198.51.100.10', 'automatic fixture', 0, 1700000000), ('198.51.100.11', 'manual fixture', 1, 1700000001)");
        $db->exec("INSERT INTO abuseipdb_bulk (ip, confidence, updated_at) VALUES ('198.51.100.12', 99, 1700000002)");
        $db->exec("INSERT INTO dark_visitors_agents (user_agent, updated_at) VALUES ('FixtureCrawler', 1700000003)");
        $db->exec("INSERT INTO report_queue (ip, path, attempts, queued_at) VALUES ('198.51.100.10', '/fixture-probe', 2, 1700000004)");
        $db->exec("INSERT INTO history (ip, action, reason, is_manual, acted_at) VALUES ('198.51.100.10', 'block', 'automatic fixture', 0, 1700000005), ('198.51.100.13', 'unblock', 'older manual fixture', 1, 1700000006)");
        $db->exec("INSERT INTO ip_cache (ip, source, decision, score, expires_at) VALUES ('198.51.100.14', 'crowdsec_cti', 'block', 4, 1999999999)");
        $db->exec("INSERT INTO metadata (key, value) VALUES ('abuseipdb_bulk_updated', '1700000002')");
        return $db;
    }

    private function tableContents(SQLite3 $db): array
    {
        $tables = [];
        foreach (['local', 'abuseipdb_bulk', 'dark_visitors_agents', 'report_queue', 'history', 'ip_cache', 'metadata', 'sqlite_sequence'] as $name) {
            $rows = $db->query('SELECT * FROM ' . $name . ' ORDER BY rowid');
            $tables[$name] = [];
            while ($row = $rows->fetchArray(SQLITE3_ASSOC)) $tables[$name][] = $row;
            $rows->finalize();
        }
        return $tables;
    }

    public function testRobotsPublicationPreservesOutsideContent(): void
    {
        $root = $this->userDir . '/robots.txt';
        file_put_contents($root, "User-agent: Googlebot\nAllow: /\n");
        $backups = $this->userDir . '/data/botwarden/backups';
        $result = RobotsTxtManager::publish($root, ['GPTBot'], $backups);
        $this->assertTrue($result['changed']);
        $this->assertStringStartsWith("User-agent: Googlebot\nAllow: /",
            file_get_contents($root));
        $this->assertCount(1, glob($backups . '/robots-*.txt'));
        $this->assertFalse(RobotsTxtManager::publish($root, ['GPTBot'], $backups)['changed']);
    }
}
