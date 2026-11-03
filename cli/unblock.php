#!/usr/bin/env php
<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

/**
 * botWarden — Emergency IP unblock tool
 *
 * Run from your Grav root when you have locked yourself out of the admin UI:
 *
 *   php user/plugins/botwarden/cli/unblock.php 203.0.113.5
 *
 * Options:
 *   --list         Show the last 25 IPs in the local blacklist
 *   --whitelist    Also add the IP to botwarden.yaml whitelist (persistent)
 *   --db=PATH      Override the path to botwarden.sqlite
 */

// ── Argument parsing ──────────────────────────────────────────────────────────
// PHP's getopt() stops at the first non-option argument, so we parse manually.

$ip     = null;
$list   = false;
$addWl  = false;
$dbPath = null;

for ($i = 1; $i < $argc; $i++) {
    $arg = $argv[$i];
    if ($arg === '--list') {
        $list = true;
    } elseif ($arg === '--whitelist') {
        $addWl = true;
    } elseif (str_starts_with($arg, '--db=')) {
        $dbPath = substr($arg, 5);
    } elseif ($arg === '--db' && isset($argv[$i + 1])) {
        $dbPath = $argv[++$i];
    } elseif (!str_starts_with($arg, '-')) {
        $ip = $arg;
    }
}

if (!$list && !$ip) {
    fwrite(STDERR, <<<'USAGE'
botWarden emergency unblock

Usage:
  php cli/unblock.php <ip>             Remove IP from local blacklist
  php cli/unblock.php --list           Show last 25 blocked IPs
  php cli/unblock.php <ip> --whitelist Also add IP to botwarden.yaml whitelist

Options:
  --db=PATH   Override path to botwarden.sqlite

USAGE);
    exit(1);
}

// ── Locate the database ───────────────────────────────────────────────────────

if (!$dbPath) {
    // When running as: php user/plugins/botwarden/cli/unblock.php
    // __DIR__ = [grav-root]/user/plugins/botwarden/cli
    $candidate = __DIR__ . '/../../../data/botwarden/botwarden.sqlite';
    if (file_exists($candidate)) {
        $dbPath = realpath($candidate);
    }
}

if ((!$dbPath || !file_exists($dbPath)) && (!$addWl || $list)) {
    fwrite(STDERR, "Error: cannot find botwarden.sqlite.\n");
    fwrite(STDERR, "Run from Grav root, or pass --db=/path/to/botwarden.sqlite\n");
    exit(1);
}

// Load Grav's existing YAML support; development dependencies are not required.
$autoload = __DIR__ . '/../../../../vendor/autoload.php';
if (is_file($autoload)) require_once $autoload;
require_once __DIR__ . '/../classes/BotwardenDatabase.php';
$db = null;
if ($dbPath && is_file($dbPath)) {
    $db = new SQLite3($dbPath);
    $db->enableExceptions(true);
    $db->busyTimeout(5000);
}

// ── --list ────────────────────────────────────────────────────────────────────

if ($list) {
    $rows = $db->query(
        'SELECT ip, reason, datetime(added_at, \'unixepoch\', \'localtime\') AS added
         FROM local ORDER BY rowid DESC LIMIT 25'
    );
    $found = false;
    printf("%-18s %-30s %s\n", 'IP', 'Reason', 'Added');
    echo str_repeat('-', 70) . "\n";
    while ($row = $rows->fetchArray(SQLITE3_ASSOC)) {
        printf("%-18s %-30s %s\n", $row['ip'], $row['reason'] ?: '—', $row['added']);
        $found = true;
    }
    if (!$found) {
        echo "(Local blacklist is empty)\n";
    }
    $db->close();
    exit(0);
}

// ── Validate IP ───────────────────────────────────────────────────────────────

if (!filter_var($ip, FILTER_VALIDATE_IP)) {
    fwrite(STDERR, "Error: \"$ip\" is not a valid IP address.\n");
    exit(1);
}

// ── Remove from local blacklist ───────────────────────────────────────────────

$removed = 0;
if ($db !== null) {
    $existing = \Grav\Plugin\Botwarden\BotwardenDatabase::searchLocal($db, $ip);
    \Grav\Plugin\Botwarden\BotwardenDatabase::removeIpFromLocal($db, $ip);
    $removed = $existing !== null ? 1 : 0;
    $db->close();
}

if ($removed > 0) {
    echo "✓ Removed $ip from local blacklist.\n";
} elseif ($db === null) {
    echo "Info: no local database found; continuing with whitelist-only recovery.\n";
} else {
    echo "Info: $ip was not in the local blacklist.\n";
}

