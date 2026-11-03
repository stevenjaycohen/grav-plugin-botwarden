<?php
namespace Grav\Plugin\Botwarden\Tests;

use Grav\Plugin\BotwardenPlugin;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class NormalizeAgentTypesTest extends TestCase
{
    private static array $defaults = [
        'AI Assistant',
        'AI Data Scraper',
        'AI Search Crawler',
        'Undocumented AI Agent',
    ];

    private function normalize(mixed $input): array
    {
        $ref = new ReflectionClass(BotwardenPlugin::class);
        $method = $ref->getMethod('normalizeAgentTypes');
        $method->setAccessible(true);
        return $method->invoke(null, $input);
    }

    public function testEmptyArrayReturnsDefaults(): void
    {
        $this->assertSame(self::$defaults, $this->normalize([]));
    }

    public function testNullReturnsDefaults(): void
    {
        $this->assertSame(self::$defaults, $this->normalize(null));
    }

    public function testAssocMapWithSomeTrueFiltersSelected(): void
    {
        $input = [
            'AI Assistant'          => true,
            'AI Data Scraper'       => false,
            'AI Search Crawler'     => true,
            'Undocumented AI Agent' => false,
        ];
        $this->assertSame(['AI Assistant', 'AI Search Crawler'], $this->normalize($input));
    }

    public function testAssocMapAllFalseReturnsDefaults(): void
    {
        $input = [
            'AI Assistant'          => false,
            'AI Data Scraper'       => false,
            'AI Search Crawler'     => false,
            'Undocumented AI Agent' => false,
        ];
        $this->assertSame(self::$defaults, $this->normalize($input));
    }

    public function testSequentialArrayPassedThrough(): void
    {
        $input = ['AI Assistant', 'AI Data Scraper'];
        $this->assertSame($input, $this->normalize($input));
    }

    public function testAllFourDefaultsAsSequentialArray(): void
    {
        $this->assertSame(self::$defaults, $this->normalize(self::$defaults));
    }

    public function testSingleEntryAssocMap(): void
    {
        $this->assertSame(['AI Search Crawler'], $this->normalize(['AI Search Crawler' => true]));
    }

    public function testDataProviderCanBeSelectedAloneWithoutEnablingOtherCategories(): void
    {
        $this->assertSame(['AI Data Provider'], $this->normalize(['AI Data Provider']));
        $this->assertSame(['AI Data Provider'], $this->normalize([
            'AI Assistant' => false, 'AI Data Scraper' => false, 'AI Data Provider' => true,
            'AI Search Crawler' => false, 'Undocumented AI Agent' => false,
        ]));
    }

    public function testDataProviderCanBeDisabledIndependently(): void
    {
        $this->assertSame(['AI Data Scraper', 'Undocumented AI Agent'], $this->normalize([
            'AI Assistant' => false, 'AI Data Scraper' => true, 'AI Data Provider' => false,
            'AI Search Crawler' => false, 'Undocumented AI Agent' => true,
        ]));
        $this->assertSame(self::$defaults, $this->normalize(array_fill_keys(
            array_merge(self::$defaults, ['AI Data Provider']), false)));
    }
}
