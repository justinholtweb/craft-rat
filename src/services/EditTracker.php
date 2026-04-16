<?php

namespace justinholtweb\rat\services;

use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
use craft\db\Query;
use justinholtweb\rat\models\EditLog;
use justinholtweb\rat\records\EditLogRecord;
use DateTime;

class EditTracker extends Component
{
    public function logEdit(ElementInterface $element, bool $isNew): void
    {
        // Skip drafts, revisions, propagating saves, and bulk resaves
        if ($element->getIsDraft() || $element->getIsRevision()) {
            return;
        }

        if ($element->propagating || $element->resaving) {
            return;
        }

        $user = Craft::$app->getUser()->getIdentity();

        $record = new EditLogRecord();
        $record->elementId = $element->id;
        $record->siteId = $element->siteId;
        $record->userId = $user?->id;
        $record->elementType = get_class($element);
        $record->elementLabel = (string)$element;
        $record->isNew = $isNew;

        $dirtyAttributes = $element->getDirtyAttributes();
        $record->dirtyAttributes = !empty($dirtyAttributes) ? json_encode($dirtyAttributes) : null;

        $record->save(false);
    }

    /**
     * @return EditLog[]
     */
    public function getRecentEdits(int $limit = 20, int $offset = 0): array
    {
        $rows = (new Query())
            ->select([
                'l.id',
                'l.elementId',
                'l.siteId',
                'l.userId',
                'l.elementType',
                'l.elementLabel',
                'l.isNew',
                'l.dirtyAttributes',
                'l.dateCreated',
            ])
            ->from(['l' => '{{%rat_editlog}}'])
            ->orderBy(['l.dateCreated' => SORT_DESC])
            ->limit($limit)
            ->offset($offset)
            ->all();

        return array_map(fn(array $row) => $this->createModel($row), $rows);
    }

    /**
     * @return EditLog[]
     */
    public function getElementHistory(int $elementId, int $siteId, int $limit = 50, int $offset = 0): array
    {
        $rows = (new Query())
            ->select([
                'l.id',
                'l.elementId',
                'l.siteId',
                'l.userId',
                'l.elementType',
                'l.elementLabel',
                'l.isNew',
                'l.dirtyAttributes',
                'l.dateCreated',
            ])
            ->from(['l' => '{{%rat_editlog}}'])
            ->where([
                'l.elementId' => $elementId,
                'l.siteId' => $siteId,
            ])
            ->orderBy(['l.dateCreated' => SORT_DESC])
            ->limit($limit)
            ->offset($offset)
            ->all();

        return array_map(fn(array $row) => $this->createModel($row), $rows);
    }

    public function cleanupOldLogs(int $days = 90): int
    {
        $date = (new DateTime())->modify("-{$days} days")->format('Y-m-d H:i:s');

        return Craft::$app->getDb()->createCommand()
            ->delete('{{%rat_editlog}}', ['<', 'dateCreated', $date])
            ->execute();
    }

    private function createModel(array $row): EditLog
    {
        $model = new EditLog();
        $model->id = (int)$row['id'];
        $model->elementId = (int)$row['elementId'];
        $model->siteId = (int)$row['siteId'];
        $model->userId = $row['userId'] ? (int)$row['userId'] : null;
        $model->elementType = $row['elementType'];
        $model->elementLabel = $row['elementLabel'];
        $model->isNew = (bool)$row['isNew'];
        $model->dirtyAttributes = $row['dirtyAttributes'];
        $model->dateCreated = $row['dateCreated'] ? new DateTime($row['dateCreated']) : null;

        return $model;
    }
}
