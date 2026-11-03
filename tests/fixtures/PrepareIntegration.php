<?php
declare(strict_types=1);

// CI-only preparation of the release ZIP's disposable Grav installation.
if (PHP_SAPI !== 'cli' || getenv('CI') !== 'true') {
    throw new RuntimeException('This fixture initializer is restricted to CI');
}
$repo = dirname(__DIR__, 2);
$base = $repo . '/vendor/.botwarden-migration';
$host = $base . '/grav';
if (realpath($host) !== $host || !is_file($host . '/bin/grav')
    || is_file($base . '/integration-prepared.json')
    || is_dir($host . '/user/data/botwarden') || is_dir($host . '/user/data/grvhulk')
    || is_file($host . '/user/config/plugins/grvhulk.yaml') || glob($host . '/user/accounts/*.yaml')) {
    throw new RuntimeException('Expected a fresh repository-local release ZIP installation');
}
foreach (['sessions', 'tmp', 'security'] as $directory) {
    if (!is_dir($base . '/' . $directory)) mkdir($base . '/' . $directory, 0700);
}
$files = [
    'config/system.yaml' => ['cache' => ['enabled' => false], 'debugger' => ['enabled' => false],
        'pages' => ['theme' => 'migration-fixture'], 'session' => ['options' => ['save_path' => $base . '/sessions']]],
    'config/plugins/admin2.yaml' => ['enabled' => true, 'route' => '/admin'],
    'config/plugins/api.yaml' => ['enabled' => true],
    'config/plugins/grvhulk.yaml' => ['enabled' => false, 'sources' => ['local' => true]],
    'themes/migration-fixture/blueprints.yaml' => ['name' => 'Migration fixture', 'slug' => 'migration-fixture', 'type' => 'theme', 'version' => '1.0.0'],
    'themes/migration-fixture/migration-fixture.yaml' => ['enabled' => true],
];
// Grav derives its root while Composer loads system/defines.php.
// Enter the fixture before loading any Grav classes or constants.
chdir($host);
define('GRAV_CLI', true);
define('GRAV_REQUEST_TIME', microtime(true));
$loader = require $host . '/vendor/autoload.php';
foreach ($files as $path => $content) {
    $path = $host . '/user/' . $path;
    if (!is_dir(dirname($path))) mkdir(dirname($path), 0750, true);
    file_put_contents($path, Symfony\Component\Yaml\Yaml::dump($content, 6));
}
$theme = $host . '/user/themes/migration-fixture/templates';
mkdir($theme, 0750, true);
file_put_contents($theme . '/default.html.twig', '<!doctype html><html><head><title>{{ page.title }}</title></head><body>{{ page.content|raw }}</body></html>');
// Keep every request on a synthetic page, independent of the downloaded demo.
Grav\Common\Filesystem\Folder::delete($host . '/user/pages');
mkdir($host . '/user/pages/01.home', 0750, true);
file_put_contents($host . '/user/pages/01.home/default.md', "---\ntitle: Synthetic botWarden fixture\n---\nSynthetic botWarden migration test site.\n");
mkdir($host . '/user/data/grvhulk', 0700, true);
$db = new SQLite3($host . '/user/data/grvhulk/grvhulk.sqlite');
$db->enableExceptions(true);
$db->exec(file_get_contents(__DIR__ . '/grvhulk-1.1.sql'));
$db->close();
$grav = Grav\Common\Grav::instance(['loader' => $loader]);
$grav->setup('migration-test')->initializeCli();
$account = $grav['accounts']->load('botwarden-ci-owner');
if ($account->exists()) throw new RuntimeException('Fixture owner already exists');
$account->set('state', 'enabled');
$account->set('email', 'botwarden-ci-owner@example.invalid');
$account->set('hashed_password', password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT));
$account->set('access', ['admin' => ['login' => true, 'super' => true], 'api' => ['access' => true, 'super' => true]]);
$account->save();
Grav\Common\Cache::clearCache('cache-only');
file_put_contents($base . '/integration-prepared.json', json_encode(['purpose' => 'CI synthetic botWarden integration']));
echo "Synthetic release-ZIP integration fixture prepared.\n";
