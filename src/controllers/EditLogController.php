<?php

namespace justinholtweb\rat\controllers;

use Craft;
use craft\base\ElementInterface;
use craft\web\Controller;
use craft\web\View;
use justinholtweb\rat\Plugin;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class EditLogController extends Controller
{
    public function actionElementHistory(): Response
    {
        $this->requireAcceptsJson();
        $this->requireCpRequest();

        $elementId = (int)$this->request->getRequiredParam('elementId');
        $siteId = (int)$this->request->getRequiredParam('siteId');
        $limit = min(max((int)$this->request->getParam('limit', 50), 1), 100);
        $offset = max((int)$this->request->getParam('offset', 0), 0);

        // Edit history is as sensitive as the element itself: it exposes titles,
        // who touched them, and which fields changed. Gate it behind the same
        // check Craft uses for the element's own edit page, so an editor can't
        // read history for a section they have no access to.
        // Elements in the trash are still checked like any other. One deleted permanently has
        // nothing left to check against, so only an admin can read what the log kept of it.
        $element = $this->findElement($elementId, $siteId);
        $tracker = Plugin::getInstance()->getEditTracker();

        if (!$element) {
            if (!Craft::$app->getUser()->getIdentity()?->admin || !$tracker->hasHistory($elementId)) {
                throw new NotFoundHttpException('Element not found.');
            }
        } elseif (!Craft::$app->getElements()->canView($element)) {
            throw new ForbiddenHttpException('You don’t have permission to view this element’s edit history.');
        }

        // Fetch one more than asked for, so we can tell the caller whether
        // another page exists without running a second count query.
        $history = $tracker->getElementHistory(
            $elementId,
            $siteId,
            $limit + 1,
            $offset,
        );

        $hasMore = count($history) > $limit;

        if ($hasMore) {
            array_pop($history);
        }

        $tracker->preload($history, elements: false);

        $data = array_map(function($entry) {
            $user = $entry->getUser();
            $photo = $user?->getPhoto();
            return [
                'id' => $entry->id,
                'userId' => $entry->userId,
                'userName' => $user ? $user->getFriendlyName() : 'System',
                // Craft 5 moved thumbnail URL generation off the Asset element
                // and onto the Assets service.
                'userPhoto' => $photo
                    ? Craft::$app->getAssets()->getThumbUrl($photo, 30, iconFallback: false)
                    : null,
                'isNew' => $entry->isNew,
                'action' => $entry->action,
                'actionLabel' => $entry->getActionLabel(),
                'details' => $entry->getDetailsList() ?: null,
                'summary' => $entry->getMoveSummary(),
                'elementLabel' => $entry->elementLabel,
                'siteId' => $entry->siteId,
                'dirtyAttributes' => $entry->getDirtyAttributesList(),
                'dateCreated' => $entry->dateCreated?->format('c'),
            ];
        }, $history);

        // `html` is what rat.js appends — rendering the same partial the
        // sidebar uses keeps appended rows identical to the initial ones.
        // `history` is the structured equivalent, for anything consuming the
        // endpoint directly.
        $html = Craft::$app->getView()->renderTemplate(
            'rat/_sidebar/_history-items',
            ['history' => $history],
            View::TEMPLATE_MODE_CP,
        );

        return $this->asJson([
            'history' => $data,
            'html' => $html,
            'hasMore' => $hasMore,
        ]);
    }

    private function findElement(int $elementId, int $siteId): ?ElementInterface
    {
        $type = Craft::$app->getElements()->getElementTypeById($elementId);

        if (!$type || !is_subclass_of($type, ElementInterface::class)) {
            return null;
        }

        return $type::find()->id($elementId)->siteId($siteId)->status(null)->trashed(null)->one();
    }
}
