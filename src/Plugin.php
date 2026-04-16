<?php

namespace justinholtweb\rat;

use Craft;
use craft\base\Element;
use craft\base\Plugin as BasePlugin;
use craft\events\DefineHtmlEvent;
use craft\events\ModelEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\services\Dashboard;
use justinholtweb\rat\assets\RatAsset;
use justinholtweb\rat\services\EditTracker;
use justinholtweb\rat\widgets\RecentEditsWidget;
use yii\base\Event;

/**
 * Rat plugin
 *
 * @property-read EditTracker $editTracker
 */
class Plugin extends BasePlugin
{
    public string $schemaVersion = '1.0.0';

    public static function config(): array
    {
        return [
            'components' => [
                'editTracker' => EditTracker::class,
            ],
        ];
    }

    public function getEditTracker(): EditTracker
    {
        return $this->get('editTracker');
    }

    public function init(): void
    {
        parent::init();

        Craft::$app->onInit(function () {
            $this->registerEventListeners();
        });
    }

    private function registerEventListeners(): void
    {
        // Track all element saves
        Event::on(
            Element::class,
            Element::EVENT_AFTER_SAVE,
            function (ModelEvent $event) {
                /** @var Element $element */
                $element = $event->sender;
                $this->getEditTracker()->logEdit($element, $event->isNew);
            },
        );

        // Register dashboard widget
        Event::on(
            Dashboard::class,
            Dashboard::EVENT_REGISTER_WIDGET_TYPES,
            function (RegisterComponentTypesEvent $event) {
                $event->types[] = RecentEditsWidget::class;
            },
        );

        // Inject edit history into element sidebars
        Event::on(
            Element::class,
            Element::EVENT_DEFINE_SIDEBAR_HTML,
            function (DefineHtmlEvent $event) {
                /** @var Element $element */
                $element = $event->sender;

                if (!$element->id) {
                    return;
                }

                $history = $this->getEditTracker()->getElementHistory(
                    $element->id,
                    $element->siteId,
                    10,
                );

                Craft::$app->getView()->registerAssetBundle(RatAsset::class);

                $event->html .= Craft::$app->getView()->renderTemplate(
                    'rat/_sidebar/edit-history',
                    [
                        'history' => $history,
                        'element' => $element,
                        'hasMore' => count($history) >= 10,
                    ],
                );
            },
        );
    }
}
