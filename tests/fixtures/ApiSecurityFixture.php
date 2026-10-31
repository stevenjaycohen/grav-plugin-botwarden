<?php
declare(strict_types=1);

// CLI-only setup for the disposable, repository-local Grav integration fixture.
if (PHP_SAPI !== 'cli') exit(1);
$repo = dirname(__DIR__, 2);
$host = realpath($argv[2] ?? '');
$work = realpath($argv[3] ?? '');
if ($host === false || $work === false
    || !str_starts_with($host, $repo . '/') || !str_starts_with($work, $repo . '/')
    || $host !== realpath($repo . '/vendor/.botwarden-migration/grav')
    || $work !== realpath($repo . '/vendor/.botwarden-migration/security')) {
    throw new RuntimeException('Only the repository-local disposable fixture is allowed');
}
chdir($host);
define('GRAV_CLI', true);
define('GRAV_REQUEST_TIME', microtime(true));
$loader = require 'vendor/autoload.php';
$grav = Grav\Common\Grav::instance(['loader' => $loader]);
$grav->setup('migration-test')->initializeCli();
$accounts = $grav['accounts'];
$manager = new Grav\Plugin\Api\Auth\ApiKeyManager();
$names = ['super' => 'botwarden-security-super', 'user' => 'botwarden-security-user'];
$private = $work . '/credentials.json';
$operation = $argv[1] ?? '';

function savePrivate(string $path, array $value): void {
    $handle = fopen($path, 'x');
    if ($handle === false) throw new RuntimeException('Fixture credential path is occupied');
    chmod($path, 0600);
    try {
        $text = json_encode($value, JSON_THROW_ON_ERROR);
        if (fwrite($handle, $text) !== strlen($text) || !fflush($handle)) throw new RuntimeException('Incomplete fixture credential');
    } finally { fclose($handle); }
}

if ($operation === 'setup') {
    if (file_exists($private) || is_dir($host . '/user/data/botwarden')) throw new RuntimeException('Existing fixture data must be preserved');
    foreach ($names as $name) if ($accounts->load($name)->exists()) throw new RuntimeException('Fixture account already exists');
    $created = [];
    $keys = [];
    $sessions = [];
    $dataCreated = false;
    $credentials = ['fixture_id' => bin2hex(random_bytes(12)), 'accounts' => [], 'keys' => [], 'sessions' => []];
    try {
        foreach ($names as $kind => $name) {
            $password = bin2hex(random_bytes(20)) . '!aA7';
            $user = $accounts->load($name);
            $user->set('state', 'enabled');
            $user->set('email', $name . '@example.invalid');
            $user->set('access', ['site' => ['login' => true], 'api' => ['access' => true, 'super' => $kind === 'super']]);
            $user->set('hashed_password', Grav\Common\User\Authentication::create($password));
            $user->save();
            $created[] = $name;
            $credentials['accounts'][$kind] = ['username' => $name, 'password' => $password];
        }
        $definitions = [
            'limited' => ['super', ['api.access']],
            'explicit_super' => ['super', ['api.access', 'api.super']],
            'unscoped_super' => ['super', []],
            'non_super' => ['user', ['api.access', 'api.super']],
            'revocable' => ['super', ['api.access', 'api.super']],
        ];
        foreach ($definitions as $label => [$kind, $scopes]) {
            $result = $manager->generateKey($accounts->load($names[$kind]), 'Disposable botWarden authorization test', $scopes, 1);
            $keys[] = ['username' => $names[$kind], 'id' => $result['id'], 'label' => $label];
            $credentials['keys'][$label] = $result['key'];
        }
        $super = $credentials['accounts']['super'];
        $grav['config']->set('system.session.initialize', false);
        $user = $grav['login']->login($super, ['admin' => true, 'twofa' => false], ['authorize' => []]);
        if (!$user->authenticated || !$user->authorized) throw new RuntimeException('Real Login plugin rejected fixture credentials');
        $sessionPath = $grav['config']->get('system.session.options.save_path');
        if (realpath($sessionPath) !== realpath(dirname($work) . '/sessions')) throw new RuntimeException('Session storage must remain in the fixture');
        $sessionName = 'grav-site-' . substr(md5(GRAV_ROOT), 0, 7);
        foreach (['valid' => true, 'pending_two_factor' => false, 'logout' => true] as $label => $authorized) {
            $sessionUser = clone $user;
            $sessionUser->set('authenticated', true);
            $sessionUser->set('authorized', $authorized);
            session_name($sessionName);
            $id = bin2hex(random_bytes(20));
            session_id($id);
            if (!session_start(['save_path' => $sessionPath, 'use_cookies' => false, 'use_strict_mode' => false, 'cache_limiter' => ''])) throw new RuntimeException('Could not create native PHP session fixture');
            $_SESSION = ['user' => $sessionUser];
            session_write_close();
            $sessions[] = $sessionPath . '/sess_' . $id;
            $credentials['sessions'][$label] = $sessionName . '=' . $id;
        }
        $credentials['key_records'] = $keys;
        $credentials['session_files'] = $sessions;
        savePrivate($private, $credentials);
        $data = $host . '/user/data/botwarden';
        if (!mkdir($data, 0700)) throw new RuntimeException('Could not create disposable database directory');
        $dataCreated = true;
        file_put_contents($data . '/migration-complete.json', json_encode(['purpose' => 'authorization-fixture', 'fixture_id' => $credentials['fixture_id']], JSON_THROW_ON_ERROR));
        $db = Grav\Plugin\Botwarden\BotwardenDatabase::getInstance($data);
        $db->close();
        echo "Disposable authorization accounts, scoped keys and native PHP sessions prepared.\n";
    } catch (Throwable $error) {
        foreach ($keys as $key) $manager->revokeKey($accounts->load($key['username']), $key['id']);
        foreach ($created as $name) $accounts->delete($name);
        foreach ($sessions as $path) if (is_file($path)) unlink($path);
        if (is_file($private)) unlink($private);
        if ($dataCreated) {
            foreach (new DirectoryIterator($data) as $file) {
                if ($file->isDot()) continue;
                if (!$file->isFile() || $file->isLink()) throw new RuntimeException('Unexpected partial fixture path', 0, $error);
                unlink($file->getPathname());
            }
            rmdir($data);
        }
        throw $error;
    }
    exit;
}

