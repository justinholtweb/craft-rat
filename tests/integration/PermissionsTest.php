<?php

namespace justinholtweb\rat\tests\integration;

use Craft;
use craft\elements\Entry;
use craft\elements\User;
use craft\models\Section;
use craft\test\TestCase;
use justinholtweb\rat\controllers\EditLogController;
use justinholtweb\rat\Plugin;
use justinholtweb\rat\tests\support\RatTestTrait;
use justinholtweb\rat\widgets\RecentEditsWidget;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;

/**
 * Edit history is as sensitive as the content it describes — it leaks titles,
 * who touched them, and which fields changed. These tests pin down that an
 * editor can only see history for sections they actually have access to.
 */
final class PermissionsTest extends TestCase
{
    use RatTestTrait;

    private Section $allowed;
    private Section $forbidden;

    protected function _before(): void
    {
        $this->clearLog();
        $this->logout();
    }

    /**
     * An author who can view entries in $allowed but not in $forbidden.
     */
    private function createRestrictedEditor(): User
    {
        $editor = $this->createUser('restricted-editor', 'Restricted Editor');

        Craft::$app->getUserPermissions()->saveUserPermissions($editor->id, [
            'accessCp',
            "viewEntries:{$this->allowed->uid}",
            // The test entries have no author, so viewing them is a "peer"
            // grant as far as Craft is concerned.
            "viewPeerEntries:{$this->allowed->uid}",
        ]);

        // Re-fetch so the permission cache reflects what we just saved.
        return Craft::$app->getUsers()->getUserById($editor->id);
    }

    private function createSections(): void
    {
        $this->allowed = $this->createSection('Allowed', 'ratAllowed');
        $this->forbidden = $this->createSection('Forbidden', 'ratForbidden');
    }

    private function callHistoryEndpoint(Entry $entry): array
    {
        Craft::$app->getRequest()->getHeaders()->set('Accept', 'application/json');
        Craft::$app->getRequest()->setIsCpRequest(true);
        Craft::$app->getRequest()->setBodyParams([
            'elementId' => $entry->id,
            'siteId' => $entry->siteId,
        ]);

        $controller = new EditLogController('edit-log', Plugin::getInstance());

        return $controller->actionElementHistory()->data;
    }

    // ---- the AJAX endpoint --------------------------------------------------

    public function testAnEditorCanReadHistoryForASectionTheyCanView(): void
    {
        $this->createSections();
        $entry = $this->createEntry($this->allowed, 'Readable Post');

        $this->loginAs($this->createRestrictedEditor());

        $data = $this->callHistoryEndpoint($entry);

        $this->assertCount(1, $data['history']);
    }

    public function testAnEditorCannotReadHistoryForASectionTheyCannotView(): void
    {
        $this->createSections();
        $entry = $this->createEntry($this->forbidden, 'Secret Post');

        $this->loginAs($this->createRestrictedEditor());

        $this->expectException(ForbiddenHttpException::class);
        $this->callHistoryEndpoint($entry);
    }

    public function testAnAdminCanReadHistoryForAnySection(): void
    {
        $this->createSections();
        $entry = $this->createEntry($this->forbidden, 'Secret Post');

        $admin = $this->createUser('an-admin');
        $admin->admin = true;
        Craft::$app->getElements()->saveElement($admin, false);
        $this->loginAs($admin);

        $data = $this->callHistoryEndpoint($entry);

        $this->assertCount(1, $data['history']);
    }

    public function testAnUnknownElementIdIsRejectedRatherThanReturningAnEmptyList(): void
    {
        $this->loginAs($this->createUser('some-editor'));

        Craft::$app->getRequest()->getHeaders()->set('Accept', 'application/json');
        Craft::$app->getRequest()->setIsCpRequest(true);
        Craft::$app->getRequest()->setBodyParams([
            'elementId' => 999999,
            'siteId' => Craft::$app->getSites()->getPrimarySite()->id,
        ]);

        $controller = new EditLogController('edit-log', Plugin::getInstance());

        $this->expectException(NotFoundHttpException::class);
        $controller->actionElementHistory();
    }

    // ---- the dashboard widget ----------------------------------------------

    public function testTheWidgetHidesEditsFromSectionsTheUserCannotView(): void
    {
        $this->createSections();
        $this->createEntry($this->allowed, 'Visible Post');
        $this->createEntry($this->forbidden, 'Hidden Post');

        $this->loginAs($this->createRestrictedEditor());

        $html = (new RecentEditsWidget())->getBodyHtml();

        $this->assertStringContainsString('Visible Post', $html);
        $this->assertStringNotContainsString('Hidden Post', $html);
    }

    public function testTheWidgetShowsEverythingToAnAdmin(): void
    {
        $this->createSections();
        $this->createEntry($this->allowed, 'Visible Post');
        $this->createEntry($this->forbidden, 'Hidden Post');

        $admin = $this->createUser('widget-admin');
        $admin->admin = true;
        Craft::$app->getElements()->saveElement($admin, false);
        $this->loginAs($admin);

        $html = (new RecentEditsWidget())->getBodyHtml();

        $this->assertStringContainsString('Visible Post', $html);
        $this->assertStringContainsString('Hidden Post', $html);
    }

    /**
     * Filtering happens after the query, so a naive "fetch $limit rows, then
     * drop the ones you can't see" would return an empty widget whenever the
     * most recent edits all happen to be hidden. It has to keep reading.
     */
    public function testTheWidgetLooksPastHiddenEditsToFillItsLimit(): void
    {
        $this->createSections();
        $editor = $this->createRestrictedEditor();
        $this->clearLog();

        foreach (range(1, 3) as $i) {
            $this->createEntry($this->allowed, "Visible $i");
        }

        // Newer than everything above, and all invisible to this editor.
        foreach (range(1, 12) as $i) {
            $this->createEntry($this->forbidden, "Hidden $i");
        }

        $visible = Plugin::getInstance()->getEditTracker()->getRecentEditsVisibleTo($editor, 3);

        $this->assertCount(3, $visible);
        foreach ($visible as $edit) {
            $this->assertStringStartsWith('Visible', $edit->elementLabel);
        }
    }

    public function testNoEditsAreVisibleToAnAnonymousRequest(): void
    {
        $this->createSections();
        $this->createEntry($this->allowed, 'Visible Post');

        $this->assertSame(
            [],
            Plugin::getInstance()->getEditTracker()->getRecentEditsVisibleTo(null),
        );
    }
}
