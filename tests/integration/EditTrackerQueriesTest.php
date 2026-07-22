<?php

namespace justinholtweb\rat\tests\integration;

use Craft;
use craft\elements\User;
use craft\helpers\Db;
use craft\test\TestCase;
use DateTime;
use justinholtweb\rat\models\EditLog;
use justinholtweb\rat\tests\support\RatTestTrait;

/**
 * Coverage of the read path — getRecentEdits(), getElementHistory() and
 * cleanupOldLogs() — against seeded rows with pinned timestamps.
 */
final class EditTrackerQueriesTest extends TestCase
{
    use RatTestTrait;

    private int $siteId;
    private User $elementA;
    private User $elementB;

    protected function _before(): void
    {
        $this->clearLog();
        $this->logout();
        $this->siteId = Craft::$app->getSites()->getPrimarySite()->id;
        $this->elementA = $this->createUser();
        $this->elementB = $this->createUser();
        $this->clearLog();
    }

    private function seedAt(string $modify, array $overrides = []): int
    {
        $date = (new DateTime())->modify($modify);

        return $this->seedLog(array_merge([
            'elementId' => $this->elementA->id,
            'siteId' => $this->siteId,
            'dateCreated' => Db::prepareDateForDb($date),
            'dateUpdated' => Db::prepareDateForDb($date),
        ], $overrides));
    }

    // ---- getRecentEdits ----------------------------------------------------

    public function testRecentEditsComeBackNewestFirst(): void
    {
        $oldest = $this->seedAt('-3 hours', ['elementLabel' => 'oldest']);
        $middle = $this->seedAt('-2 hours', ['elementLabel' => 'middle']);
        $newest = $this->seedAt('-1 hour', ['elementLabel' => 'newest']);

        $edits = $this->tracker()->getRecentEdits();

        $this->assertSame([$newest, $middle, $oldest], array_map(fn(EditLog $e) => $e->id, $edits));
    }

    /**
     * dateCreated only has second resolution, so several edits made in the same
     * second must still come back in a stable, newest-first order rather than
     * whatever the storage engine happens to return.
     */
    public function testEditsSharingATimestampAreOrderedByInsertionNewestFirst(): void
    {
        $date = Db::prepareDateForDb(new DateTime());

        $first = $this->seedLog(['elementId' => $this->elementA->id, 'siteId' => $this->siteId, 'dateCreated' => $date, 'dateUpdated' => $date]);
        $second = $this->seedLog(['elementId' => $this->elementA->id, 'siteId' => $this->siteId, 'dateCreated' => $date, 'dateUpdated' => $date]);
        $third = $this->seedLog(['elementId' => $this->elementA->id, 'siteId' => $this->siteId, 'dateCreated' => $date, 'dateUpdated' => $date]);

        $edits = $this->tracker()->getRecentEdits();

        $this->assertSame([$third, $second, $first], array_map(fn(EditLog $e) => $e->id, $edits));
    }

    public function testRecentEditsRespectLimitAndOffset(): void
    {
        $this->seedAt('-3 hours');
        $middle = $this->seedAt('-2 hours');
        $newest = $this->seedAt('-1 hour');

        $firstPage = $this->tracker()->getRecentEdits(1);
        $secondPage = $this->tracker()->getRecentEdits(1, 1);

        $this->assertSame([$newest], array_map(fn(EditLog $e) => $e->id, $firstPage));
        $this->assertSame([$middle], array_map(fn(EditLog $e) => $e->id, $secondPage));
    }

