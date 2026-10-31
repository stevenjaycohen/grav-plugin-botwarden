<?php
namespace Grav\Plugin\Grvhulk;

/**
 * Read-only discovery of an existing GRVHulk installation.
 *
 * No files are written, migrated, or disabled. This is preliminary
 * groundwork for botWarden's opt-in migration assistant.
 */
final class LegacyInstallationDetector
{
    /**
     * @return array{detected:bool,plugin_present:bool,config_present:bool,database_present:bool,config_readable:bool,database_readable:bool}
     */
    public static function inspect(string $userDirectory): array
    {
        if (trim($userDirectory) === '') {
            throw new \InvalidArgumentException('User directory cannot be empty');
        }
        $root = rtrim($userDirectory, '/\\');
        $plugin = $root . '/plugins/grvhulk';
        $config = $root . '/config/plugins/grvhulk.yaml';
        $database = $root . '/data/grvhulk/grvhulk.sqlite';

        $pluginPresent = is_dir($plugin);
        $configPresent = is_file($config);
        $databasePresent = is_file($database);
        return [
            'detected' => $pluginPresent || $configPresent || $databasePresent,
            'plugin_present' => $pluginPresent,
            'config_present' => $configPresent,
            'database_present' => $databasePresent,
            'config_readable' => $configPresent && is_readable($config),
            'database_readable' => $databasePresent && is_readable($database),
        ];
    }
}
