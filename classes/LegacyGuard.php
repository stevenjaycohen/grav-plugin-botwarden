<?php
namespace Grav\Plugin\Botwarden;

/** Prevent two concurrent blockers and preserve legacy data for migration. */
final class LegacyGuard
{
    public static function mustDefer(string $userDirectory): bool
    {
        if (!LegacyInstallationDetector::inspect($userDirectory)['detected']) return false;
        return !is_file(rtrim($userDirectory, '/') . '/data/botwarden/migration-complete.json');
    }
}
