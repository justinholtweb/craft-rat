<?php

namespace justinholtweb\rat\widgets;

use Craft;
use craft\base\Widget;
use justinholtweb\rat\assets\RatAsset;
use justinholtweb\rat\models\EditLog;
use justinholtweb\rat\Plugin;

class RecentEditsWidget extends Widget
{
    public int $limit = 20;

    /**
     * `all`, or `deletions` for deletes only — to the trash and permanent.
     */
    public string $show = 'all';

    public static function displayName(): string
    {
        return 'Recent Edits';
    }

    public static function icon(): ?string
    {
        return Craft::getAlias('@justinholtweb/rat/icon-mask.svg');
    }

    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['limit'], 'integer', 'min' => 1, 'max' => 100];
        $rules[] = [['show'], 'in', 'range' => ['all', 'deletions']];

        return $rules;
    }

    public function getSettingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('rat/_widgets/recent-edits-settings', [
            'widget' => $this,
        ]);
    }

    public function getTitle(): ?string
    {
        return $this->show === 'deletions' ? Craft::t('rat', 'Recent Deletions') : parent::getTitle();
    }

    public function getBodyHtml(): ?string
    {
        Craft::$app->getView()->registerAssetBundle(RatAsset::class);

        // Only show edits to elements this user could open themselves.
        $edits = Plugin::getInstance()->getEditTracker()->getRecentEditsVisibleTo(
            Craft::$app->getUser()->getIdentity(),
            $this->limit,
            $this->show === 'deletions' ? [EditLog::ACTION_DELETE, EditLog::ACTION_HARD_DELETE] : null,
        );

        return Craft::$app->getView()->renderTemplate('rat/_widgets/recent-edits', [
            'edits' => $edits,
            'deletionsOnly' => $this->show === 'deletions',
        ]);
    }
}
