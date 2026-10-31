<?php
namespace Grav\Plugin\Botwarden\Tests;

use Grav\Plugin\Botwarden\BotwardenDatabase;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use SQLite3;

class BotwardenDatabaseTest extends TestCase
{
    private SQLite3 $db;
    private string $tempDir;

    protected function setUp(): void
    {
        // Reset the singleton so each test gets a fresh database
        $ref = new ReflectionClass(BotwardenDatabase::class);
        $prop = $ref->getProperty('instance');
        $prop->setAccessible(true);
        $prop->setValue(null, null);

        $this->tempDir = sys_get_temp_dir() . '/botwarden_test_' . uniqid();
        mkdir($this->tempDir, 0777, true);
        $this->db = BotwardenDatabase::getInstance($this->tempDir);
    }

    protected function tearDown(): void
    {
        // Reset singleton so we don't bleed state between tests
        $ref = new ReflectionClass(BotwardenDatabase::class);
        $prop = $ref->getProperty('instance');
        $prop->setAccessible(true);
        $prop->setValue(null, null);

        $this->db->close();

        // Remove temp files
        $dbFile = $this->tempDir . '/botwarden.sqlite';
        foreach (glob($dbFile . '*') as $f) {
            @unlink($f);
        }
        @rmdir($this->tempDir);
    }

    // ── Local blacklist ──────────────────────────────────────────────────────

    public function testAddAndCheckIpInLocal(): void
    {
        $this->assertFalse(BotwardenDatabase::isIpInLocal($this->db, '1.2.3.4'));
        BotwardenDatabase::addIpToLocal($this->db, '1.2.3.4', 'test');
        $this->assertTrue(BotwardenDatabase::isIpInLocal($this->db, '1.2.3.4'));
    }

    public function testLocalTtlExpiry(): void
    {
        // Manually insert an entry with a past timestamp to simulate expiry
        $this->db->exec("INSERT INTO local (ip, reason, added_at) VALUES ('9.9.9.9', 'old', " . (time() - 7200) . ")");
        // TTL=3600 (1h): entry is 2h old → expired
        $this->assertFalse(BotwardenDatabase::isIpInLocal($this->db, '9.9.9.9', 3600));
        // TTL=0 (permanent): same entry is still found
        $this->assertTrue(BotwardenDatabase::isIpInLocal($this->db, '9.9.9.9', 0));
    }

    public function testPruneExpiredLocal(): void
    {
        $this->db->exec("INSERT INTO local (ip, reason, added_at) VALUES ('1.1.1.1', 'old', " . (time() - 7200) . ")");
        BotwardenDatabase::addIpToLocal($this->db, '2.2.2.2', 'fresh');
        // Prune with TTL=3600: only the 2h-old entry should be removed
        $removed = BotwardenDatabase::pruneExpiredLocal($this->db, 3600);
        $this->assertSame(1, $removed);
        $this->assertFalse(BotwardenDatabase::isIpInLocal($this->db, '1.1.1.1', 0));
        $this->assertTrue(BotwardenDatabase::isIpInLocal($this->db, '2.2.2.2', 0));
    }

    public function testRemoveIpFromLocal(): void
    {
        BotwardenDatabase::addIpToLocal($this->db, '1.2.3.4');
        BotwardenDatabase::removeIpFromLocal($this->db, '1.2.3.4');
        $this->assertFalse(BotwardenDatabase::isIpInLocal($this->db, '1.2.3.4'));
    }

    public function testAddDuplicateIpIsIgnored(): void
    {
        BotwardenDatabase::addIpToLocal($this->db, '1.2.3.4', 'first');
        BotwardenDatabase::addIpToLocal($this->db, '1.2.3.4', 'second');
        $this->assertSame(1, BotwardenDatabase::getLocalCount($this->db));
    }

    public function testGetLocalCount(): void
    {
        $this->assertSame(0, BotwardenDatabase::getLocalCount($this->db));
        BotwardenDatabase::addIpToLocal($this->db, '1.1.1.1');
        BotwardenDatabase::addIpToLocal($this->db, '2.2.2.2');
        $this->assertSame(2, BotwardenDatabase::getLocalCount($this->db));
    }

    public function testGetLastNLocal(): void
    {
        BotwardenDatabase::addIpToLocal($this->db, '1.1.1.1', 'first');
        BotwardenDatabase::addIpToLocal($this->db, '2.2.2.2', 'second');
        $rows = BotwardenDatabase::getLastNLocal($this->db, 25);
        $this->assertCount(2, $rows);
        // Most recent row first (ORDER BY rowid DESC)
        $this->assertSame('2.2.2.2', $rows[0]['ip']);
        $this->assertSame('second', $rows[0]['reason']);
    }

    public function testSearchLocalFound(): void
    {
        BotwardenDatabase::addIpToLocal($this->db, '5.6.7.8', 'scan');
        $entry = BotwardenDatabase::searchLocal($this->db, '5.6.7.8');
        $this->assertNotNull($entry);
        $this->assertSame('5.6.7.8', $entry['ip']);
        $this->assertSame('scan', $entry['reason']);
    }

