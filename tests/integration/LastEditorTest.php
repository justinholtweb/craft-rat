<?php

namespace justinholtweb\rat\tests\integration;

use Craft;
use craft\elements\User;
use craft\helpers\Db;
use craft\test\TestCase;
use DateTime;
use justinholtweb\rat\services\EditTracker;
use justinholtweb\rat\tests\support\RatTestTrait;

/**
 * Coverage of the element-index "Last Editor" column: the lookup, the rendered
 * cell, and the correlated-subquery sort expression.
 */
final class LastEditorTest extends TestCase
{
    use RatTestTrait;

    private int $siteId;
    private User $subject;

    protected function _before(): void
    {
        $this->clearLog();
        $this->logout();
        $this->siteId = Craft::$app->getSites()->getPrimarySite()->id;
        $this->subject = $this->createUser();
        $this->clearLog();
    }

    private function seedFor(User $element, ?int $userId, string $modify = '-1 hour'): int
    {
        $date = Db::prepareDateForDb((new DateTime())->modify($modify));

        return $this->seedLog([
            'elementId' => $element->id,
            'siteId' => $this->siteId,
            'userId' => $userId,
            'dateCreated' => $date,
            'dateUpdated' => $date,
        ]);
    }

    // ---- getLastEditor -----------------------------------------------------

    public function testLastEditorIsNullWhenNothingHasBeenLogged(): void
    {
        $this->assertNull($this->tracker()->getLastEditor($this->subject->id, $this->siteId));
    }

    public function testLastEditorReturnsTheMostRecentEditorWithNamesJoinedIn(): void
    {
        $older = $this->createUser('older-editor', 'Older Editor');
        $newer = $this->createUser('newer-editor', 'Newer Editor');
        $this->clearLog();

        $this->seedFor($this->subject, $older->id, '-2 hours');
        $this->seedFor($this->subject, $newer->id, '-1 hour');

        $editor = $this->tracker()->getLastEditor($this->subject->id, $this->siteId);

        $this->assertSame($newer->id, (int)$editor['userId']);
        $this->assertSame('Newer Editor', $editor['fullName']);
        $this->assertSame('newer-editor', $editor['username']);
    }

    public function testLastEditorBreaksTimestampTiesByInsertionOrder(): void
    {
        $first = $this->createUser('tie-first');
        $second = $this->createUser('tie-second');
        $this->clearLog();

        $this->seedFor($this->subject, $first->id, 'now');
        $this->seedFor($this->subject, $second->id, 'now');

        $editor = $this->tracker()->getLastEditor($this->subject->id, $this->siteId);

        $this->assertSame($second->id, (int)$editor['userId']);
    }

    public function testLastEditorIsScopedToElementAndSite(): void
    {
        $other = $this->createUser();
        $editor = $this->createUser('scoped-editor');
        $this->clearLog();

        $this->seedFor($other, $editor->id);

        $this->assertNull($this->tracker()->getLastEditor($this->subject->id, $this->siteId));
        $this->assertNull($this->tracker()->getLastEditor($other->id, $this->siteId + 999));
    }

    public function testLastEditorSurvivesADeletedUserAsANullUserId(): void
    {
        $editor = $this->createUser('doomed-editor');
        $this->clearLog();
        $this->seedFor($this->subject, $editor->id);

        // Hard-delete so the SET NULL foreign key fires.
        Craft::$app->getDb()->createCommand()
            ->delete('{{%elements}}', ['id' => $editor->id])
            ->execute();

        $row = $this->tracker()->getLastEditor($this->subject->id, $this->siteId);

        $this->assertNotNull($row);
        $this->assertNull($row['userId']);
    }

    public function testLastEditorLookupIsMemoizedWithinTheRequest(): void
    {
        $editor = $this->createUser('memo-editor');
        $this->clearLog();
        $this->seedFor($this->subject, $editor->id);

        $tracker = $this->tracker();
        $first = $tracker->getLastEditor($this->subject->id, $this->siteId);

        // Remove the row behind the service's back; a memoized lookup still returns it.
        $this->clearLog();

        $this->assertSame($first, $tracker->getLastEditor($this->subject->id, $this->siteId));
    }

