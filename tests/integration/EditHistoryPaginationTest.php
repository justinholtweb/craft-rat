<?php

namespace justinholtweb\rat\tests\integration;

use Craft;
use craft\helpers\Db;
use craft\test\TestCase;
use DateTime;
use justinholtweb\rat\controllers\EditLogController;
use justinholtweb\rat\Plugin;
use justinholtweb\rat\tests\support\RatTestTrait;

/**
 * Covers the "View more..." pagination that rat.js drives: the markup hooks
 * the script binds to, and the rendered rows + hasMore flag it consumes.
 */
final class EditHistoryPaginationTest extends TestCase
{
    use RatTestTrait;

    protected function _before(): void
    {
        $this->clearLog();

        $admin = $this->createUser('pagination-admin');
        $admin->admin = true;
        Craft::$app->getElements()->saveElement($admin, false);
        $this->loginAs($admin);

        $this->clearLog();
    }

    private function seedEdits(int $elementId, int $siteId, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->seedLog([
                'elementId' => $elementId,
                'siteId' => $siteId,
                'userId' => null,
                'elementLabel' => "Edit $i",
                'dateCreated' => Db::prepareDateForDb(new DateTime()),
            ]);
        }
    }

    private function fetchPage(int $elementId, int $siteId, int $limit, int $offset): array
    {
        Craft::$app->getRequest()->getHeaders()->set('Accept', 'application/json');
        Craft::$app->getRequest()->setIsCpRequest(true);
        Craft::$app->getRequest()->setBodyParams([
            'elementId' => $elementId,
            'siteId' => $siteId,
            'limit' => $limit,
            'offset' => $offset,
        ]);

        $controller = new EditLogController('edit-log', Plugin::getInstance());

        return $controller->actionElementHistory()->data;
    }

    public function testTheSidebarExposesTheHooksTheScriptNeeds(): void
    {
        $subject = $this->createUser();
        $this->clearLog();
        $this->seedEdits($subject->id, $subject->siteId, 12);

        $html = $subject->getSidebarHtml(false);

        $this->assertStringContainsString('data-rat-element-id="' . $subject->id . '"', $html);
        $this->assertStringContainsString('data-rat-site-id="' . $subject->siteId . '"', $html);
        $this->assertStringContainsString('data-rat-page-size="10"', $html);
        $this->assertStringContainsString('data-rat-load-more', $html);
    }

    public function testTheSidebarHidesTheLinkWhenNothingFollows(): void
    {
        $subject = $this->createUser();
        $this->clearLog();

        // Exactly one full page: the old `count >= 10` check offered a "View
        // more..." link here that had nothing left to load.
        $this->seedEdits($subject->id, $subject->siteId, 10);

        $html = $subject->getSidebarHtml(false);

        $this->assertStringNotContainsString('data-rat-load-more', $html);
        $this->assertSame(10, substr_count($html, 'rat-history-item'));
    }

    public function testTheEndpointReturnsRenderedRowsAndFlagsAFurtherPage(): void
    {
        $subject = $this->createUser();
        $this->clearLog();
        $this->seedEdits($subject->id, $subject->siteId, 25);

        $page = $this->fetchPage($subject->id, $subject->siteId, 10, 10);

        $this->assertTrue($page['hasMore']);
        $this->assertCount(10, $page['history']);
        $this->assertSame(10, substr_count($page['html'], 'rat-history-item'));
    }

    public function testTheEndpointClearsTheFlagOnTheLastPage(): void
    {
        $subject = $this->createUser();
        $this->clearLog();
        $this->seedEdits($subject->id, $subject->siteId, 25);

        $page = $this->fetchPage($subject->id, $subject->siteId, 10, 20);

        $this->assertFalse($page['hasMore']);
        $this->assertCount(5, $page['history']);
        $this->assertSame(5, substr_count($page['html'], 'rat-history-item'));
    }

    public function testPagesDoNotOverlapOrSkipRows(): void
    {
        $subject = $this->createUser();
        $this->clearLog();
        $this->seedEdits($subject->id, $subject->siteId, 25);

        $ids = [];

        foreach ([0, 10, 20] as $offset) {
            $page = $this->fetchPage($subject->id, $subject->siteId, 10, $offset);

            foreach ($page['history'] as $entry) {
                $ids[] = $entry['id'];
            }
        }

        $this->assertCount(25, $ids);
        $this->assertSame($ids, array_values(array_unique($ids)));
    }
}
