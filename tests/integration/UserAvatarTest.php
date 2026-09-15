<?php

namespace justinholtweb\rat\tests\integration;

use Craft;
use craft\helpers\Db;
use craft\test\TestCase;
use DateTime;
use justinholtweb\rat\controllers\EditLogController;
use justinholtweb\rat\Plugin;
use justinholtweb\rat\tests\support\RatTestTrait;
use justinholtweb\rat\widgets\RecentEditsWidget;

/**
 * Regression coverage for the editor avatars in the sidebar, the widget, and
 * the history endpoint.
 *
 * Craft 5 dropped Asset::getThumbUrl(); thumbnail URLs now come from the
 * assets service, and elements render their own thumb markup via
 * getThumbHtml(). The old calls returned an empty <img> in templates (Twig
 * swallows the exception when devMode is off) and threw outright in the
 * endpoint, so these tests exercise both the photo and no-photo branches.
 */
final class UserAvatarTest extends TestCase
{
    use RatTestTrait;

    protected function _before(): void
    {
        $this->clearLog();
    }

    private function seedEditBy(int $editorId, int $elementId, int $siteId): void
    {
        $this->seedLog([
            'elementId' => $elementId,
            'siteId' => $siteId,
            'userId' => $editorId,
            'dateCreated' => Db::prepareDateForDb(new DateTime()),
        ]);
    }

    public function testTheSidebarRendersAnAvatarForAnEditorWithAPhoto(): void
    {
        $editor = $this->createUserWithPhoto('sidebar-photo-editor');
        $subject = $this->createUser();
        $this->clearLog();
        $this->seedEditBy($editor->id, $subject->id, $subject->siteId);

        $html = $subject->getSidebarHtml(false);

        $this->assertStringContainsString('rat-user-photo-sm', $html);
        // getThumbHtml() defers the <img> to the CP's thumb loader, so the
        // marker of a real photo is the srcset it leaves behind.
        $this->assertStringContainsString('data-srcset', $html);
    }

    public function testTheSidebarFallsBackToInitialsForAnEditorWithoutAPhoto(): void
    {
        $editor = $this->createUser('sidebar-initials-editor', 'Ada Lovelace');
        $subject = $this->createUser();
        $this->clearLog();
        $this->seedEditBy($editor->id, $subject->id, $subject->siteId);

        $html = $subject->getSidebarHtml(false);

        // No photo means an inline SVG carrying the editor's initials, rather
        // than the empty container the old markup produced.
        $this->assertStringContainsString('rat-user-photo-sm', $html);
        $this->assertStringContainsString('<svg', $html);
        $this->assertStringContainsString('AL', $html);
    }

    public function testTheWidgetRendersAvatarsForEditors(): void
    {
        $editor = $this->createUserWithPhoto('widget-photo-editor');
        $subject = $this->createUser();
        $this->clearLog();
        $this->seedEditBy($editor->id, $subject->id, $subject->siteId);

        $admin = $this->createUser('widget-avatar-admin');
        $admin->admin = true;
        Craft::$app->getElements()->saveElement($admin, false);
        $this->loginAs($admin);

        $html = (new RecentEditsWidget())->getBodyHtml();

        $this->assertStringContainsString('rat-user-photo', $html);
        $this->assertStringContainsString('data-srcset', $html);
    }

    public function testTheHistoryEndpointReturnsAThumbnailUrlForAnEditorWithAPhoto(): void
    {
        $editor = $this->createUserWithPhoto('endpoint-photo-editor');
        $subject = $this->createUser();
        $this->clearLog();
        $this->seedEditBy($editor->id, $subject->id, $subject->siteId);

        $admin = $this->createUser('endpoint-avatar-admin');
        $admin->admin = true;
        Craft::$app->getElements()->saveElement($admin, false);
        $this->loginAs($admin);

        Craft::$app->getRequest()->getHeaders()->set('Accept', 'application/json');
        Craft::$app->getRequest()->setIsCpRequest(true);
        Craft::$app->getRequest()->setBodyParams([
            'elementId' => $subject->id,
            'siteId' => $subject->siteId,
        ]);

        $controller = new EditLogController('edit-log', Plugin::getInstance());

        // The pre-fix code raised UnknownMethodException here rather than
        // returning a URL.
        $response = $controller->actionElementHistory();
        $entry = $response->data['history'][0];

        $this->assertNotNull($entry['userPhoto']);
        $this->assertIsString($entry['userPhoto']);
    }
}