    public function testSearchLocalNotFound(): void
    {
        $this->assertNull(BotwardenDatabase::searchLocal($this->db, '9.9.9.9'));
    }

    // ── IP cache ─────────────────────────────────────────────────────────────

    public function testSetAndGetCachedDecision(): void
    {
        BotwardenDatabase::setCachedDecision($this->db, '1.2.3.4', 'crowdsec_cti', 'block', 90, 300);
        $cached = BotwardenDatabase::getCachedDecision($this->db, '1.2.3.4', 'crowdsec_cti');
        $this->assertNotNull($cached);
        $this->assertSame('block', $cached['decision']);
        $this->assertSame(90, (int)$cached['score']);
    }

    public function testCachedDecisionReturnsNullWhenMissing(): void
    {
        $this->assertNull(BotwardenDatabase::getCachedDecision($this->db, '99.99.99.99', 'crowdsec_cti'));
    }

    public function testExpiredCacheReturnsNull(): void
    {
        // TTL = -1 means already expired
        BotwardenDatabase::setCachedDecision($this->db, '1.2.3.4', 'crowdsec_cti', 'block', 5, -1);
        $this->assertNull(BotwardenDatabase::getCachedDecision($this->db, '1.2.3.4', 'crowdsec_cti'));
    }

    public function testCacheSeparatedBySource(): void
    {
        BotwardenDatabase::setCachedDecision($this->db, '1.2.3.4', 'crowdsec_cti', 'block', 80, 300);
        BotwardenDatabase::setCachedDecision($this->db, '1.2.3.4', 'crowdsec_lapi', 'clean', 0, 300);

        $a = BotwardenDatabase::getCachedDecision($this->db, '1.2.3.4', 'crowdsec_cti');
        $c = BotwardenDatabase::getCachedDecision($this->db, '1.2.3.4', 'crowdsec_lapi');

        $this->assertSame('block', $a['decision']);
        $this->assertSame('clean', $c['decision']);
    }

    // ── Metadata ─────────────────────────────────────────────────────────────

    public function testSetAndGetMeta(): void
    {
        BotwardenDatabase::setMeta($this->db, 'test_key', 'hello');
        $this->assertSame('hello', BotwardenDatabase::getMeta($this->db, 'test_key'));
    }

    public function testGetMetaReturnsNullWhenMissing(): void
    {
        $this->assertNull(BotwardenDatabase::getMeta($this->db, 'nonexistent'));
    }

    public function testSetMetaOverwritesExistingValue(): void
    {
        BotwardenDatabase::setMeta($this->db, 'k', 'v1');
        BotwardenDatabase::setMeta($this->db, 'k', 'v2');
        $this->assertSame('v2', BotwardenDatabase::getMeta($this->db, 'k'));
    }

    // ── Dark Visitors agents ─────────────────────────────────────────────────

    public function testDarkVisitorsAgentBlocked(): void
    {
        BotwardenDatabase::replaceDarkVisitorsAgents($this->db, ['GPTBot', 'ClaudeBot']);
        $this->assertTrue(BotwardenDatabase::isDarkVisitorsAgentBlocked($this->db, 'Mozilla/5.0 GPTBot/1.0'));
    }

    public function testDarkVisitorsAgentNotBlocked(): void
    {
        BotwardenDatabase::replaceDarkVisitorsAgents($this->db, ['GPTBot']);
        $this->assertFalse(BotwardenDatabase::isDarkVisitorsAgentBlocked($this->db, 'Mozilla/5.0 (compatible; Googlebot)'));
    }

    public function testDarkVisitorsAgentCaseInsensitive(): void
    {
        BotwardenDatabase::replaceDarkVisitorsAgents($this->db, ['GPTBot']);
        $this->assertTrue(BotwardenDatabase::isDarkVisitorsAgentBlocked($this->db, 'Mozilla/5.0 gptbot/1.0'));
    }

    public function testDarkVisitorsEmptyUaReturnsFalse(): void
    {
        BotwardenDatabase::replaceDarkVisitorsAgents($this->db, ['GPTBot']);
        $this->assertFalse(BotwardenDatabase::isDarkVisitorsAgentBlocked($this->db, ''));
    }

    public function testDarkVisitorsGetCount(): void
    {
        $this->assertSame(0, BotwardenDatabase::getDarkVisitorsCount($this->db));
        BotwardenDatabase::replaceDarkVisitorsAgents($this->db, ['GPTBot', 'ClaudeBot', 'PerplexityBot']);
        $this->assertSame(3, BotwardenDatabase::getDarkVisitorsCount($this->db));
    }

    public function testReplaceAgentsClearsOldEntries(): void
    {
        BotwardenDatabase::replaceDarkVisitorsAgents($this->db, ['OldBot']);
        BotwardenDatabase::replaceDarkVisitorsAgents($this->db, ['NewBot']);
        $this->assertFalse(BotwardenDatabase::isDarkVisitorsAgentBlocked($this->db, 'OldBot'));
        $this->assertTrue(BotwardenDatabase::isDarkVisitorsAgentBlocked($this->db, 'NewBot'));
    }

