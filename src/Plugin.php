<?php

namespace justinholtweb\rat;

use Craft;
use craft\base\Element;
use craft\base\Plugin as BasePlugin;
use craft\events\DefineAttributeHtmlEvent;
use craft\events\DefineHtmlEvent;
use craft\events\ModelEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterElementSortOptionsEvent;
use craft\events\RegisterElementTableAttributesEvent;
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

        // Make the "Last Editor" column available on every element index
        Event::on(
            Element::class,
            Element::EVENT_REGISTER_TABLE_ATTRIBUTES,
            function (RegisterElementTableAttributesEvent $event) {
                $event->tableAttributes[EditTracker::LAST_EDITOR_ATTRIBUTE] = [
                    'label' => Craft::t('rat', 'Last Editor'),
                ];
            },
        );

        // Render the "Last Editor" column cell
        Event::on(
            Element::class,
            Element::EVENT_DEFINE_ATTRIBUTE_HTML,
            function (DefineAttributeHtmlEvent $event) {
                if ($event->attribute !== EditTracker::LAST_EDITOR_ATTRIBUTE) {
                    return;
                }

                /** @var Element $element */
                $element = $event->sender;

                if (!$element->id || !$element->siteId) {
                    $event->html = '';
                    return;
                }

                $event->html = $this->getEditTracker()->getLastEditorCellHtml(
                    $element->id,
                    $element->siteId,
                );
            },
        );

        // Allow sorting element indexes by who made the last edit
        Event::on(
            Element::class,
            Element::EVENT_REGISTER_SORT_OPTIONS,
            function (RegisterElementSortOptionsEvent $event) {
                $event->sortOptions[] = [
                    'label' => Craft::t('rat', 'Last Editor'),
                    'orderBy' => fn(int $dir) => $this->getEditTracker()->getLastEditorSortOrderBy($dir),
                    'attribute' => EditTracker::LAST_EDITOR_ATTRIBUTE,
                ];
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

                $pageSize = 10;

                // One extra row tells us whether "View more..." has anything
                // left to fetch, rather than guessing from a full page.
                $history = $this->getEditTracker()->getElementHistory(
                    $element->id,
                    $element->siteId,
                    $pageSize + 1,
                );

                $hasMore = count($history) > $pageSize;

                if ($hasMore) {
                    array_pop($history);
                }

                $view = Craft::$app->getView();
                $view->registerAssetBundle(RatAsset::class);
                $view->registerTranslations('rat', [
                    'Couldn’t load more edit history.',
                ]);

                $event->html .= $view->renderTemplate(
                    'rat/_sidebar/edit-history',
                    [
                        'history' => $history,
                        'element' => $element,
                        'hasMore' => $hasMore,
                        'pageSize' => $pageSize,
                    ],
                );
            },
        );
    }
}
