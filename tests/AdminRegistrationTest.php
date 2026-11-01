<?php
namespace Grav\Plugin\Botwarden\Tests;

use Grav\Plugin\BotwardenPlugin;
use PHPUnit\Framework\TestCase;
use RocketTheme\Toolbox\Event\Event;

final class AdminRegistrationTest extends TestCase
{
    public function testPluginSubscribesToGrav2Events(): void
    {
        $events = BotwardenPlugin::getSubscribedEvents();
        foreach (['onApiRegisterRoutes', 'onApiSidebarItems', 'onApiPluginPageInfo', 'onPluginsInitialized'] as $name) {
            $this->assertArrayHasKey($name, $events);
        }
        $this->assertArrayNotHasKey('onAdminMenu', $events);
    }

    public function testSidebarRegistrationUsesBotwardenSlugAndPermission(): void
    {
        $plugin = new BotwardenPlugin();
        $event = new Event(['items' => []]);
        $plugin->onApiSidebarItems($event);
        $this->assertSame('/plugin/botwarden', $event['items'][0]['route']);
        $this->assertSame('api.super', $event['items'][0]['authorize']);
    }

    public function testOnlyThisPluginDefinesItsPage(): void
    {
        $plugin = new BotwardenPlugin();
        $other = new Event(['plugin' => 'unrelated']);
        $plugin->onApiPluginPageInfo($other);
        $this->assertFalse(isset($other['definition']));

        $self = new Event(['plugin' => 'botwarden']);
        $plugin->onApiPluginPageInfo($self);
        $this->assertSame('component', $self['definition']['page_type']);
    }

    public function testApiRoutesAreRegistered(): void
    {
        $routes = new class {
            public array $routes = [];
            public function get(string $path, array $handler): void { $this->routes[] = ['GET', $path, $handler]; }
            public function post(string $path, array $handler): void { $this->routes[] = ['POST', $path, $handler]; }
        };
        $plugin = new BotwardenPlugin();
        $event = new Event(['routes' => $routes]);
        $plugin->onApiRegisterRoutes($event);
        $paths = array_map(static fn($route) => $route[1], $routes->routes);
        $this->assertContains('/botwarden/status', $paths);
        $this->assertContains('/botwarden/legacy', $paths);
        $this->assertContains('/botwarden/migrate', $paths);
        $this->assertContains('/botwarden/unblock', $paths);
        $this->assertContains('/botwarden/known-agents/connect', $paths);
    }
}