    public function testDarkVisitorsBackslashEscaping(): void
    {
        // A stored agent containing a literal backslash must still match the same
        // literal in an incoming UA. The escape character has to be escaped first,
        // otherwise '\B' would be consumed as an escape and the match would fail.
        BotwardenDatabase::replaceDarkVisitorsAgents($this->db, ['Bad\\Bot']);
        $this->assertTrue(BotwardenDatabase::isDarkVisitorsAgentBlocked($this->db, 'Mozilla Bad\\Bot/1.0'));
        $this->assertFalse(BotwardenDatabase::isDarkVisitorsAgentBlocked($this->db, 'Mozilla BadBot/1.0'));
    }

    // ── Manual vs auto local entries ─────────────────────────────────────────

    public function testManualEntryNotExpiredByTtl(): void
    {
        // Manual entry with an old timestamp must still be enforced and not pruned.
        $this->db->exec("INSERT INTO local (ip, reason, is_manual, added_at) VALUES ('7.7.7.7', 'manual', 1, " . (time() - 7200) . ")");
        $this->assertTrue(BotwardenDatabase::isIpInLocal($this->db, '7.7.7.7', 3600));
        $removed = BotwardenDatabase::pruneExpiredLocal($this->db, 3600);
        $this->assertSame(0, $removed);
        $this->assertTrue(BotwardenDatabase::isIpInLocal($this->db, '7.7.7.7', 0));
    }

    public function testManualEntryNotTrimmed(): void
    {
        $this->db->exec("INSERT INTO local (ip, reason, is_manual, added_at) VALUES ('9.9.9.9', 'manual', 1, " . (time() - 100) . ")");
        BotwardenDatabase::addIpToLocal($this->db, '1.1.1.1', 'auto');
        BotwardenDatabase::addIpToLocal($this->db, '2.2.2.2', 'auto');
        // Trim auto entries down to 1; the manual entry is always kept.
        BotwardenDatabase::trimLocal($this->db, 1);
        $this->assertTrue(BotwardenDatabase::isIpInLocal($this->db, '9.9.9.9'));
        $this->assertSame(2, BotwardenDatabase::getLocalCount($this->db)); // manual + 1 auto
    }

    public function testManualAddViaAddIpToLocal(): void
    {
        BotwardenDatabase::addIpToLocal($this->db, '3.3.3.3', 'manual', true);
        $row = BotwardenDatabase::searchLocal($this->db, '3.3.3.3');
        $this->assertSame(1, (int)$row['is_manual']);
    }

    public function testManualAddPromotesExistingAutoEntry(): void
    {
        // An auto entry that is later added manually must be promoted, not ignored.
        BotwardenDatabase::addIpToLocal($this->db, '4.4.4.4', 'URI filter', false);
        BotwardenDatabase::addIpToLocal($this->db, '4.4.4.4', 'admin add', true);
        $row = BotwardenDatabase::searchLocal($this->db, '4.4.4.4');
        $this->assertSame(1, (int)$row['is_manual']);
        $this->assertSame('admin add', $row['reason']);
        $this->assertSame(1, BotwardenDatabase::getLocalCount($this->db));
    }

    public function testAutoAddDoesNotDemoteManualEntry(): void
    {
        BotwardenDatabase::addIpToLocal($this->db, '5.5.5.5', 'admin add', true);
        BotwardenDatabase::addIpToLocal($this->db, '5.5.5.5', 'URI filter', false);
        $row = BotwardenDatabase::searchLocal($this->db, '5.5.5.5');
        $this->assertSame(1, (int)$row['is_manual']);
        $this->assertSame('admin add', $row['reason']);
    }

    // ── Report queue ─────────────────────────────────────────────────────────

    public function testReportQueueRoundTrip(): void
    {
        $this->assertSame([], BotwardenDatabase::getPendingReports($this->db));
        BotwardenDatabase::enqueueReport($this->db, '1.2.3.4', '/wp-login.php');
        $pending = BotwardenDatabase::getPendingReports($this->db, 10);
        $this->assertCount(1, $pending);
        $this->assertSame('1.2.3.4', $pending[0]['ip']);
        $this->assertSame('/wp-login.php', $pending[0]['path']);
        BotwardenDatabase::deleteReport($this->db, (int)$pending[0]['id']);
        $this->assertSame([], BotwardenDatabase::getPendingReports($this->db));
    }

    public function testFailedReportRetriedThenDropped(): void
    {
        BotwardenDatabase::enqueueReport($this->db, '1.2.3.4', '/x');
        $id = (int)BotwardenDatabase::getPendingReports($this->db)[0]['id'];

        // Below the cap it is retained for retry; at the cap it is dropped.
        BotwardenDatabase::failReport($this->db, $id, 3); // attempts 1
        $this->assertCount(1, BotwardenDatabase::getPendingReports($this->db));
        BotwardenDatabase::failReport($this->db, $id, 3); // attempts 2
        $this->assertCount(1, BotwardenDatabase::getPendingReports($this->db));
        BotwardenDatabase::failReport($this->db, $id, 3); // attempts 3 -> dropped
        $this->assertSame([], BotwardenDatabase::getPendingReports($this->db));
    }
}
