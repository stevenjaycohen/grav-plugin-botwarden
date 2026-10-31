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
