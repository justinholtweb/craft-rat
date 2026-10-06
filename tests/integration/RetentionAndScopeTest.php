<?php

namespace justinholtweb\rat\tests\integration;

use Craft;
use craft\controllers\ElementIndexesController;
use craft\elements\User;
use craft\helpers\Db;
use craft\services\Gc;
use craft\test\TestCase;
use DateTime;
use justinholtweb\rat\models\EditLog;
use justinholtweb\rat\models\Settings;
use justinholtweb\rat\Plugin;
use justinholtweb\rat\services\EditTracker;
use justinholtweb\rat\tests\support\RatTestTrait;
use yii\base\Event;

/**
 * What Rat records, how long it keeps it, and what it costs to read back.
 *
 * Until 5.1.3: nothing ever called cleanupOldLogs(), so the log grew forever; every front-end save
 * was logged, so a store added rows per cart action; the cutoff was built in PHP's time zone
 * against UTC dates; and the Recent Edits widget and the Last Editor column ran a query per row.
 */
final class RetentionAndScopeTest extends TestCase
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
        Craft::$app->controller = null;
    }

    private function seedDaysAgo(float $days): int
    {
        $date = new DateTime('@' . (int)(time() - $days * 86400));

        return $this->seedLog(['dateCreated' => Db::prepareDateForDb($date), 'dateUpdated' => Db::prepareDateForDb($date)]);
    }

    private function remainingIds(): array
    {
        return array_map('intval', (new \craft\db\Query())->select('id')->from('{{%rat_editlog}}')->column());
    }

    // ---- retention ---------------------------------------------------------

    public function testTheDefaultsKeepNinetyDaysAndSkipCartsAndAnonymousSaves(): void
    {
        $defaults = new Settings();

        $this->assertSame(90, $defaults->retentionDays);
        $this->assertFalse($defaults->trackAnonymousSiteSaves);
        $this->assertContains('craft\\commerce\\elements\\Order', $defaults->excludedElementTypes);
    }

    public function testGarbageCollectionPrunesTheLog(): void
    {
        // Only Rat is installed in this suite's Craft, so firing the event runs Rat's handler alone.
        $old = $this->seedDaysAgo(120);
        $recent = $this->seedDaysAgo(10);

        Event::trigger(Gc::class, Gc::EVENT_RUN);

        $this->assertSame([$recent], $this->remainingIds());
        $this->assertNotContains($old, $this->remainingIds());
    }

    public function testPruningFollowsTheRetentionSetting(): void
    {
        $this->settings->retentionDays = 30;
        $this->seedDaysAgo(40);
        $kept = $this->seedDaysAgo(20);

        $this->assertSame(1, Plugin::getInstance()->pruneEditLog());
        $this->assertSame([$kept], $this->remainingIds());
    }

    public function testRetentionZeroKeepsEverything(): void
    {
        $this->settings->retentionDays = 0;
        $this->seedDaysAgo(4000);

        $this->assertSame(0, Plugin::getInstance()->pruneEditLog());
        $this->assertCount(1, $this->remainingIds());
    }

    public function testTheCutoffIsInUtcWhateverTheServersTimeZone(): void
    {
        // Craft stores dates in UTC. Until 5.1.3 the cutoff was formatted in PHP's zone, so on a
        // server seven hours behind UTC a row three hours past the window survived.
        $zone = Craft::$app->getTimeZone();
        Craft::$app->setTimeZone('America/Los_Angeles');

        try {
            $justOut = $this->seedDaysAgo(90 + 3 / 24);
            $justIn = $this->seedDaysAgo(90 - 3 / 24);

            $this->tracker()->cleanupOldLogs(90);
        } finally {
            Craft::$app->setTimeZone($zone);
        }

        $this->assertSame([$justIn], $this->remainingIds());
        $this->assertNotContains($justOut, $this->remainingIds());
    }

    public function testPruningWorksThroughALogLargerThanOneChunk(): void
    {
        $date = Db::prepareDateForDb(new DateTime('-200 days'));
        $rows = [];
        for ($i = 0; $i < 5003; $i++) {
            $rows[] = [1, 1, User::class, 'Old', false, $date, $date, \craft\helpers\StringHelper::UUID()];
        }
        Craft::$app->getDb()->createCommand()->batchInsert('{{%rat_editlog}}',
            ['elementId', 'siteId', 'elementType', 'elementLabel', 'isNew', 'dateCreated', 'dateUpdated', 'uid'], $rows)->execute();
        $kept = $this->seedDaysAgo(1);

        $this->assertSame(5003, $this->tracker()->cleanupOldLogs(90));
        $this->assertSame([$kept], $this->remainingIds());
    }

    // ---- what gets recorded ------------------------------------------------

    public function testExcludedElementTypesAreNotRecorded(): void
    {
        $this->settings->excludedElementTypes = [User::class];
        $user = $this->createUser('excluded-type');

        $this->assertSame([], $this->logRowsFor($user->id));
    }

    public function testTheExclusionListAcceptsATextareaAndLeadingBackslashes(): void
    {
        $settings = new Settings();
        $settings->setAttributes(['excludedElementTypes' => "\\craft\\elements\\User\n\n craft\\elements\\Asset "]);

        $this->assertSame(['craft\\elements\\User', 'craft\\elements\\Asset'], $settings->excludedElementTypes);
    }

    public function testAnExcludedClassThatIsntInstalledIsHarmless(): void
    {
        // The Commerce default on a site without Commerce.
        $this->settings->excludedElementTypes = ['craft\\commerce\\elements\\Order'];
        $user = $this->createUser('no-commerce');

        $this->assertCount(1, $this->logRowsFor($user->id));
    }

    public function testAnonymousFrontEndSavesAreSkippedUnlessSwitchedOn(): void
    {
        $tracker = new class() extends EditTracker {
            public function isAnonymousSiteRequest(): bool
            {
                return true;
            }
        };
        $user = $this->createUser('anon-subject');

        $this->assertFalse($tracker->shouldTrack($user), 'an anonymous front-end save');

        $this->settings->trackAnonymousSiteSaves = true;
        $this->assertTrue($tracker->shouldTrack($user), 'with the setting on');
    }

    public function testConsoleSavesAreNotTreatedAsAnonymousFrontEndSaves(): void
    {
        $this->assertFalse($this->tracker()->isAnonymousSiteRequest());
    }

    public function testSavesInsideAQueueJobAreRecordedOnAnAnonymousRequest(): void
    {
        // Craft runs the queue from a cookieless site request when it runs it automatically.
        $original = Craft::$app->getRequest();
        Craft::$app->set('request', $this->siteRequest());
        $this->logout();

        try {
            $outside = $this->tracker()->isAnonymousSiteRequest();
            Event::trigger(\yii\queue\Queue::class, \yii\queue\Queue::EVENT_BEFORE_EXEC, new \yii\queue\ExecEvent());
            $inside = $this->tracker()->isAnonymousSiteRequest();
            Event::trigger(\yii\queue\Queue::class, \yii\queue\Queue::EVENT_AFTER_EXEC, new \yii\queue\ExecEvent());
            $after = $this->tracker()->isAnonymousSiteRequest();
        } finally {
            Craft::$app->set('request', $original);
            $this->tracker()->queueDepth = 0;
        }

        $this->assertTrue($outside, 'anonymous, outside a job');
        $this->assertFalse($inside, 'inside a job');
        $this->assertTrue($after, 'and anonymous again once the job is done');
    }

    public function testASignedInFrontEndSaveIsStillRecorded(): void
    {
        $request = $this->siteRequest();
        $original = Craft::$app->getRequest();
        Craft::$app->set('request', $request);

        try {
            $editor = $this->createUser('front-end-editor');
            $this->loginAs($editor);
            $signedIn = $this->tracker()->isAnonymousSiteRequest();
            $this->logout();
            $anonymous = $this->tracker()->isAnonymousSiteRequest();
        } finally {
            Craft::$app->set('request', $original);
        }

        $this->assertFalse($signedIn, 'a signed-in visitor on the front end');
        $this->assertTrue($anonymous, 'an anonymous visitor on the front end');
    }

    // ---- reading it back ---------------------------------------------------

    /**
     * A front-end web request. Yii decides "console" from PHP_SAPI unless told, and the suite runs
     * under the CLI, so a bare web Request would say it was a console one.
     */
    private function siteRequest(): \craft\web\Request
    {
        return new \craft\web\Request(['isCpRequest' => false, 'isConsoleRequest' => false]);
    }

    /**
     * Statements the database ran during `$fn`, from MySQL's own per-connection counter. (Yii's
     * profiling can't be used: Craft flushes the logger as it goes, so nothing accumulates.)
     */
    private function queryCount(callable $fn): int
    {
        $questions = static fn() => (int)Craft::$app->getDb()->createCommand("SHOW SESSION STATUS LIKE 'Questions'")->queryOne()['Value'];
        $before = $questions();
        $fn();

        // Less the second SHOW itself.
        return $questions() - $before - 1;
    }

    public function testPreloadingLeavesNothingToQueryPerRow(): void
    {
        $section = $this->createSection();
        $editors = [$this->createUser('ed-one'), $this->createUser('ed-two')];
        for ($i = 0; $i < 6; $i++) {
            $this->loginAs($editors[$i % 2]);
            $this->createEntry($section, "Entry $i");
        }
        $this->logout();
        $edits = $this->tracker()->getRecentEdits(50);
        $this->assertGreaterThan(6, count($edits));

        $preloadQueries = $this->queryCount(fn() => $this->tracker()->preload($edits));
        $perRowQueries = $this->queryCount(function() use ($edits) {
            foreach ($edits as $edit) {
                $edit->getElement();
                $edit->getUser();
            }
        });

        $this->assertGreaterThan(0, $preloadQueries, 'query profiling must be on for this test to mean anything');
        $this->assertLessThanOrEqual(6, $preloadQueries, 'one query per element type and site, and one for users');
        $this->assertSame(0, $perRowQueries);
    }

    public function testTheWidgetsQueryCountDoesntGrowWithTheRows(): void
    {
        $section = $this->createSection();
        $editor = $this->createUser('widget-editor');
        $this->loginAs($editor);
        for ($i = 0; $i < 30; $i++) {
            $this->createEntry($section, "Widget $i");
        }
        $admin = User::find()->admin(true)->status(null)->one() ?? $this->createUser('widget-admin');
        $admin->admin = true;

        $queries = $this->queryCount(fn() => $this->tracker()->getRecentEditsVisibleTo($admin, 30));

        // One per row would be 30 for the elements and 30 again for their editors.
        $this->assertLessThan(15, $queries);
    }

    public function testPreloadedElementsAndEditorsAreTheRightOnes(): void
    {
        $section = $this->createSection();
        $editor = $this->createUser('the-editor');
        $this->loginAs($editor);
        $entry = $this->createEntry($section, 'Preloaded');
        $this->logout();

        $edits = array_values(array_filter($this->tracker()->getRecentEdits(50), fn(EditLog $e) => $e->elementId === $entry->id));
        $this->tracker()->preload($edits);

        $this->assertSame($entry->id, $edits[0]->getElement()?->id);
        $this->assertSame($editor->id, $edits[0]->getUser()?->id);
    }

    public function testTheLastEditorColumnIsFetchedForAWholePageAtOnce(): void
    {
        $users = [];
        for ($i = 0; $i < 5; $i++) {
            $users[] = $this->createUser("page-$i");
        }
        $editor = $this->createUser('page-editor', 'Page Editor');
        $this->loginAs($editor);
        $users[0]->fullName = 'Edited Again';
        Craft::$app->getElements()->saveElement($users[0], false);
        $this->logout();

        $tracker = $this->tracker();
        $expected = [];
        foreach ($users as $user) {
            $expected[$user->id] = (new EditTracker())->getLastEditor($user->id, $user->siteId);
        }

        // As the element index loads the page.
        Craft::$app->controller = new ElementIndexesController('element-indexes', Craft::$app);
        User::find()->id(array_map(fn(User $u) => $u->id, $users))->status(null)->all();

        $cellQueries = $this->queryCount(function() use ($tracker, $users, &$actual) {
            foreach ($users as $user) {
                $actual[$user->id] = $tracker->getLastEditor($user->id, $user->siteId);
            }
        });

        $this->assertSame(0, $cellQueries, 'the cells should all come from the prefetch');
        $this->assertSame('Page Editor', $actual[$users[0]->id]['fullName']);
        foreach ($users as $user) {
            $this->assertSame($expected[$user->id]['userId'] ?? null, $actual[$user->id]['userId'] ?? null);
        }
    }

    public function testOutsideTheElementIndexNothingIsPrefetched(): void
    {
        $user = $this->createUser('not-indexed');
        $tracker = $this->tracker();

        User::find()->id($user->id)->status(null)->all();

        $this->assertGreaterThan(0, $this->queryCount(fn() => $tracker->getLastEditor($user->id, $user->siteId)));
    }

    public function testAnElementWithNoHistoryIsPrefetchedAsNone(): void
    {
        $user = $this->createUser('no-history');
        $this->clearLog();
        $tracker = $this->tracker();

        $tracker->prefetchLastEditors([$user]);

        $this->assertSame(0, $this->queryCount(fn() => $this->assertNull($tracker->getLastEditor($user->id, $user->siteId))));
    }
}
