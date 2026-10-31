#!/usr/bin/env php
<?php
/**
 * botWarden GRVHulk migration. Preview by default; --confirm actually imports.
 * Usage from Grav root:
 * php user/plugins/botwarden/cli/migrate.php
 * php user/plugins/botwarden/cli/migrate.php --confirm
 */
require_once dirname(__DIR__) . '/classes/LegacyInstallationDetector.php';
require_once dirname(__DIR__) . '/classes/LegacyMigration.php';

use Grav\Plugin\Botwarden\LegacyMigration;

$userDir = dirname(__DIR__, 3);
$confirm = in_array('--confirm', $argv, true);
if (count(array_diff(array_slice($argv, 1), ['--confirm'])) > 0) {
    fwrite(STDERR, "Usage: php user/plugins/botwarden/cli/migrate.php [--confirm]\n");
    exit(2);
}
try {
    $result = $confirm ? LegacyMigration::migrate($userDir, true) : LegacyMigration::preview($userDir);
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, "[botWarden] Migration failed: " . $e->getMessage() . "\n");
    exit(1);
}
