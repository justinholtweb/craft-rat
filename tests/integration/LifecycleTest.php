<?php

namespace justinholtweb\rat\tests\integration;

use Craft;
use craft\elements\Entry;
use craft\elements\User;
use craft\models\Section;
use craft\test\TestCase;
use justinholtweb\rat\controllers\EditLogController;
use justinholtweb\rat\models\EditLog;
use justinholtweb\rat\models\Settings;
use justinholtweb\rat\Plugin;
use justinholtweb\rat\tests\support\RatTestTrait;
use justinholtweb\rat\widgets\RecentEditsWidget;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;

/**
 * Deletes, restores and structure moves.
 *
 * Until 5.2.0 Rat only listened for saves, so none of these were recorded — and a permanent delete
 * cascaded through a foreign key and erased the element's whole history, including any trace of
 * who deleted it. "Who deleted this?" is the question an edit log most often gets asked.
 */
final class LifecycleTest extends TestCase
{
    use RatTestTrait;

    private Settings $settings;
    private array $originalSettings;

    protected function _before(): void
    {
        $this->clearLog();
        $this->logout();
        $this->settings = Plugin::getInstance()->getSettings();
        $this->originalSettings = $this->settings->toArray();
    }

    protected function _after(): void
    {
        $this->settings->setAttributes($this->originalSettings, false);
    }

    private function rowsWithAction(int $elementId, string $action): array
    {
        return array_values(array_filter(
            $this->logRowsFor($elementId),
            fn(array $row) => $row['action'] === $action,
        ));
    }

    private function makeAdmin(string $username): User
    {
        $admin = $this->createUser($username);
        $admin->admin = true;
        Craft::$app->getElements()->saveElement($admin, false);

        return $admin;
    }

    private function callHistoryEndpoint(int $elementId, int $siteId): array
    {
        Craft::$app->getRequest()->getHeaders()->set('Accept', 'application/json');
        Craft::$app->getRequest()->setIsCpRequest(true);
        Craft::$app->getRequest()->setBodyParams([
            'elementId' => $elementId,
            'siteId' => $siteId,
        ]);

        $controller = new EditLogController('edit-log', Plugin::getInstance());

        return $controller->actionElementHistory()->data;
    }

    // ---- deletes -----------------------------------------------------------

    public function testASoftDeleteIsRecordedWithWhoDidIt(): void
    {
        $entry = $this->createEntry($this->createSection(), 'Doomed Post');
        $editor = $this->createUser('the-deleter', 'Dana Deleter');
        $this->loginAs($editor);

        Craft::$app->getElements()->deleteElement($entry);

        $rows = $this->rowsWithAction($entry->id, EditLog::ACTION_DELETE);
        $this->assertCount(1, $rows);
        $this->assertSame($editor->id, (int)$rows[0]['userId']);
        $this->assertSame('Doomed Post', $rows[0]['elementLabel']);
        $this->assertSame(Entry::class, $rows[0]['elementType']);
        $this->assertSame((int)$entry->siteId, (int)$rows[0]['siteId']);
        $this->assertFalse((bool)$rows[0]['isNew']);
        $this->assertNotEmpty($rows[0]['dateCreated']);
    }

    public function testAPermanentDeleteIsRecordedAsOneAndTheHistorySurvivesIt(): void
    {
        $entry = $this->createEntry($this->createSection(), 'Gone For Good');
        $this->loginAs($this->createUser('hard-deleter'));

        Craft::$app->getElements()->deleteElement($entry, true);

        $this->assertNull(Craft::$app->getElements()->getElementById($entry->id, null, null, ['trashed' => null]));

        $rows = $this->logRowsFor($entry->id);
        $this->assertSame([EditLog::ACTION_SAVE, EditLog::ACTION_HARD_DELETE], array_column($rows, 'action'));
        $this->assertSame('Gone For Good', $rows[1]['elementLabel'], 'the label is the one captured at the time');
    }

    public function testEmptyingTheTrashIsRecordedAsAPermanentDelete(): void
    {
        $entry = $this->createEntry($this->createSection(), 'Trashed Then Purged');

        Craft::$app->getElements()->deleteElement($entry);
        Craft::$app->getElements()->deleteElement($entry, true);

        $this->assertSame(
            [EditLog::ACTION_SAVE, EditLog::ACTION_DELETE, EditLog::ACTION_HARD_DELETE],
            array_column($this->logRowsFor($entry->id), 'action'),
        );
    }

