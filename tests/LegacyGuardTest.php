<?php
namespace Grav\Plugin\Botwarden\Tests;

use Grav\Plugin\Botwarden\LegacyGuard;
use PHPUnit\Framework\TestCase;

final class LegacyGuardTest extends TestCase
{
    public function testLegacyPathsDeferUntilCompletionMarker(): void
    {
        $root = sys_get_temp_dir() . '/botwarden_guard_' . bin2hex(random_bytes(8));
        mkdir($root . '/config/plugins', 0700, true);
        mkdir($root . '/data/botwarden', 0700, true);
        try {
            $this->assertFalse(LegacyGuard::mustDefer($root));
            file_put_contents($root . '/config/plugins/grvhulk.yaml', "enabled: true\n");
            $this->assertTrue(LegacyGuard::mustDefer($root));
            file_put_contents($root . '/data/botwarden/migration-complete.json', '{}');
            $this->assertFalse(LegacyGuard::mustDefer($root));
        } finally {
            @unlink($root . '/config/plugins/grvhulk.yaml');
            @unlink($root . '/data/botwarden/migration-complete.json');
            @rmdir($root . '/config/plugins');
            @rmdir($root . '/config');
            @rmdir($root . '/data/botwarden');
            @rmdir($root . '/data');
            @rmdir($root);
        }
    }
}
