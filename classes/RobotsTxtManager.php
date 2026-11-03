<?php
namespace Grav\Plugin\Botwarden;

/**
 * Opt-in robots.txt manager. Only edits content between marked lines,
 * performs a backup before writing, and preserves every other byte.
 */
final class RobotsTxtManager
{
    public const START = '# botWarden:START';
    public const END = '# botWarden:END';

    /** Pure function for preview, testing, and idempotent updates. */
    public static function render(string $original, array $userAgents): string
    {
        $startCount = substr_count($original, self::START);
        $endCount = substr_count($original, self::END);
        $startLines = preg_match_all('/(?:^|[\r\n])' . preg_quote(self::START, '/') . '(?=[\r\n]|$)/', $original);
        $endLines = preg_match_all('/(?:^|[\r\n])' . preg_quote(self::END, '/') . '(?=[\r\n]|$)/', $original);
        if ($startCount !== $endCount || $startCount > 1 || $startLines !== $startCount || $endLines !== $endCount) {
            throw new \RuntimeException('robots.txt contains invalid/duplicate botWarden markers');
        }
        $rules = [];
        foreach ($userAgents as $agent) {
            if (!is_string($agent)) throw new \InvalidArgumentException('Invalid User-agent');
            $agent = trim($agent);
            if ($agent === '' || $agent === '*' || strlen($agent) > 512 || preg_match('/[\r\n\x00-\x1f]/', $agent)) {
                throw new \InvalidArgumentException('Unsafe User-agent');
            }
            $rules[$agent] = "User-agent: {$agent}\nDisallow: /";
        }
        if (!$rules) throw new \RuntimeException('Refusing to publish an empty robots.txt policy');
        $section = self::START . "\n" . implode("\n\n", array_values($rules)) . "\n" . self::END;
        if ($startCount === 1) {
            $start = strpos($original, self::START);
            $end = strpos($original, self::END, $start);
            if ($end === false || $end <= $start) throw new \RuntimeException('Invalid marker ordering');
            return substr($original, 0, $start) . $section . substr($original, $end + strlen(self::END));
        }
        $separator = $original === '' || preg_match('/(?:\n\r?\n|\r\r)$/D', $original)
            ? ''
            : (preg_match('/[\r\n]$/D', $original) ? "\n" : "\n\n");
        return $original . $separator . $section . "\n";
    }

    /** Returns the previous and new hashes for diagnostics. */
    public static function publish(string $path, array $userAgents, string $backupDirectory): array
    {
        return self::update($path, $userAgents, $backupDirectory);
    }

    public static function withdrawalPreview(string $path): array
    {
        if (is_link($path)) throw new \RuntimeException('Refusing to follow robots.txt symlink');
        $original = is_file($path) ? file_get_contents($path) : '';
        if ($original === false) throw new \RuntimeException('Cannot read robots.txt');
        return ['sha256' => hash('sha256', $original), 'content' => self::withoutPolicy($original)];
    }

    private static function withoutPolicy(string $original): string
    {
        self::render($original, ['ValidationOnly']);
        $start = strpos($original, self::START);
        if ($start === false) return $original;
        $end = strpos($original, self::END, $start) + strlen(self::END);
        return substr($original, 0, $start) . substr($original, $end);
    }

    public static function withdraw(string $path, string $backupDirectory, string $expectedHash): array
    {
        return self::update($path, [], $backupDirectory, $expectedHash);
    }

    private static function update(string $path, array $userAgents, string $backupDirectory, ?string $expectedHash = null): array
    {
        if (is_link($path)) throw new \RuntimeException('Refusing to follow robots.txt symlink');
        $preflight = is_file($path) ? file_get_contents($path) : '';
        if ($preflight === false) throw new \RuntimeException('Cannot read robots.txt');
        $preview = $expectedHash === null ? self::render($preflight, $userAgents) : self::withoutPolicy($preflight);
        if ($preview === $preflight && ($expectedHash === null || hash_equals(hash('sha256', $preflight), $expectedHash))) {
            return ['changed' => false, 'sha256' => hash('sha256', $preflight)];
        }
        if (!is_dir($backupDirectory) && !mkdir($backupDirectory, 0700, true) && !is_dir($backupDirectory)) {
            throw new \RuntimeException('Cannot create backup directory');
        }
        $lockPath = $backupDirectory . '/.robots.lock';
        if (is_link($lockPath)) throw new \RuntimeException('Refusing a lock symlink');
        $lock = fopen($lockPath, 'c');
        if ($lock === false) throw new \RuntimeException('Cannot open robots.txt lock');
        try {
        $deadline = microtime(true) + 2;
        while (!flock($lock, LOCK_EX | LOCK_NB)) {
            if (microtime(true) >= $deadline) throw new \RuntimeException('robots.txt publication is busy; retry later');
            usleep(10000);
        }
        $original = is_file($path) ? file_get_contents($path) : '';
        if ($original === false) throw new \RuntimeException('Cannot read robots.txt');
        if ($expectedHash !== null && !hash_equals(hash('sha256', $original), $expectedHash)) {
            throw new \RuntimeException('robots.txt changed since preview; preview again');
        }
        $updated = $expectedHash === null ? self::render($original, $userAgents) : self::withoutPolicy($original);
        if (hash_equals(hash('sha256', $original), hash('sha256', $updated))) {
            return ['changed' => false, 'sha256' => hash('sha256', $updated)];
        }
        if (!is_dir($backupDirectory) && !mkdir($backupDirectory, 0700, true) && !is_dir($backupDirectory)) {
            throw new \RuntimeException('Cannot create backup directory');
        }
        if (is_file($path)) {
            $backup = rtrim($backupDirectory, '/') . '/robots-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(5)) . '.txt';
            if (!copy($path, $backup)) throw new \RuntimeException('Could not back up robots.txt');
            @chmod($backup, 0600);
        }
        $temp = tempnam(dirname($path), '.botwarden-');
        if ($temp === false) throw new \RuntimeException('Cannot create robots.txt temporary file');
        try {
            if (file_put_contents($temp, $updated, LOCK_EX) !== strlen($updated)) {
                throw new \RuntimeException('Failed to write complete robots.txt');
            }
            if (file_exists($path)) @chmod($temp, fileperms($path) & 0777);
            else @chmod($temp, 0644);
            $current = is_file($path) ? file_get_contents($path) : '';
            if (is_link($path) || $current !== $original) {
                throw new \RuntimeException('robots.txt changed during publication; owner changes preserved');
            }
            if (!rename($temp, $path)) throw new \RuntimeException('Atomic rename failed');
        } finally {
            if (file_exists($temp)) @unlink($temp);
        }
        return ['changed' => true, 'sha256' => hash('sha256', $updated)];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