if (!is_file($private)) throw new RuntimeException('Security fixture has not been prepared');
$credentials = json_decode(file_get_contents($private), true, 512, JSON_THROW_ON_ERROR);
if ($operation === 'cleanup') {
    foreach ($credentials['key_records'] as $key) {
        $owner = $accounts->load($key['username']);
        $current = $manager->loadKeys();
        if (isset($current[$key['id']]) && !$manager->revokeKey($owner, $key['id'])) throw new RuntimeException('Could not revoke fixture key');
    }
    foreach ($names as $name) if ($accounts->load($name)->exists() && !$accounts->delete($name)) throw new RuntimeException('Could not delete fixture account');
    foreach ($credentials['session_files'] as $path) if (is_file($path)) unlink($path);
    $data = $host . '/user/data/botwarden';
    $marker = json_decode(file_get_contents($data . '/migration-complete.json'), true, 512, JSON_THROW_ON_ERROR);
    if (($marker['fixture_id'] ?? '') !== $credentials['fixture_id']) throw new RuntimeException('Unexpected database fixture identity');
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($data, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $path) {
        if ($path->isLink()) throw new RuntimeException('Unexpected fixture symlink');
        $path->isDir() ? rmdir($path->getPathname()) : unlink($path->getPathname());
    }
    rmdir($data);
    unlink($private);
    echo "Authorization accounts, keys, native sessions and disposable database removed.\n";
    exit;
}

$user = $accounts->load($names['super']);
if (!$user->exists()) throw new RuntimeException('Fixture superuser is missing');
switch ($operation) {
    case 'revoke_super': $user->set('access.api.super', false); break;
    case 'restore_super': $user->set('access.api.super', true); break;
    case 'disable_super': $user->set('state', 'disabled'); break;
    case 'enable_super': $user->set('state', 'enabled'); break;
    case 'invalidate_sessions': $user->set('api_tokens_valid_after', time() + 5); break;
    case 'reset_cutoff': $user->set('api_tokens_valid_after', 0); break;
    case 'logout_session':
        [$sessionName, $id] = explode('=', $credentials['sessions']['logout'], 2);
        $path = $grav['config']->get('system.session.options.save_path');
        if (!in_array($path . '/sess_' . $id, $credentials['session_files'], true)) throw new RuntimeException('Unexpected logout fixture session');
        session_name($sessionName);
        session_id($id);
        if (!session_start(['save_path' => $path, 'use_cookies' => false, 'use_strict_mode' => false, 'cache_limiter' => ''])) throw new RuntimeException('Could not open logout fixture session');
        $_SESSION = [];
        if (!session_destroy()) throw new RuntimeException('Could not destroy fixture session');
        echo "Fixture session logged out.\n"; exit;
    case 'revoke_test_key':
        foreach ($credentials['key_records'] as $key) {
            if ($key['label'] === 'revocable' && !$manager->revokeKey($user, $key['id'])) throw new RuntimeException('Could not revoke dedicated test key');
        }
        echo "Fixture key revoked.\n"; exit;
    default: throw new RuntimeException('Unknown security fixture operation');
}
$user->save();
echo "Fixture account state updated.\n";
