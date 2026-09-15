<?php

namespace justinholtweb\rat\tests\support;

use Craft;
use craft\elements\Asset;
use craft\elements\Entry;
use craft\elements\User;
use craft\fs\Local;
use craft\helpers\Db;
use craft\fieldlayoutelements\entries\EntryTitleField;
use craft\helpers\FileHelper;
use craft\helpers\StringHelper;
use craft\models\EntryType;
use craft\models\FieldLayout;
use craft\models\FieldLayoutTab;
use craft\models\Section;
use craft\models\Section_SiteSettings;
use craft\models\Volume;
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
     * Creates a user with a real photo asset attached. The avatar markup takes
     * a different branch for users who have a photo, and that branch is the one
     * that broke on Craft 5 (see the getThumbUrl() regression), so it needs a
     * genuine asset behind it rather than a stubbed photoId.
     */
    protected function createUserWithPhoto(?string $username = null): User
    {
        $user = $this->createUser($username);

        $photo = $this->createImageAsset();

        // Saving a user with a photo makes Craft relocate it into the
        // configured user photo volume, so point that at the test volume.
        Craft::$app->getProjectConfig()->set('users.photoVolumeUid', $photo->getVolume()->uid);

        $user->photoId = $photo->id;

        if (!Craft::$app->getElements()->saveElement($user, false)) {
            $this->fail('Could not attach photo to test user: ' . json_encode($user->getErrors()));
        }

        return $user;
    }

    /**
     * Saves a small generated PNG into a throwaway local volume and returns it.
     */
    protected function createImageAsset(): Asset
    {
        $volume = $this->assetVolume();
        $folder = Craft::$app->getAssets()->getRootFolderByVolumeId($volume->id);

        if ($folder === null) {
            $this->fail('Test asset volume has no root folder.');
        }

        $tempPath = Craft::$app->getPath()->getTempPath()
            . DIRECTORY_SEPARATOR . StringHelper::randomString(10) . '.png';

        $image = imagecreatetruecolor(40, 40);
        imagefill($image, 0, 0, imagecolorallocate($image, 120, 160, 200));
        imagepng($image, $tempPath);
        imagedestroy($image);

        $asset = new Asset();
        $asset->setScenario(Asset::SCENARIO_CREATE);
        $asset->tempFilePath = $tempPath;
        $asset->setFilename(StringHelper::randomString(10) . '.png');
        $asset->newFolderId = $folder->id;
        $asset->setVolumeId($volume->id);

        if (!Craft::$app->getElements()->saveElement($asset, false)) {
            $this->fail('Could not save test asset: ' . json_encode($asset->getErrors()));
        }

        return $asset;
    }

    /**
     * Lazily provisions (and reuses) a local filesystem + volume for test assets.
     */
    protected function assetVolume(): Volume
    {
        $volumesService = Craft::$app->getVolumes();
        $existing = $volumesService->getVolumeByHandle('ratTestAssets');

        if ($existing !== null) {
            return $existing;
        }

        // Craft refuses local filesystems rooted inside its system directories,
        // and getTestsPath() and the runtime path are both on that list — so the
        // volume has to live outside the project entirely.
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'rat-test-assets';
        FileHelper::createDirectory($path);

        $fs = new Local([
            'name' => 'Rat Test Assets',
            'handle' => 'ratTestAssets',
            'path' => $path,
        ]);

        if (!Craft::$app->getFs()->saveFilesystem($fs)) {
            $this->fail('Could not save test filesystem: ' . json_encode($fs->getErrors()));
        }

        $volume = new Volume([
            'name' => 'Rat Test Assets',
            'handle' => 'ratTestAssets',
            'fsHandle' => 'ratTestAssets',
        ]);

        if (!$volumesService->saveVolume($volume)) {
            $this->fail('Could not save test volume: ' . json_encode($volume->getErrors()));
        }

        return $volume;
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