// ── --whitelist: append to botwarden.yaml ──────────────────────────────────────

if ($addWl) {
    $yamlPath = __DIR__ . '/../../../config/plugins/botwarden.yaml';
    try {
        if (is_link($yamlPath)) throw new \RuntimeException('Refusing a configuration symlink');
        $yaml = is_file($yamlPath) ? file_get_contents($yamlPath) : '';
        if ($yaml === false) throw new \RuntimeException('Cannot read the configuration');
        $updated = appendToWhitelist($yaml, $ip);
        if ($updated !== $yaml) {
            if (!is_dir(dirname($yamlPath)) && !mkdir(dirname($yamlPath), 0775, true)) {
                throw new \RuntimeException('Cannot create the configuration directory');
            }
            if (!is_writable(dirname($yamlPath))) throw new \RuntimeException('Configuration directory is not writable');
            $temporary = tempnam(dirname($yamlPath), '.botwarden-whitelist-');
            if ($temporary === false) throw new \RuntimeException('Cannot create temporary configuration');
            try {
                if (file_put_contents($temporary, $updated, LOCK_EX) !== strlen($updated)) {
                    throw new \RuntimeException('Cannot write the complete whitelist configuration');
                }
                if (!chmod($temporary, is_file($yamlPath) ? fileperms($yamlPath) & 0777 : 0640)) {
                    throw new \RuntimeException('Cannot preserve configuration permissions');
                }
                if (is_link($yamlPath) || (is_file($yamlPath) ? file_get_contents($yamlPath) : '') !== $yaml) {
                    throw new \RuntimeException('Configuration changed during recovery; retry');
                }
                if (!rename($temporary, $yamlPath)) throw new \RuntimeException('Cannot replace configuration');
            } finally {
                if (is_file($temporary)) unlink($temporary);
            }
        }
        echo "✓ $ip is whitelisted in $yamlPath\n";
        echo "Run php bin/grav cache from the Grav root before retrying access.\n";
        echo "Remove the temporary whitelist entry when recovery is complete.\n";
        exit(0);
    } catch (\Throwable $error) {
        fwrite(STDERR, "Whitelist failed: " . $error->getMessage() . "\n");
        if ($removed) fwrite(STDERR, "Partial recovery: local block removed; whitelist update failed.\n");
        exit(1);
    }
}

exit($removed > 0 ? 0 : 1);

/**
 * Merge the whitelist using Grav's YAML parser while retaining unrelated text.
 */
function appendToWhitelist(string $yaml, string $ip): string
{
    $parsed = \Symfony\Component\Yaml\Yaml::parse($yaml) ?? [];
    if (!is_array($parsed)) throw new \RuntimeException('Configuration must be a YAML mapping');
    $whitelist = $parsed['whitelist'] ?? [];
    if (is_string($whitelist)) $whitelist = preg_split('/\r\n|\r|\n/', $whitelist) ?: [];
    if (!is_array($whitelist)) throw new \RuntimeException('Whitelist must be a list or multiline string');
    $whitelist = array_values(array_filter(array_map('trim', $whitelist),
        static fn(string $entry): bool => $entry !== '' && !str_starts_with($entry, '#')));
    if (in_array($ip, $whitelist, true)) return $yaml;
    $whitelist[] = $ip;
    $replacement = \Symfony\Component\Yaml\Yaml::dump(['whitelist' => $whitelist], 2, 2);
    if (str_contains($yaml, "\r\n")) $replacement = str_replace("\n", "\r\n", $replacement);
    // Replace only this top-level key, keeping unrelated text and comments.
    if (preg_match('/^whitelist:[^\r\n]*(?:\r?\n(?:[ \t]+[^\r\n]*|))*/m', $yaml, $match, PREG_OFFSET_CAPTURE)) {
        [$block, $offset] = $match[0];
        $ending = str_ends_with($block, "\n") ? (str_contains($yaml, "\r\n") ? "\r\n" : "\n") : '';
        $updated = substr_replace($yaml, rtrim($replacement, "\r\n") . $ending, $offset, strlen($block));
    } else {
        $updated = $yaml . ($yaml === '' || str_ends_with($yaml, "\n") ? '' : "\n") . $replacement;
    }
    $check = \Symfony\Component\Yaml\Yaml::parse($updated);
    $parsed['whitelist'] = $whitelist;
    if ($check !== $parsed) throw new \RuntimeException('Whitelist merge would change unrelated configuration');
    return $updated;
}