    /**
     * The memo must not outlive a write. Saving an element and then rendering
     * its index row happens inside a single control-panel request, so a cached
     * "no editor yet" answer would show stale data.
     */
    public function testLoggingAnEditInvalidatesTheMemoizedLookup(): void
    {
        $editor = $this->createUser('invalidation-editor');
        $this->loginAs($editor);
        $this->clearLog();

        $tracker = $this->tracker();
        $this->assertNull($tracker->getLastEditor($this->subject->id, $this->siteId));

        $this->subject->fullName = 'Touched';
        Craft::$app->getElements()->saveElement($this->subject, false);

        $row = $tracker->getLastEditor($this->subject->id, $this->siteId);

        $this->assertNotNull($row, 'The memo should have been invalidated by the new edit.');
        $this->assertSame($editor->id, (int)$row['userId']);
    }

    // ---- getLastEditorCellHtml ---------------------------------------------

    public function testCellIsEmptyWithoutAnyLoggedEdit(): void
    {
        $this->assertSame('', $this->tracker()->getLastEditorCellHtml($this->subject->id, $this->siteId));
    }

    public function testCellShowsTheEditorsFullName(): void
    {
        $editor = $this->createUser('cell-editor', 'Ada Lovelace');
        $this->clearLog();
        $this->seedFor($this->subject, $editor->id);

        $html = $this->tracker()->getLastEditorCellHtml($this->subject->id, $this->siteId);

        $this->assertStringContainsString('Ada Lovelace', $html);
        $this->assertStringContainsString('rat-index-editor', $html);
    }

    public function testCellFallsBackToUsernameThenEmailLocalPart(): void
    {
        $noName = $this->createUser('just-a-username');
        $this->clearLog();
        $this->seedFor($this->subject, $noName->id);

        $this->assertStringContainsString(
            'just-a-username',
            $this->tracker()->getLastEditorCellHtml($this->subject->id, $this->siteId),
        );
    }

    public function testCellShowsSystemForEditsWithNoUser(): void
    {
        $this->seedFor($this->subject, null);

        $this->assertStringContainsString(
            'System',
            $this->tracker()->getLastEditorCellHtml($this->subject->id, $this->siteId),
        );
    }

    public function testCellEscapesEditorNames(): void
    {
        $editor = $this->createUser('xss-editor', '<script>alert(1)</script>');
        $this->clearLog();
        $this->seedFor($this->subject, $editor->id);

        $html = $this->tracker()->getLastEditorCellHtml($this->subject->id, $this->siteId);

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    // ---- getLastEditorSortOrderBy ------------------------------------------

    public function testSortExpressionOrdersAnElementQueryByEditorName(): void
    {
        $zoe = $this->createUser('zoe', 'Zoe Zulu');
        $abe = $this->createUser('abe', 'Abe Alpha');

        $editedByZoe = $this->createUser('subject-z');
        $editedByAbe = $this->createUser('subject-a');
        $this->clearLog();

        $this->seedFor($editedByZoe, $zoe->id);
        $this->seedFor($editedByAbe, $abe->id);

        $ascending = User::find()
            ->id([$editedByZoe->id, $editedByAbe->id])
            ->siteId($this->siteId)
            ->orderBy($this->tracker()->getLastEditorSortOrderBy(SORT_ASC))
            ->ids();

        $descending = User::find()
            ->id([$editedByZoe->id, $editedByAbe->id])
            ->siteId($this->siteId)
            ->orderBy($this->tracker()->getLastEditorSortOrderBy(SORT_DESC))
            ->ids();

        $this->assertSame([$editedByAbe->id, $editedByZoe->id], $ascending);
        $this->assertSame([$editedByZoe->id, $editedByAbe->id], $descending);
    }

    public function testTheLastEditorAttributeKeyIsStable(): void
    {
        $this->assertSame('ratLastEditor', EditTracker::LAST_EDITOR_ATTRIBUTE);
    }
}