    public function testDeletingADraftIsNotRecorded(): void
    {
        $entry = $this->createEntry($this->createSection());
        $editor = $this->createUser('draft-discarder');
        $draft = Craft::$app->getDrafts()->createDraft($entry, $editor->id);
        $this->clearLog();

        Craft::$app->getElements()->deleteElement($draft, true);

        $this->assertSame([], $this->logRowsFor($draft->id));
        $this->assertSame([], $this->logRowsFor($entry->id));
    }

    public function testElementsDeletedWithTheirOwnerAreNotRecordedSeparately(): void
    {
        $entry = $this->createEntry($this->createSection());
        $this->clearLog();

        $entry->deletedWithOwner = true;
        Craft::$app->getElements()->deleteElement($entry);

        $this->assertSame([], $this->logRowsFor($entry->id));
    }

    // ---- restores ----------------------------------------------------------

    public function testARestoreIsRecorded(): void
    {
        $entry = $this->createEntry($this->createSection(), 'Second Chance');
        Craft::$app->getElements()->deleteElement($entry);

        $restorer = $this->createUser('the-restorer');
        $this->loginAs($restorer);
        $this->assertTrue(Craft::$app->getElements()->restoreElement($entry));

        $rows = $this->rowsWithAction($entry->id, EditLog::ACTION_RESTORE);
        $this->assertCount(1, $rows);
        $this->assertSame($restorer->id, (int)$rows[0]['userId']);
        $this->assertSame('Second Chance', $rows[0]['elementLabel']);
    }

    public function testElementsRestoredWithTheirOwnerAreNotRecordedSeparately(): void
    {
        $entry = $this->createEntry($this->createSection());
        $entry->deletedWithOwner = true;
        Craft::$app->getElements()->deleteElement($entry);
        $this->clearLog();

        Craft::$app->getElements()->restoreElement($entry);

        $this->assertSame([], $this->logRowsFor($entry->id));
    }

    // ---- structure moves ---------------------------------------------------

    /**
     * @return Entry[] three top-level pages, A, B and C, in that order
     */
    private function threePages(Section $section): array
    {
        return array_map(fn(string $title) => $this->createEntry($section, $title), ['Page A', 'Page B', 'Page C']);
    }

    public function testPlacingANewEntryInAStructureIsNotAMove(): void
    {
        [$a] = $this->threePages($this->createStructureSection());

        $this->assertSame([], $this->rowsWithAction($a->id, EditLog::ACTION_MOVE));
    }

    public function testMovingUnderAnotherElementRecordsTheOldAndNewParentAndPosition(): void
    {
        $section = $this->createStructureSection();
        [$a, , $c] = $this->threePages($section);
        $mover = $this->createUser('the-mover');
        $this->loginAs($mover);

        Craft::$app->getStructures()->append($section->structureId, $c, $a);

        $rows = $this->rowsWithAction($c->id, EditLog::ACTION_MOVE);
        $this->assertCount(1, $rows);
        $this->assertSame($mover->id, (int)$rows[0]['userId']);

        $details = json_decode($rows[0]['details'], true);
        $this->assertSame('append', $details['action']);
        $this->assertSame($a->id, $details['targetId']);
        $this->assertSame('Page A', $details['targetLabel']);
        $this->assertSame(['parentId' => null, 'parentLabel' => null, 'position' => 3], $details['from']);
        $this->assertSame(['parentId' => $a->id, 'parentLabel' => 'Page A', 'position' => 1], $details['to']);

        $summary = $this->tracker()->getElementHistory($c->id, $c->siteId)[0]->getMoveSummary();
        $this->assertSame('Moved to the end of “Page A”. Was at the top level, position 3. Now under “Page A”, position 1.', $summary);
    }

    public function testMovingAfterASiblingRecordsThePlacement(): void
    {
        $section = $this->createStructureSection();
        [$a, $b] = $this->threePages($section);

        Craft::$app->getStructures()->moveAfter($section->structureId, $a, $b);

        $rows = $this->rowsWithAction($a->id, EditLog::ACTION_MOVE);
        $this->assertCount(1, $rows);

        $details = json_decode($rows[0]['details'], true);
        $this->assertSame('placeAfter', $details['action']);
        $this->assertSame('Page B', $details['targetLabel']);
        $this->assertSame(1, $details['from']['position']);
        $this->assertSame(2, $details['to']['position']);
        $this->assertNull($details['to']['parentId']);
    }

