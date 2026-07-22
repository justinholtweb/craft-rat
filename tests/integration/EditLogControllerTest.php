<?php

namespace justinholtweb\rat\tests\integration;

use Craft;
use craft\helpers\Db;
use craft\test\TestCase;
use DateTime;
use justinholtweb\rat\controllers\EditLogController;
use justinholtweb\rat\Plugin;
use justinholtweb\rat\tests\support\RatTestTrait;
use yii\web\BadRequestHttpException;

/**
 * Coverage of the AJAX endpoint that powers "show more" in the sidebar.
 */
final class EditLogControllerTest extends TestCase
{
    use RatTestTrait;

    private function controller(): EditLogController
    {
        return new EditLogController('edit-log', Plugin::getInstance());
    }

    private function acceptJson(): void
    {
        Craft::$app->getRequest()->getHeaders()->set('Accept', 'application/json');
        Craft::$app->getRequest()->setIsCpRequest(true);
    }

    protected function _before(): void
    {
        $this->clearLog();

        // The endpoint authorizes against the element being asked about, so
        // these shape-of-the-response tests run as an admin. The permission
        // boundary itself is covered by PermissionsTest.
        $admin = $this->createUser('controller-admin');
        $admin->admin = true;
        Craft::$app->getElements()->saveElement($admin, false);
        $this->loginAs($admin);

        $this->clearLog();
    }

    public function testItReturnsSerialisedHistoryForAnElement(): void
    {
        $editor = $this->createUser('endpoint-editor', 'Endpoint Editor');
        $subject = $this->createUser();
        $this->clearLog();

        $date = Db::prepareDateForDb(new DateTime());
        $this->seedLog([
            'elementId' => $subject->id,
            'siteId' => $subject->siteId,
            'userId' => $editor->id,
            'isNew' => true,
            'dirtyAttributes' => json_encode(['title']),
            'dateCreated' => $date,
            'dateUpdated' => $date,
        ]);

        $this->acceptJson();
        Craft::$app->getRequest()->setBodyParams([
            'elementId' => $subject->id,
            'siteId' => $subject->siteId,
        ]);

        $response = $this->controller()->actionElementHistory();
        $data = $response->data;

        $this->assertCount(1, $data['history']);
        $entry = $data['history'][0];
        $this->assertSame($editor->id, $entry['userId']);
        // The endpoint reports Craft's friendly name, which is the first name.
        $this->assertSame('Endpoint', $editor->getFriendlyName());
        $this->assertSame($editor->getFriendlyName(), $entry['userName']);
        $this->assertTrue($entry['isNew']);
        $this->assertSame(['title'], $entry['dirtyAttributes']);
        $this->assertNull($entry['userPhoto']);
        $this->assertNotNull($entry['dateCreated']);
    }

    public function testEditsWithoutAUserAreLabelledSystem(): void
    {
        $subject = $this->createUser();
        $this->clearLog();

        $date = Db::prepareDateForDb(new DateTime());
        $this->seedLog([
            'elementId' => $subject->id,
            'siteId' => $subject->siteId,
            'userId' => null,
            'dateCreated' => $date,
            'dateUpdated' => $date,
        ]);

        $this->acceptJson();
        Craft::$app->getRequest()->setBodyParams([
            'elementId' => $subject->id,
            'siteId' => $subject->siteId,
        ]);

        $data = $this->controller()->actionElementHistory()->data;

        $this->assertSame('System', $data['history'][0]['userName']);
    }

    public function testItPaginates(): void
    {
        $subject = $this->createUser();
        $this->clearLog();

        foreach ([-3, -2, -1] as $hours) {
            $date = Db::prepareDateForDb((new DateTime())->modify("$hours hours"));
            $this->seedLog([
                'elementId' => $subject->id,
                'siteId' => $subject->siteId,
                'dateCreated' => $date,
                'dateUpdated' => $date,
            ]);
        }

        $this->acceptJson();
        Craft::$app->getRequest()->setBodyParams([
            'elementId' => $subject->id,
            'siteId' => $subject->siteId,
            'limit' => 2,
            'offset' => 1,
        ]);

        $data = $this->controller()->actionElementHistory()->data;

        $this->assertCount(2, $data['history']);
    }

    public function testAMissingElementIdIsRejected(): void
    {
        $this->acceptJson();
        Craft::$app->getRequest()->setBodyParams(['siteId' => 1]);

        $this->expectException(BadRequestHttpException::class);
        $this->controller()->actionElementHistory();
    }

    public function testANonJsonRequestIsRejected(): void
    {
        Craft::$app->getRequest()->getHeaders()->set('Accept', 'text/html');
        Craft::$app->getRequest()->setBodyParams(['elementId' => 1, 'siteId' => 1]);

        $this->expectException(BadRequestHttpException::class);
        $this->controller()->actionElementHistory();
    }
}
