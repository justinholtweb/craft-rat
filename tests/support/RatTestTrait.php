<?php

namespace justinholtweb\rat\tests\support;

use Craft;
use craft\elements\Entry;
use craft\elements\User;
use craft\helpers\Db;
use craft\fieldlayoutelements\entries\EntryTitleField;
use craft\helpers\StringHelper;
use craft\models\EntryType;
use craft\models\FieldLayout;
use craft\models\FieldLayoutTab;
use craft\models\Section;
use craft\models\Section_SiteSettings;
use DateTime;
use justinholtweb\rat\Plugin;
use justinholtweb\rat\services\EditTracker;

/**
 * Shared helpers for the integration suite: building real elements to save,
 * and seeding the edit log directly when a test needs control over timestamps.
 */
trait RatTestTrait
{
    protected function tracker(): EditTracker
    {
        return Plugin::getInstance()->getEditTracker();
    }

    /**
     * Creates and saves a real User element. Users are the cheapest real
     * element to work with — no section, entry type, or field layout needed —
     * and they exercise the same catch-all save listener as everything else.
     */
    protected function createUser(?string $username = null, ?string $fullName = null): User
    {
        $username ??= 'user-' . StringHelper::randomString(10);

        $user = new User();
        $user->username = $username;
        $user->email = "$username@example.com";
        $user->fullName = $fullName;
        $user->active = true;

        if (!Craft::$app->getElements()->saveElement($user, false)) {
            $this->fail('Could not save test user: ' . json_encode($user->getErrors()));
        }

        return $user;
    }

    /**
     * Provisions a channel section with a single entry type, so tests have a
     * real Entry to work with. Entries are the element type this plugin is
     * mostly used on, and unlike users they support drafts and revisions.
     */
    protected function createSection(string $name = 'Rat Test Posts', string $handle = 'ratTestPosts'): Section
    {
        $entriesService = Craft::$app->getEntries();

        $entryType = new EntryType([
            'name' => "$name Type",
            'handle' => "{$handle}Type",
        ]);

        // Craft derives hasTitleField from the field layout, so an entry type
        // without an explicit title field saves entries with no title at all.
        $fieldLayout = new FieldLayout(['type' => Entry::class]);
        $fieldLayout->setTabs([
            new FieldLayoutTab([
                'layout' => $fieldLayout,
                'name' => 'Content',
                'elements' => [new EntryTitleField()],
            ]),
        ]);
        $entryType->setFieldLayout($fieldLayout);

        if (!$entriesService->saveEntryType($entryType)) {
            $this->fail('Could not save test entry type: ' . json_encode($entryType->getErrors()));
        }

        $section = new Section([
            'name' => $name,
            'handle' => $handle,
            'type' => Section::TYPE_CHANNEL,
            'enableVersioning' => true,
            'entryTypes' => [$entryType],
            'siteSettings' => [
                new Section_SiteSettings([
                    'siteId' => Craft::$app->getSites()->getPrimarySite()->id,
                    'hasUrls' => false,
                ]),
            ],
        ]);

        if (!$entriesService->saveSection($section)) {
            $this->fail('Could not save test section: ' . json_encode($section->getErrors()));
        }

        return $section;
    }

    protected function createEntry(Section $section, string $title = 'Test Entry'): Entry
    {
        $entry = new Entry();
        $entry->sectionId = $section->id;
        $entry->typeId = $section->getEntryTypes()[0]->id;
        $entry->title = $title;

        if (!Craft::$app->getElements()->saveElement($entry, false)) {
            $this->fail('Could not save test entry: ' . json_encode($entry->getErrors()));
        }

        return $entry;
    }

    /**
     * Signs a user in for the remainder of the test, so logEdit() attributes
     * saves to them the way a real control-panel request would.
     */
    protected function loginAs(User $user): void
    {
        Craft::$app->getUser()->setIdentity($user);
    }

    protected function logout(): void
    {
        Craft::$app->getUser()->setIdentity(null);
    }

    /**
     * Inserts an edit-log row directly, bypassing the save listener. Used when
     * a test needs to pin dateCreated to a specific moment.
     */
    protected function seedLog(array $overrides = []): int
    {
        $now = new DateTime();

        $row = array_merge([
            'elementId' => 1,
            'siteId' => Craft::$app->getSites()->getPrimarySite()->id,
            'userId' => null,
            'elementType' => User::class,
            'elementLabel' => 'Seeded',
            'isNew' => false,
            'dirtyAttributes' => null,
            'dateCreated' => Db::prepareDateForDb($now),
            'dateUpdated' => Db::prepareDateForDb($now),
            'uid' => StringHelper::UUID(),
        ], $overrides);

        Craft::$app->getDb()->createCommand()
            ->insert('{{%rat_editlog}}', $row)
            ->execute();

        return (int)Craft::$app->getDb()->getLastInsertID('{{%rat_editlog}}');
    }

    protected function clearLog(): void
    {
        Craft::$app->getDb()->createCommand()
            ->delete('{{%rat_editlog}}')
            ->execute();
    }

    protected function logRowsFor(int $elementId): array
    {
        return (new \craft\db\Query())
            ->from('{{%rat_editlog}}')
            ->where(['elementId' => $elementId])
            ->orderBy(['id' => SORT_ASC])
            ->all();
    }
}