    public function testAMoveThatChangesNothingIsNotRecorded(): void
    {
        $section = $this->createStructureSection();
        [$a, $b] = $this->threePages($section);

        // A already sits directly before B.
        Craft::$app->getStructures()->moveBefore($section->structureId, $a, $b);

        $this->assertSame([], $this->rowsWithAction($a->id, EditLog::ACTION_MOVE));
    }

    // ---- scope and retention -----------------------------------------------

    public function testExcludedElementTypesAreNotRecordedOnDeleteEither(): void
    {
        $user = $this->createUser('excluded-deletee');
        $this->settings->excludedElementTypes = [User::class];
        $this->clearLog();

        Craft::$app->getElements()->deleteElement($user);
        Craft::$app->getElements()->restoreElement($user);

        $this->assertSame([], $this->logRowsFor($user->id));
    }

    public function testDeletionsArePrunedWithEverythingElse(): void
    {
        $old = $this->seedLog([
            'action' => EditLog::ACTION_HARD_DELETE,
            'dateCreated' => '2000-01-01 00:00:00',
            'dateUpdated' => '2000-01-01 00:00:00',
        ]);
        $recent = $this->seedLog(['action' => EditLog::ACTION_HARD_DELETE]);

        $this->tracker()->cleanupOldLogs(90);

        $ids = array_map('intval', (new \craft\db\Query())->select('id')->from('{{%rat_editlog}}')->column());
        $this->assertNotContains($old, $ids);
        $this->assertContains($recent, $ids);
    }

    // ---- where it shows -----------------------------------------------------

    public function testTheSidebarHistoryLabelsEachAction(): void
    {
        $section = $this->createStructureSection();
        [$a, , $c] = $this->threePages($section);
        $this->loginAs($this->createUser('sidebar-actor', 'Sidebar Actor'));

        Craft::$app->getStructures()->append($section->structureId, $c, $a);
        Craft::$app->getElements()->deleteElement($c);
        Craft::$app->getElements()->restoreElement($c);

        $history = $this->tracker()->getElementHistory($c->id, $c->siteId);
        $this->assertSame(
            [EditLog::ACTION_RESTORE, EditLog::ACTION_DELETE, EditLog::ACTION_MOVE, EditLog::ACTION_SAVE],
            array_map(fn(EditLog $edit) => $edit->action, $history),
        );

        $html = Craft::$app->getView()->renderTemplate('rat/_sidebar/_history-items', ['history' => $history], 'cp');

        foreach (['restored', 'deleted', 'moved', 'created', 'Now under “Page A”, position 1.'] as $text) {
            $this->assertStringContainsString($text, $html);
        }
    }

    public function testTheWidgetLabelsDeletionsAndCanShowOnlyThose(): void
    {
        $section = $this->createSection();
        $kept = $this->createEntry($section, 'Kept Post');
        $trashed = $this->createEntry($section, 'Trashed Post');
        $purged = $this->createEntry($section, 'Purged Post');

        $this->loginAs($this->makeAdmin('widget-admin'));
        Craft::$app->getElements()->deleteElement($trashed);
        Craft::$app->getElements()->deleteElement($purged, true);

        $all = (new RecentEditsWidget())->getBodyHtml();
        $this->assertStringContainsString('Deleted permanently', $all);
        $this->assertStringContainsString('in the trash', $all);
        $this->assertStringContainsString('Kept Post', $all);

        $widget = new RecentEditsWidget(['show' => 'deletions']);
        $this->assertSame('Recent Deletions', $widget->getTitle());

        $deletions = $widget->getBodyHtml();
        $this->assertStringContainsString('Trashed Post', $deletions);
        $this->assertStringContainsString('Purged Post', $deletions);
        $this->assertStringNotContainsString('Kept Post', $deletions);
        $this->assertStringNotContainsString('Created', $deletions);
    }

    public function testTheWidgetRejectsAnUnknownShowValue(): void
    {
        $widget = new RecentEditsWidget(['show' => 'everything']);

        $this->assertFalse($widget->validate(['show']));
    }

