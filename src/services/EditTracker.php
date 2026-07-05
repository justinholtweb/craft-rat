<?php

namespace justinholtweb\rat\services;

use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
use craft\db\Query;
use craft\helpers\Html;
use justinholtweb\rat\models\EditLog;
use justinholtweb\rat\records\EditLogRecord;
use DateTime;
use yii\db\Expression;

class EditTracker extends Component
{
    /**
     * The element index table attribute / sort key for the "Last Editor" column.
     */
    public const LAST_EDITOR_ATTRIBUTE = 'ratLastEditor';

    /**
     * Per-request cache of last-editor lookups, keyed by "elementId-siteId".
     *
     * @var array<string, array|null>
     */
    private array $lastEditorCache = [];

    public function logEdit(ElementInterface $element, bool $isNew): void
    {
        if (!$this->shouldTrack($element)) {
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
     * Determines whether a save should be recorded.
     *
     * Drafts, revisions, propagating saves (multi-site content propagation),
     * and bulk resaves are intentionally excluded so the log only reflects
     * real, intentional content changes.
     */
    public function shouldTrack(ElementInterface $element): bool
    {
        if ($element->getIsDraft() || $element->getIsRevision()) {
            return false;
        }

        if ($element->propagating || $element->resaving) {
            return false;
        }

        return true;
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

    /**
     * Returns the most recent edit for an element on a given site, with the
     * editor's name columns joined in. Memoized per request so rendering an
     * element index column stays at one query per row.
     *
     * @return array{userId: int|null, dateCreated: string|null, fullName: string|null, username: string|null, email: string|null}|null
     */
    public function getLastEditor(int $elementId, int $siteId): ?array
    {
        $key = "$elementId-$siteId";

        if (array_key_exists($key, $this->lastEditorCache)) {
            return $this->lastEditorCache[$key];
        }

        $row = (new Query())
            ->select([
                'l.userId',
                'l.dateCreated',
                'u.fullName',
                'u.username',
                'u.email',
            ])
            ->from(['l' => '{{%rat_editlog}}'])
            ->leftJoin(['u' => '{{%users}}'], '[[u.id]] = [[l.userId]]')
            ->where([
                'l.elementId' => $elementId,
                'l.siteId' => $siteId,
            ])
            ->orderBy(['l.dateCreated' => SORT_DESC, 'l.id' => SORT_DESC])
            ->limit(1)
            ->one();

        return $this->lastEditorCache[$key] = $row ?: null;
    }

    /**
     * Renders the "Last Editor" cell for an element index. Returns an empty
     * string when there's no recorded edit for the element.
     */
    public function getLastEditorCellHtml(int $elementId, int $siteId): string
    {
        $editor = $this->getLastEditor($elementId, $siteId);

        if ($editor === null) {
            return '';
        }

        $name = $this->editorDisplayName($editor);
        $date = !empty($editor['dateCreated']) ? new DateTime($editor['dateCreated']) : null;

        $formatter = Craft::$app->getFormatter();
        $inner = Html::encode($name);

        if ($date) {
            $inner .= ' ' . Html::tag('span', Html::encode($formatter->asRelativeTime($date)), [
                'class' => 'light',
            ]);
        }

        return Html::tag('span', $inner, [
            'class' => 'rat-index-editor',
            'title' => $date ? $formatter->asDatetime($date, 'short') : null,
        ]);
    }

    /**
     * Builds an ORDER BY expression that sorts elements by the name of whoever
     * made their most recent tracked edit.
     *
     * Uses a correlated subquery against {{%rat_editlog}} so it works on any
     * element index without joining the log into the element query. The
     * `elements` and `elements_sites` aliases are the ones Craft's element
     * query exposes to sort expressions.
     */
    public function getLastEditorSortOrderBy(int $dir): Expression
    {
        $direction = $dir === SORT_ASC ? 'ASC' : 'DESC';

        $sql = <<<SQL
(SELECT COALESCE([[u.fullName]], [[u.username]], [[u.email]])
FROM {{%rat_editlog}} [[rl]]
LEFT JOIN {{%users}} [[u]] ON [[u.id]] = [[rl.userId]]
WHERE [[rl.elementId]] = [[elements.id]] AND [[rl.siteId]] = [[elements_sites.siteId]]
ORDER BY [[rl.dateCreated]] DESC, [[rl.id]] DESC
LIMIT 1) $direction
SQL;

        return new Expression($sql);
    }

    /**
     * @param array{userId: int|null, fullName: string|null, username: string|null, email: string|null} $editor
     */
    private function editorDisplayName(array $editor): string
    {
        if (empty($editor['userId'])) {
            return Craft::t('rat', 'System');
        }

        $name = $editor['fullName'] ?: $editor['username'];

        if (!$name && !empty($editor['email'])) {
            $name = explode('@', $editor['email'])[0];
        }

        return $name ?: Craft::t('rat', 'Unknown');
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
