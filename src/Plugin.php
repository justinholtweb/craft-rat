<?php

namespace justinholtweb\rat;

use Craft;
use craft\base\Element;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\controllers\ElementIndexesController;
use craft\elements\db\ElementQuery;
use craft\events\DefineAttributeHtmlEvent;
use craft\events\DefineHtmlEvent;
use craft\events\ModelEvent;
use craft\events\PopulateElementsEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterElementSortOptionsEvent;
use craft\events\RegisterElementTableAttributesEvent;
use craft\services\Dashboard;
use craft\services\Gc;
use justinholtweb\rat\assets\RatAsset;
use justinholtweb\rat\models\Settings;
use justinholtweb\rat\services\EditTracker;
use justinholtweb\rat\widgets\RecentEditsWidget;
use yii\base\Event;
use yii\queue\Queue;

/**
 * Rat plugin
 *
 * @property-read EditTracker $editTracker
 * @method Settings getSettings()
 */
class Plugin extends BasePlugin
{
    public string $schemaVersion = '1.0.0';

    public bool $hasCpSettings = true;

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

        Craft::$app->onInit(function() {
            $this->registerEventListeners();
        });
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('rat/_settings', [
            'settings' => $this->getSettings(),
        ]);
    }

    /**
     * Prunes the edit log to `retentionDays`. Runs on Craft's garbage collection — until 5.1.3
     * nothing pruned the log at all, so it only ever grew.
     *
     * @return int rows deleted
     */
    public function pruneEditLog(): int
    {
        $days = $this->getSettings()->retentionDays;

        return $days > 0 ? $this->getEditTracker()->cleanupOldLogs($days) : 0;
    }

    private function registerEventListeners(): void
    {
        Event::on(Gc::class, Gc::EVENT_RUN, function() {
            $this->pruneEditLog();
        });

        // Saves made by queue jobs are recorded whatever request happens to be running the queue.
        Event::on(Queue::class, Queue::EVENT_BEFORE_EXEC, function() {
            $this->getEditTracker()->queueDepth++;
        });
        $leaveJob = function() {
            $this->getEditTracker()->queueDepth = max(0, $this->getEditTracker()->queueDepth - 1);
        };
        Event::on(Queue::class, Queue::EVENT_AFTER_EXEC, $leaveJob);
        Event::on(Queue::class, Queue::EVENT_AFTER_ERROR, $leaveJob);

        // The "Last Editor" column: fetch the last editor of every element on an index page in
        // one query as the page's elements are loaded, rather than one query per row as each cell
        // renders. Only inside the element index, where the column lives.
        Event::on(ElementQuery::class, ElementQuery::EVENT_AFTER_POPULATE_ELEMENTS, function(PopulateElementsEvent $event) {
            if (Craft::$app->controller instanceof ElementIndexesController) {
                $this->getEditTracker()->prefetchLastEditors($event->elements);
            }
        });

        // Track all element saves
        Event::on(
            Element::class,
            Element::EVENT_AFTER_SAVE,
            function(ModelEvent $event) {
                /** @var Element $element */
                $element = $event->sender;
                $this->getEditTracker()->logEdit($element, $event->isNew);
            },
        );

        // Register dashboard widget
        Event::on(
            Dashboard::class,
            Dashboard::EVENT_REGISTER_WIDGET_TYPES,
            function(RegisterComponentTypesEvent $event) {
                $event->types[] = RecentEditsWidget::class;
            },
        );

        // Make the "Last Editor" column available on every element index
        Event::on(
            Element::class,
            Element::EVENT_REGISTER_TABLE_ATTRIBUTES,
            function(RegisterElementTableAttributesEvent $event) {
                $event->tableAttributes[EditTracker::LAST_EDITOR_ATTRIBUTE] = [
                    'label' => Craft::t('rat', 'Last Editor'),
                ];
            },
        );

        // Render the "Last Editor" column cell
        Event::on(
            Element::class,
            Element::EVENT_DEFINE_ATTRIBUTE_HTML,
            function(DefineAttributeHtmlEvent $event) {
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
            function(RegisterElementSortOptionsEvent $event) {
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
            function(DefineHtmlEvent $event) {
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

                $this->getEditTracker()->preload($history, elements: false);

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
