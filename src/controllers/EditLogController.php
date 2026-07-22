<?php

namespace justinholtweb\rat\controllers;

use Craft;
use craft\web\Controller;
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
        $element = Craft::$app->getElements()->getElementById($elementId, null, $siteId);

        if (!$element) {
            throw new NotFoundHttpException('Element not found.');
        }

        if (!Craft::$app->getElements()->canView($element)) {
            throw new ForbiddenHttpException('You don’t have permission to view this element’s edit history.');
        }

        $history = Plugin::getInstance()->getEditTracker()->getElementHistory(
            $elementId,
            $siteId,
            $limit,
            $offset,
        );

        $data = array_map(function ($entry) {
            $user = $entry->getUser();
            return [
                'id' => $entry->id,
                'userId' => $entry->userId,
                'userName' => $user ? $user->getFriendlyName() : 'System',
                'userPhoto' => $user && $user->photo ? $user->photo->getThumbUrl(30) : null,
                'isNew' => $entry->isNew,
                'dirtyAttributes' => $entry->getDirtyAttributesList(),
                'dateCreated' => $entry->dateCreated?->format('c'),
            ];
        }, $history);

        return $this->asJson([
            'history' => $data,
        ]);
    }
}
