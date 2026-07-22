<?php

namespace justinholtweb\rat\tests\integration;

use Craft;
use craft\elements\User;
use craft\test\TestCase;
use justinholtweb\rat\models\EditLog;
use justinholtweb\rat\tests\support\RatTestTrait;

/**
 * The EditLog model's Craft-backed accessors and validation rules. The pure
 * presentation helpers are covered by the unit suite.
 */
final class EditLogModelTest extends TestCase
{
    use RatTestTrait;

    public function testGetUserResolvesTheEditor(): void
    {
        $editor = $this->createUser('model-editor', 'Model Editor');

        $log = new EditLog();
        $log->userId = $editor->id;

        $this->assertSame($editor->id, $log->getUser()->id);
    }

    public function testGetUserReturnsNullForAMissingUser(): void
    {
        $log = new EditLog();
        $log->userId = 999999;

        $this->assertNull($log->getUser());
    }

    public function testGetElementResolvesTheTrackedElement(): void
    {
        $element = $this->createUser();

        $log = new EditLog();
        $log->elementId = $element->id;
        $log->elementType = User::class;
        $log->siteId = $element->siteId;

        $this->assertSame($element->id, $log->getElement()->id);
    }

    public function testGetElementReturnsNullForAMissingElement(): void
    {
        $log = new EditLog();
        $log->elementId = 999999;
        $log->elementType = User::class;
        $log->siteId = Craft::$app->getSites()->getPrimarySite()->id;

        $this->assertNull($log->getElement());
    }

    public function testValidationRequiresTheCoreIdentifiers(): void
    {
        $log = new EditLog();

        $this->assertFalse($log->validate());
        $this->assertArrayHasKey('elementId', $log->getErrors());
        $this->assertArrayHasKey('siteId', $log->getErrors());
        $this->assertArrayHasKey('elementType', $log->getErrors());
    }

    public function testAFullyPopulatedLogValidates(): void
    {
        $log = new EditLog();
        $log->elementId = 1;
        $log->siteId = 1;
        $log->elementType = User::class;
        $log->elementLabel = 'Someone';
        $log->isNew = true;

        $this->assertTrue($log->validate(), json_encode($log->getErrors()));
    }

    public function testAnOverlongLabelFailsValidation(): void
    {
        $log = new EditLog();
        $log->elementId = 1;
        $log->siteId = 1;
        $log->elementType = User::class;
        $log->elementLabel = str_repeat('a', 256);

        $this->assertFalse($log->validate());
        $this->assertArrayHasKey('elementLabel', $log->getErrors());
    }
}
