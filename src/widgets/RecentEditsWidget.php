<?php

namespace justinholtweb\rat\widgets;

use Craft;
use craft\base\Widget;
use justinholtweb\rat\Plugin;
use justinholtweb\rat\assets\RatAsset;

class RecentEditsWidget extends Widget
{
    public int $limit = 20;

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

        return $rules;
    }

    public function getSettingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('rat/_widgets/recent-edits-settings', [
            'widget' => $this,
        ]);
    }

    public function getBodyHtml(): ?string
    {
        Craft::$app->getView()->registerAssetBundle(RatAsset::class);

        $edits = Plugin::getInstance()->getEditTracker()->getRecentEdits($this->limit);

        return Craft::$app->getView()->renderTemplate('rat/_widgets/recent-edits', [
            'edits' => $edits,
        ]);
    }
}