    public function testDeletionsCanBeFoundByTheirRecordedTitle(): void
    {
        $section = $this->createSection();
        $entry = $this->createEntry($section, 'Quarterly Report 100%');
        $this->createEntry($section, 'Something Else');
        Craft::$app->getElements()->deleteElement($entry, true);

        $found = $this->tracker()->getDeletions('Report 100%');
        $this->assertCount(1, $found);
        $this->assertSame($entry->id, $found[0]->elementId);
        $this->assertSame(EditLog::ACTION_HARD_DELETE, $found[0]->action);

        // `%` is matched literally rather than as a wildcard.
        $this->assertSame([], $this->tracker()->getDeletions('Report 1%0'));
    }

    // ---- permissions -------------------------------------------------------

    private function restrictedEditorFor(Section $allowed): User
    {
        $editor = $this->createUser('lifecycle-editor');

        Craft::$app->getUserPermissions()->saveUserPermissions($editor->id, [
            'accessCp',
            "viewEntries:$allowed->uid",
            "viewPeerEntries:$allowed->uid",
        ]);

        return Craft::$app->getUsers()->getUserById($editor->id);
    }

    public function testAnEditorSeesDeletionsInTheirOwnSectionButNotOthers(): void
    {
        $allowed = $this->createSection('Allowed', 'ratLifeAllowed');
        $forbidden = $this->createSection('Forbidden', 'ratLifeForbidden');
        $mine = $this->createEntry($allowed, 'My Trashed Post');
        $theirs = $this->createEntry($forbidden, 'Their Trashed Post');
        $purged = $this->createEntry($allowed, 'Purged Post');
        Craft::$app->getElements()->deleteElement($mine);
        Craft::$app->getElements()->deleteElement($theirs);
        Craft::$app->getElements()->deleteElement($purged, true);

        $editor = $this->restrictedEditorFor($allowed);
        $this->loginAs($editor);

        $html = (new RecentEditsWidget(['show' => 'deletions']))->getBodyHtml();
        $this->assertStringContainsString('My Trashed Post', $html);
        $this->assertStringNotContainsString('Their Trashed Post', $html);
        // Gone for good, so there's nothing to check the editor against: admins only.
        $this->assertStringNotContainsString('Purged Post', $html);

        $data = $this->callHistoryEndpoint($mine->id, $mine->siteId);
        $this->assertSame(EditLog::ACTION_DELETE, $data['history'][0]['action']);
        $this->assertSame('Deleted', $data['history'][0]['actionLabel']);
    }

    public function testAnEditorCannotReadTheHistoryOfATrashedElementTheyCannotView(): void
    {
        $allowed = $this->createSection('Allowed', 'ratLifeAllowed');
        $forbidden = $this->createSection('Forbidden', 'ratLifeForbidden');
        $theirs = $this->createEntry($forbidden, 'Their Trashed Post');
        Craft::$app->getElements()->deleteElement($theirs);

        $this->loginAs($this->restrictedEditorFor($allowed));

        $this->expectException(ForbiddenHttpException::class);
        $this->callHistoryEndpoint($theirs->id, $theirs->siteId);
    }

    public function testAnAdminCanReadTheHistoryOfAPermanentlyDeletedElement(): void
    {
        $entry = $this->createEntry($this->createSection(), 'Purged Post');
        Craft::$app->getElements()->deleteElement($entry, true);

        $this->loginAs($this->makeAdmin('history-admin'));

        $data = $this->callHistoryEndpoint($entry->id, $entry->siteId);

        $this->assertSame([EditLog::ACTION_HARD_DELETE, EditLog::ACTION_SAVE], array_column($data['history'], 'action'));
        $this->assertSame('Purged Post', $data['history'][0]['elementLabel']);
    }

    public function testANonAdminGetsNotFoundForAPermanentlyDeletedElement(): void
    {
        $section = $this->createSection();
        $entry = $this->createEntry($section, 'Purged Post');
        Craft::$app->getElements()->deleteElement($entry, true);

        $this->loginAs($this->restrictedEditorFor($section));

        $this->expectException(NotFoundHttpException::class);
        $this->callHistoryEndpoint($entry->id, $entry->siteId);
    }
}