    public function testRecentEditsAreHydratedIntoModels(): void
    {
        $editor = $this->createUser('hydration-editor');
        $this->clearLog();

        $id = $this->seedAt('-1 hour', [
            'userId' => $editor->id,
            'elementLabel' => 'Hydrated',
            'isNew' => true,
            'dirtyAttributes' => json_encode(['title', 'slug']),
        ]);

        $edit = $this->tracker()->getRecentEdits()[0];

        $this->assertInstanceOf(EditLog::class, $edit);
        $this->assertSame($id, $edit->id);
        $this->assertSame($this->elementA->id, $edit->elementId);
        $this->assertSame($this->siteId, $edit->siteId);
        $this->assertSame($editor->id, $edit->userId);
        $this->assertSame(User::class, $edit->elementType);
        $this->assertSame('Hydrated', $edit->elementLabel);
        $this->assertTrue($edit->isNew);
        $this->assertSame(['title', 'slug'], $edit->getDirtyAttributesList());
        $this->assertInstanceOf(DateTime::class, $edit->dateCreated);
    }

    public function testRecentEditsAreEmptyWhenNothingHasBeenLogged(): void
    {
        $this->assertSame([], $this->tracker()->getRecentEdits());
    }

    // ---- getElementHistory -------------------------------------------------

    public function testElementHistoryIsScopedToTheElement(): void
    {
        $mine = $this->seedAt('-1 hour');
        $this->seedAt('-1 hour', ['elementId' => $this->elementB->id]);

        $history = $this->tracker()->getElementHistory($this->elementA->id, $this->siteId);

        $this->assertSame([$mine], array_map(fn(EditLog $e) => $e->id, $history));
    }

    public function testElementHistoryIsScopedToTheSite(): void
    {
        $this->seedAt('-1 hour');

        $history = $this->tracker()->getElementHistory($this->elementA->id, $this->siteId + 999);

        $this->assertSame([], $history);
    }

    public function testElementHistoryIsNewestFirstAndPaginates(): void
    {
        $oldest = $this->seedAt('-3 hours');
        $middle = $this->seedAt('-2 hours');
        $newest = $this->seedAt('-1 hour');

        $all = $this->tracker()->getElementHistory($this->elementA->id, $this->siteId);
        $page2 = $this->tracker()->getElementHistory($this->elementA->id, $this->siteId, 1, 1);

        $this->assertSame([$newest, $middle, $oldest], array_map(fn(EditLog $e) => $e->id, $all));
        $this->assertSame([$middle], array_map(fn(EditLog $e) => $e->id, $page2));
    }

    public function testElementHistorySharingATimestampIsOrderedNewestFirst(): void
    {
        $date = Db::prepareDateForDb(new DateTime());
        $args = ['elementId' => $this->elementA->id, 'siteId' => $this->siteId, 'dateCreated' => $date, 'dateUpdated' => $date];

        $first = $this->seedLog($args);
        $second = $this->seedLog($args);

        $history = $this->tracker()->getElementHistory($this->elementA->id, $this->siteId);

        $this->assertSame([$second, $first], array_map(fn(EditLog $e) => $e->id, $history));
    }

    // ---- cleanupOldLogs ----------------------------------------------------

    public function testCleanupRemovesOnlyRowsOlderThanTheCutoff(): void
    {
        $this->seedAt('-100 days');
        $this->seedAt('-91 days');
        $kept = $this->seedAt('-89 days');
        $alsoKept = $this->seedAt('-1 hour');

        $deleted = $this->tracker()->cleanupOldLogs();

        $this->assertSame(2, $deleted);
        $remaining = array_map(fn(EditLog $e) => $e->id, $this->tracker()->getRecentEdits());
        $this->assertEqualsCanonicalizing([$kept, $alsoKept], $remaining);
    }

    public function testCleanupHonoursACustomWindow(): void
    {
        $this->seedAt('-10 days');
        $kept = $this->seedAt('-1 day');

        $deleted = $this->tracker()->cleanupOldLogs(7);

        $this->assertSame(1, $deleted);
        $this->assertSame([$kept], array_map(fn(EditLog $e) => $e->id, $this->tracker()->getRecentEdits()));
    }

    public function testCleanupOnAnEmptyLogDeletesNothing(): void
    {
        $this->assertSame(0, $this->tracker()->cleanupOldLogs());
    }
}
