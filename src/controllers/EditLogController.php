<?php

namespace justinholtweb\rat\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\rat\Plugin;
use yii\web\Response;

class EditLogController extends Controller
{
    public function actionElementHistory(): Response
    {
        $this->requireAcceptsJson();

        $elementId = $this->request->getRequiredParam('elementId');
        $siteId = $this->request->getRequiredParam('siteId');
        $limit = $this->request->getParam('limit', 50);
        $offset = $this->request->getParam('offset', 0);

        $history = Plugin::getInstance()->getEditTracker()->getElementHistory(
            (int)$elementId,
            (int)$siteId,
            (int)$limit,
            (int)$offset,
        );

        $data = array_map(function ($entry) {
            $user = $entry->getUser();
            return [
                'id' => $entry->id,
                'userId' => $entry->userId,
                'userName' => $user ? $user->getFriendlyName() : 'System',
                'userPhoto' => $user ? $user->getThumbUrl(30) : null,
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
