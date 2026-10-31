<?php
declare(strict_types=1);
namespace Grav\Plugin\Botwarden\Tests;

use Grav\Plugin\Botwarden\RobotsTxtManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RobotsTxtManagerTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = dirname(__DIR__) . '/vendor/.botwarden-robots-' . bin2hex(random_bytes(8));
        if (file_exists($this->directory) || !mkdir($this->directory, 0700)) throw new \RuntimeException('Cannot create isolated robots fixture');
    }

    protected function tearDown(): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            if ($file->isDir() && !$file->isLink()) { chmod($file->getPathname(), 0700); rmdir($file->getPathname()); }
            else unlink($file->getPathname());
        }
        rmdir($this->directory);
    }

    public static function originalContents(): iterable
    {
        yield 'empty' => [''];
        yield 'no final newline' => ["# normal policy\nUser-agent: GoodBot\nAllow: /"];
        yield 'LF' => ["User-agent: GoodBot\nAllow: /\n"];
        yield 'multiple final LF' => ["User-agent: GoodBot\nAllow: /\n\n\n"];
        yield 'CRLF' => ["# Known Hosts policy\r\n# KNOWN HOSTS START\r\nUser-agent: ExistingBot\r\nDisallow: /private\r\n# KNOWN HOSTS END\r\n\r\n\r\n"];
    }

    #[DataProvider('originalContents')]
    public function testAppendingPreservesEveryOriginalByte(string $original): void
    {
        $rendered = RobotsTxtManager::render($original, ['FixtureBot', 'FixtureBot']);
        $this->assertSame($original, substr($rendered, 0, strlen($original)));
        $this->assertSame(1, substr_count($rendered, RobotsTxtManager::START));
        $this->assertSame(1, substr_count($rendered, 'User-agent: FixtureBot'));
        $this->assertSame($rendered, RobotsTxtManager::render($rendered, ['FixtureBot']));
    }

    public function testReplacingPreservesPrefixAndSuffixExactly(): void
    {
        $prefix = "# owner policy — unchanged\r\nUser-agent: GoodBot\r\nAllow: /\r\n\r\n";
        $suffix = "\r\n\r\n# KNOWN HOSTS START\r\nUser-agent: ExistingBot\r\nDisallow: /private\r\n# KNOWN HOSTS END\r\n\r\n";
        $original = $prefix . RobotsTxtManager::START . "\r\nUser-agent: OldFixtureBot\r\nDisallow: /\r\n" . RobotsTxtManager::END . $suffix;
        $rendered = RobotsTxtManager::render($original, ['FixtureBot']);
        $this->assertSame($prefix, substr($rendered, 0, strpos($rendered, RobotsTxtManager::START)));
        $this->assertSame($suffix, substr($rendered, strpos($rendered, RobotsTxtManager::END) + strlen(RobotsTxtManager::END)));
        $this->assertStringNotContainsString('OldFixtureBot', $rendered);
        $this->assertSame($rendered, RobotsTxtManager::render($rendered, ['FixtureBot']));
    }

    public static function damagedMarkers(): iterable
    {
        $start = RobotsTxtManager::START;
        $end = RobotsTxtManager::END;
        yield 'missing end' => [$start . "\nUser-agent: OldBot\nDisallow: /\n"];
        yield 'missing start' => ["User-agent: OldBot\nDisallow: /\n" . $end . "\n"];
        yield 'duplicate blocks' => ["$start\n$end\n$start\n$end\n"];
        yield 'reversed' => ["$end\n$start\n"];
        yield 'inline start' => ["# owner text $start\n$end\n"];
        yield 'inline end' => ["$start\n# owner text $end\n"];
        yield 'extra start text' => ["$start damaged\n$end\n"];
        yield 'extra end text' => ["$start\n$end damaged\n"];
    }

    #[DataProvider('damagedMarkers')]
    public function testDamagedMarkersRefusePublicationWithoutChanges(string $original): void
    {
        $path = $this->directory . '/robots.txt';
        $backup = $this->directory . '/backups';
        file_put_contents($path, $original);
        chmod($path, 0640);
        try {
            RobotsTxtManager::publish($path, ['FixtureBot'], $backup);
            $this->fail('Damaged markers must refuse publication');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('marker', $error->getMessage());
        }
        $this->assertSame($original, file_get_contents($path));
        $this->assertSame(0640, fileperms($path) & 0777);
        $this->assertDirectoryDoesNotExist($backup);
        $this->assertSame([], glob($this->directory . '/.botwarden-*'));
    }

    public function testChangedPublicationBacksUpAndPreservesPermissions(): void
    {
        $path = $this->directory . '/robots.txt';
        $backup = $this->directory . '/backups';
        $original = "User-agent: GoodBot\r\nAllow: /\r\n\r\n";
        file_put_contents($path, $original);
        chmod($path, 0640);
        $first = RobotsTxtManager::publish($path, ['FixtureBot'], $backup);
        $this->assertTrue($first['changed']);
        $this->assertSame(hash_file('sha256', $path), $first['sha256']);
        clearstatcache();
        $this->assertSame(0640, fileperms($path) & 0777);
        $files = glob($backup . '/*');
        $this->assertCount(1, $files);
        $this->assertMatchesRegularExpression('/^robots-\d{8}-\d{6}-[a-f0-9]{10}\.txt$/', basename($files[0]));
        $this->assertSame($original, file_get_contents($files[0]));
        $this->assertSame(0600, fileperms($files[0]) & 0777);
        $this->assertSame(0700, fileperms($backup) & 0777);
        $mtime = filemtime($path);
        $second = RobotsTxtManager::publish($path, ['FixtureBot'], $backup);
        $this->assertFalse($second['changed']);
        $this->assertSame($first['sha256'], $second['sha256']);
        $this->assertSame($files, glob($backup . '/*'));
        $this->assertSame($mtime, filemtime($path));
        $beforeUpdate = file_get_contents($path);
        $third = RobotsTxtManager::publish($path, ['DifferentFixtureBot'], $backup);
        $this->assertTrue($third['changed']);
        $files = glob($backup . '/*');
        $this->assertCount(2, $files, 'Changes within the same second still need distinct backups');
        $this->assertContains($beforeUpdate, array_map('file_get_contents', $files));
        $this->assertSame([], glob($this->directory . '/.botwarden-*'));
    }

    public function testMissingFileCreatedWithPublicReadPermissions(): void
    {
        $path = $this->directory . '/robots.txt';
        $backup = $this->directory . '/backups';
        $result = RobotsTxtManager::publish($path, ['FixtureBot'], $backup);
        $this->assertTrue($result['changed']);
        $this->assertSame(0644, fileperms($path) & 0777);
        $this->assertSame([], glob($backup . '/*'));
        $this->assertStringStartsWith(RobotsTxtManager::START, file_get_contents($path));
    }

    public function testSymlinkTargetIsNeverFollowed(): void
    {
        $target = $this->directory . '/owner.txt';
        $path = $this->directory . '/robots.txt';
        file_put_contents($target, 'owner bytes');
        symlink($target, $path);
        try {
            RobotsTxtManager::publish($path, ['FixtureBot'], $this->directory . '/backups');
            $this->fail('A symlink must be refused');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('symlink', $error->getMessage());
        }
        $this->assertSame('owner bytes', file_get_contents($target));
        $this->assertTrue(is_link($path));
        $this->assertDirectoryDoesNotExist($this->directory . '/backups');
    }
}
