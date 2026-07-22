<?php

namespace justinholtweb\rat\tests\integration;

use Craft;
use craft\elements\Entry;
use craft\elements\User;
use craft\test\TestCase;
use justinholtweb\rat\tests\support\RatTestTrait;

/**
 * End-to-end coverage of the write path: saving a real element through
 * Craft::$app->getElements() must produce exactly one edit-log row with the
 * right shape, and the drafts/revisions/propagating/resaving filters must keep
 * noise out of the log.
 */
final class EditTrackerLoggingTest extends TestCase
{
    use RatTestTrait;

    protected function _before(): void
    {
        $this->clearLog();
        $this->logout();
    }

    public function testSavingAnElementWritesOneLogRow(): void
    {
        $user = $this->createUser('logged-subject');

        $rows = $this->logRowsFor($user->id);

        $this->assertCount(1, $rows);
        $this->assertSame(User::class, $rows[0]['elementType']);
        $this->assertSame((int)$user->siteId, (int)$rows[0]['siteId']);
        $this->assertSame((string)$user, $rows[0]['elementLabel']);
        $this->assertNotEmpty($rows[0]['dateCreated']);
    }

    public function testFirstSaveIsFlaggedAsNewAndLaterSavesAreNot(): void
    {
        $user = $this->createUser();

        $user->fullName = 'Renamed Person';
        Craft::$app->getElements()->saveElement($user, false);

        $rows = $this->logRowsFor($user->id);

        $this->assertCount(2, $rows);
        $this->assertTrue((bool)$rows[0]['isNew'], 'The initial save should be flagged as new.');
        $this->assertFalse((bool)$rows[1]['isNew'], 'A subsequent save should not be flagged as new.');
    }

    public function testEditsAreAttributedToTheSignedInUser(): void
    {
        $editor = $this->createUser('the-editor');
        $this->loginAs($editor);

        $subject = $this->createUser('the-subject');
        $rows = $this->logRowsFor($subject->id);

        $this->assertSame($editor->id, (int)$rows[0]['userId']);
    }

    public function testEditsWithNoSignedInUserAreRecordedWithoutAUserId(): void
    {
        $subject = $this->createUser();
        $rows = $this->logRowsFor($subject->id);

        $this->assertNull($rows[0]['userId']);
    }

    public function testDirtyAttributesAreCapturedAsJson(): void
    {
        $user = $this->createUser();
        $this->clearLog();

        $user->fullName = 'Changed Name';
        Craft::$app->getElements()->saveElement($user, false);

        $rows = $this->logRowsFor($user->id);
        $dirty = json_decode($rows[0]['dirtyAttributes'] ?? '[]', true);

        $this->assertIsArray($dirty);
        $this->assertContains('fullName', $dirty);
    }

    public function testSavingAnEntryIsLogged(): void
    {
        $entry = $this->createEntry($this->createSection(), 'A Tracked Post');

        $rows = $this->logRowsFor($entry->id);

        $this->assertCount(1, $rows);
        $this->assertSame(Entry::class, $rows[0]['elementType']);
        $this->assertSame('A Tracked Post', $rows[0]['elementLabel']);
        $this->assertTrue((bool)$rows[0]['isNew']);
    }

    public function testDraftsAreNotLogged(): void
    {
        $entry = $this->createEntry($this->createSection());
        $this->clearLog();

        $editor = $this->createUser('draft-author');
        $this->clearLog();

        $draft = Craft::$app->getDrafts()->createDraft($entry, $editor->id);
        $draft->title = 'Draft Title';
        Craft::$app->getElements()->saveElement($draft, false);

        $this->assertSame([], $this->logRowsFor($draft->id));
        $this->assertSame([], $this->logRowsFor($entry->id));
    }

    public function testRevisionsAreNotLogged(): void
    {
        $entry = $this->createEntry($this->createSection());
        $this->clearLog();

        $revisionId = Craft::$app->getRevisions()->createRevision($entry);

        $this->assertSame([], $this->logRowsFor($revisionId));
        $this->assertSame([], $this->logRowsFor($entry->id));
    }

    public function testPropagatingSavesAreNotLogged(): void
    {
        $user = $this->createUser();
        $this->clearLog();

        $user->propagating = true;
        Craft::$app->getElements()->saveElement($user, false);

        $this->assertSame([], $this->logRowsFor($user->id));
    }

    public function testBulkResavesAreNotLogged(): void
    {
        $user = $this->createUser();
        $this->clearLog();

        $user->resaving = true;
        Craft::$app->getElements()->saveElement($user, false);

        $this->assertSame([], $this->logRowsFor($user->id));
    }

    /**
     * Element labels come straight from (string)$element, but the column is
     * only 255 characters wide. A long label must not blow up the save.
     */
    public function testOverlongElementLabelsDoNotBreakTheSave(): void
    {
        $user = $this->createUser();
        $this->clearLog();

        $element = new LongLabelUser();
        $element->id = $user->id;
        $element->siteId = $user->siteId;

        $this->tracker()->logEdit($element, false);

        $rows = $this->logRowsFor($user->id);
        $this->assertCount(1, $rows);
        $this->assertLessThanOrEqual(255, mb_strlen($rows[0]['elementLabel']));
    }
}

/**
 * A stand-in element whose label exceeds the elementLabel column width.
 */
class LongLabelUser extends User
{
    public function __toString(): string
    {
        return str_repeat('a', 300);
    }
}
