<?php
namespace Grav\Plugin\Botwarden;

use SQLite3;

/**
 * GRVHulk -> botWarden opt-in migration. Never mutates or disables GRVHulk.
 *
 * Imports old config as disabled and takes a consistent SQLite snapshot using
 * SQLite3::backup (necessary with WAL). Refuses overwrites and rolls back
 * newly created destinations on failure.
 */
final class LegacyMigration
{
    public static function preview(string $userDir): array
    {
        $found = LegacyInstallationDetector::inspect($userDir);
        $found['migratable'] = ($found['config_readable'] || $found['database_readable']);
        $found['new_config_exists'] = is_file(rtrim($userDir, '/') . '/config/plugins/botwarden.yaml');
        $found['new_database_exists'] = is_file(rtrim($userDir, '/') . '/data/botwarden/botwarden.sqlite');
        $found['can_migrate'] = $found['migratable']
            && (!$found['config_present'] || $found['config_readable'])
            && (!$found['database_present'] || $found['database_readable'])
            && !$found['new_config_exists'] && !$found['new_database_exists'];
        return $found;
    }

    public static function migrate(string $userDir, bool $confirmed = false): array
    {
        if (!$confirmed) throw new \LogicException('Migration requires explicit confirmation');
        $status = self::preview($userDir);
        if (!$status['can_migrate']) throw new \RuntimeException('Migration not possible; see preview (missing legacy files or destination exists)');
        $root = rtrim($userDir, '/');
        $oldConfig = $root . '/config/plugins/grvhulk.yaml';
        $oldDb = $root . '/data/grvhulk/grvhulk.sqlite';
        $newConfig = $root . '/config/plugins/botwarden.yaml';
        $newDb = $root . '/data/botwarden/botwarden.sqlite';
        $backups = $root . '/data/botwarden/backups';
        if (!is_dir($backups) && !mkdir($backups, 0700, true) && !is_dir($backups)) {
            throw new \RuntimeException('Cannot create secure backup directory');
        }
        $created = [];
        $backupId = gmdate('Ymd-His') . '-' . bin2hex(random_bytes(6));
        try {
            if ($status['config_present']) {
                $input = file_get_contents($oldConfig);
                if ($input === false) throw new \RuntimeException('Could not read old configuration');
                if (!self::writeNewFile($backups . '/grvhulk-' . $backupId . '.yaml', $input)) {
                    throw new \RuntimeException('Failed to back up original configuration');
                }
                // Ensure the imported configuration does not activate the second blocker.
                if (preg_match('/^enabled\s*:/m', $input)) {
                    if (!preg_match('/^enabled\s*:\s*(?:true|false|0|1)\s*(?:#.*)?$/mi', $input)) {
                        throw new \RuntimeException('Unsupported enabled setting; migrate manually');
                    }
                    $input = preg_replace('/^enabled\s*:\s*(?:true|false|0|1)\s*(?:#.*)?$/mi',
                        'enabled: false', $input);
                } else {
                    $input = "enabled: false\n" . $input;
                }
                if (!is_dir(dirname($newConfig)) && !mkdir(dirname($newConfig), 0750, true) && !is_dir(dirname($newConfig))) {
                    throw new \RuntimeException('Cannot create configuration directory');
                }
                // Reserve destination exclusively to prevent concurrent migrations overwriting it.
                if (!self::writeNewFile($newConfig, $input)) throw new \RuntimeException('Cannot create new configuration');
                $created[] = $newConfig;
            } else {
                // A DB-only legacy installation still needs a disabled
                // botWarden override; otherwise bundled enabled:true would
                // activate two blockers immediately after importing the DB.
                if (!is_dir(dirname($newConfig))
                    && !mkdir(dirname($newConfig), 0750, true)
                    && !is_dir(dirname($newConfig))) {
                    throw new \RuntimeException('Cannot create configuration directory');
                }
                if (!self::writeNewFile($newConfig, "enabled: false\n")) {
                    throw new \RuntimeException('Cannot create disabled botWarden configuration');
                }
                $created[] = $newConfig;
            }
            if ($status['database_present']) {
                $source = new SQLite3($oldDb, SQLITE3_OPEN_READONLY);
                try {
                    $source->enableExceptions(true);
                    if ($source->querySingle('PRAGMA integrity_check') !== 'ok') {
                        throw new \RuntimeException('Legacy database integrity check failed');
                    }
                    $schema = (int)$source->querySingle('PRAGMA user_version');
                    if ($schema > 4) throw new \RuntimeException('Unsupported future GRVHulk database schema');
                    foreach (['local', 'abuseipdb_bulk'] as $table) {
                        $stmt = $source->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = :name");
                        $stmt->bindValue(':name', $table, SQLITE3_TEXT);
                        if (!$stmt->execute()->fetchArray(SQLITE3_NUM)) {
                            throw new \RuntimeException('Missing legacy table: ' . $table);
                        }
                    }
                    $backup = $backups . '/grvhulk-' . $backupId . '.sqlite';
                    if (file_exists($backup)) throw new \RuntimeException('Backup path already exists');
                    $backupDb = new SQLite3($backup, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
                    try {
                        if (!$source->backup($backupDb)) throw new \RuntimeException('Database snapshot failed');
                    } finally { $backupDb->close(); }
                    @chmod($backup, 0600);
                    // Reserve destination exclusively. copy() would overwrite a
                    // competing migration that created the path after the check.
                    $srcHandle = fopen($backup, 'rb');
                    $dstHandle = @fopen($newDb, 'x');
                    if ($srcHandle === false || $dstHandle === false) {
                        if (is_resource($srcHandle)) fclose($srcHandle);
                        throw new \RuntimeException('Could not reserve database destination');
                    }
                    $created[] = $newDb;
                    try {
                        $sourceBytes = filesize($backup);
                        $writtenBytes = stream_copy_to_stream($srcHandle, $dstHandle);
                        if ($writtenBytes === false || $writtenBytes !== $sourceBytes || !fflush($dstHandle)) {
                            throw new \RuntimeException('Could not install complete database snapshot');
                        }
                    } finally {
                        fclose($srcHandle);
                        fclose($dstHandle);
                    }
                    @chmod($newDb, 0600);
                    $verify = new SQLite3($newDb, SQLITE3_OPEN_READONLY);
                    try {
                        if ($verify->querySingle('PRAGMA integrity_check') !== 'ok') {
                            throw new \RuntimeException('Migrated database integrity check failed');
                        }
                    } finally { $verify->close(); }
                } finally { $source->close(); }
            }
            $marker = $root . '/data/botwarden/migration-complete.json';
            $metadata = json_encode(['source' => 'grvhulk', 'backup_id' => $backupId,
                'timestamp' => gmdate('c'), 'config_copied' => $status['config_present'],
                'database_copied' => $status['database_present']], JSON_THROW_ON_ERROR);
            if (!self::writeNewFile($marker, $metadata . "\n")) {
                throw new \RuntimeException('Cannot write migration marker');
            }
            $created[] = $marker;
            return ['success' => true, 'config_copied' => $status['config_present'],
                'database_copied' => $status['database_present'],
                'originals_preserved' => true, 'botwarden_enabled' => false,
                'backup_id' => $backupId];
        } catch (\Throwable $error) {
            foreach (array_reverse($created) as $file) @unlink($file);
            throw $error;
        }
    }

    private static function writeNewFile(string $path, string $contents): bool
    {
        $handle = @fopen($path, 'x');
        if ($handle === false) return false;
        try {
            @chmod($path, 0600);
            $length = strlen($contents);
            $offset = 0;
            while ($offset < $length) {
                $written = fwrite($handle, substr($contents, $offset));
                if ($written === false || $written === 0) throw new \RuntimeException('Short write on new file');
                $offset += $written;
            }
            if (!fflush($handle)) throw new \RuntimeException('Could not flush new file');
            return true;
        } catch (\Throwable $error) {
            @unlink($path);
            throw $error;
        } finally {
            fclose($handle);
        }
    }
}
