<?php
namespace Grav\Plugin\Botwarden\Tests;

use Grav\Plugin\Botwarden\LegacyInstallationDetector;
use PHPUnit\Framework\TestCase;

final class LegacyInstallationDetectorTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/botwarden_legacy_' . bin2hex(random_bytes(8));
        mkdir($this->root, 0700, true);
    }

    protected function tearDown(): void
    {
        @unlink($this->root . '/config/plugins/grvhulk.yaml');
        @unlink($this->root . '/data/grvhulk/grvhulk.sqlite');
        foreach (['plugins/grvhulk', 'plugins', 'config/plugins', 'config', 'data/grvhulk', 'data'] as $dir) {
            @rmdir($this->root . '/' . $dir);
        }
        @rmdir($this->root);
    }

    public function testNothingFound(): void
    {
        $this->assertSame([
            'detected' => false, 'plugin_present' => false,
            'config_present' => false, 'database_present' => false,
            'config_readable' => false, 'database_readable' => false,
        ], LegacyInstallationDetector::inspect($this->root));
    }

    public function testConfigSurvivesPluginRemoval(): void
    {
        mkdir($this->root . '/config/plugins', 0700, true);
        file_put_contents($this->root . '/config/plugins/grvhulk.yaml', "enabled: true\n");
        $result = LegacyInstallationDetector::inspect($this->root);
        $this->assertTrue($result['detected']);
        $this->assertTrue($result['config_present']);
        $this->assertTrue($result['config_readable']);
        $this->assertFalse($result['plugin_present']);
    }

    public function testDatabaseWithoutPlugin(): void
    {
        mkdir($this->root . '/data/grvhulk', 0700, true);
        file_put_contents($this->root . '/data/grvhulk/grvhulk.sqlite', 'invalid db');
        $result = LegacyInstallationDetector::inspect($this->root);
        $this->assertTrue($result['database_present']);
        $this->assertTrue($result['detected']);
    }

    public function testPluginDirectoryOnly(): void
    {
        mkdir($this->root . '/plugins/grvhulk', 0700, true);
        $this->assertTrue(LegacyInstallationDetector::inspect($this->root)['plugin_present']);
    }

    public function testInvalidPathFails(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        LegacyInstallationDetector::inspect('');
    }
}
