<?php

namespace justinholtweb\rat\tests\integration;

use Craft;
use craft\base\Element;
use craft\elements\User;
use craft\events\DefineAttributeHtmlEvent;
use craft\events\RegisterElementSortOptionsEvent;
use craft\events\RegisterElementTableAttributesEvent;
use craft\helpers\Db;
use craft\test\TestCase;
use DateTime;
use justinholtweb\rat\Plugin;
use justinholtweb\rat\services\EditTracker;
use justinholtweb\rat\tests\support\RatTestTrait;
use justinholtweb\rat\widgets\RecentEditsWidget;
use yii\base\Event;
use yii\db\Expression;

/**
 * Coverage of the plugin's event wiring and the pieces the control panel talks
 * to: the service container, the index column/sort registrations, and the
 * dashboard widget.
 */
final class PluginWiringTest extends TestCase
{
    use RatTestTrait;

    protected function _before(): void
    {
        $this->clearLog();
        $this->logout();
    }

    public function testTheEditTrackerServiceIsWiredAndSingleton(): void
    {
        $plugin = Plugin::getInstance();

        $this->assertInstanceOf(EditTracker::class, $plugin->getEditTracker());
        $this->assertSame($plugin->getEditTracker(), $plugin->getEditTracker());
    }

    public function testTheLastEditorColumnIsOfferedOnElementIndexes(): void
    {
        $event = new RegisterElementTableAttributesEvent(['tableAttributes' => []]);
        Event::trigger(Element::class, Element::EVENT_REGISTER_TABLE_ATTRIBUTES, $event);

        $this->assertArrayHasKey(EditTracker::LAST_EDITOR_ATTRIBUTE, $event->tableAttributes);
        $this->assertSame('Last Editor', $event->tableAttributes[EditTracker::LAST_EDITOR_ATTRIBUTE]['label']);
    }

    public function testTheLastEditorSortOptionIsRegisteredAndBuildsAnExpression(): void
    {
        $event = new RegisterElementSortOptionsEvent(['sortOptions' => []]);
        Event::trigger(Element::class, Element::EVENT_REGISTER_SORT_OPTIONS, $event);

        $option = null;
        foreach ($event->sortOptions as $candidate) {
            if (($candidate['attribute'] ?? null) === EditTracker::LAST_EDITOR_ATTRIBUTE) {
                $option = $candidate;
                break;
            }
        }

        $this->assertNotNull($option, 'The Last Editor sort option should be registered.');
        $this->assertInstanceOf(Expression::class, ($option['orderBy'])(SORT_ASC));
    }

    public function testTheColumnCellRendersThroughTheElementEvent(): void
    {
        $editor = $this->createUser('column-editor', 'Grace Hopper');
        $subject = $this->createUser();
        $this->clearLog();

        $date = Db::prepareDateForDb(new DateTime());
        $this->seedLog([
            'elementId' => $subject->id,
            'siteId' => $subject->siteId,
            'userId' => $editor->id,
            'dateCreated' => $date,
            'dateUpdated' => $date,
        ]);

        $html = $subject->getAttributeHtml(EditTracker::LAST_EDITOR_ATTRIBUTE);

        $this->assertStringContainsString('Grace Hopper', $html);
    }

    public function testTheColumnCellIsBlankForAnUnsavedElement(): void
    {
        $event = new DefineAttributeHtmlEvent([
            'attribute' => EditTracker::LAST_EDITOR_ATTRIBUTE,
            'html' => 'untouched',
        ]);
        Event::trigger(new User(), Element::EVENT_DEFINE_ATTRIBUTE_HTML, $event);

        $this->assertSame('', $event->html);
    }

    public function testOtherColumnsAreLeftAlone(): void
    {
        $event = new DefineAttributeHtmlEvent([
            'attribute' => 'someoneElsesColumn',
            'html' => 'untouched',
        ]);
        Event::trigger(new User(), Element::EVENT_DEFINE_ATTRIBUTE_HTML, $event);

        $this->assertSame('untouched', $event->html);
    }

    public function testTheDashboardWidgetIsRegistered(): void
    {
        $this->assertContains(
            RecentEditsWidget::class,
            Craft::$app->getDashboard()->getAllWidgetTypes(),
        );
    }

    public function testTheWidgetIconResolvesToAFileThatExists(): void
    {
        $icon = RecentEditsWidget::icon();

        $this->assertIsString($icon);
        $this->assertFileExists($icon);
    }

    public function testTheWidgetRejectsAnOutOfRangeLimit(): void
    {
        $this->assertFalse((new RecentEditsWidget(['limit' => 0]))->validate());
        $this->assertFalse((new RecentEditsWidget(['limit' => 101]))->validate());
        $this->assertTrue((new RecentEditsWidget(['limit' => 20]))->validate());
    }

    public function testTheWidgetRendersRecentEdits(): void
    {
        $editor = $this->createUser('widget-editor', 'Widget Editor');
        $this->loginAs($editor);
        $subject = $this->createUser('widget-subject');

        $html = (new RecentEditsWidget())->getBodyHtml();

        $this->assertIsString($html);
        $this->assertStringContainsString('Widget Editor', $html);
    }

    public function testTheSidebarHistoryIsInjectedForSavedElements(): void
    {
        $editor = $this->createUser('sidebar-editor', 'Sidebar Editor');
        $this->loginAs($editor);
        $subject = $this->createUser('sidebar-subject');

        $html = $subject->getSidebarHtml(false);

        $this->assertStringContainsString('rat-edit-history', $html);
        $this->assertStringContainsString($editor->getFriendlyName(), $html);
    }
}
