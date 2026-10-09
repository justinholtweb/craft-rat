<?php

namespace justinholtweb\rat\services;

use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
use craft\db\Query;
use craft\db\Table;
use craft\elements\User;
use craft\events\MoveElementEvent;
use craft\helpers\Db;
use craft\helpers\Html;
use DateTime;
use justinholtweb\rat\models\EditLog;
use justinholtweb\rat\models\Settings;
use justinholtweb\rat\Plugin;
use justinholtweb\rat\records\EditLogRecord;
use yii\db\Expression;

class EditTracker extends Component
{
    /**
     * The element index table attribute / sort key for the "Last Editor" column.
     */
    public const LAST_EDITOR_ATTRIBUTE = 'ratLastEditor';

    /**
     * How many log rows to examine at a time when filtering by visibility.
     */
    private const VISIBILITY_BATCH_SIZE = 50;

    /**
     * Ceiling on visibility-filtering batches, so a user who can view almost
     * nothing can't turn a widget render into a full table scan.
     */
    private const MAX_VISIBILITY_BATCHES = 10;

    /**
     * How many old rows to delete per statement when pruning.
     */
    private const PRUNE_CHUNK = 5000;

    /**
     * Per-request cache of last-editor lookups, keyed by "elementId-siteId".
     *
     * @var array<string, array|null>
     */
    private array $lastEditorCache = [];

    /**
     * Structure positions noted before a move, keyed by "structureId-elementId".
     *
     * @var array<string, array{parentId: int|null, parentLabel: string|null, position: int}>
     */
    private array $pendingMoves = [];

    /**
     * Elements being restored along with their owner, keyed by ID.
     *
     * @var array<int, true>
     */
    private array $restoringWithOwner = [];

    public function logEdit(ElementInterface $element, bool $isNew): void
    {
        if (!$this->shouldTrack($element)) {
            return;
        }

        $dirtyAttributes = $element->getDirtyAttributes();

        $this->record($element, EditLog::ACTION_SAVE, $isNew, dirtyAttributes: !empty($dirtyAttributes) ? $this->encode($dirtyAttributes) : null);
    }

    /**
     * Records an element being deleted: to the trash, or permanently (`$element->hardDelete`).
     * Runs after the delete, so for a permanent one the element is already gone — the label
     * recorded here is what the log shows for it from then on.
     *
     * Nested elements deleted along with their owner (Matrix entries, say) are skipped: the
     * owner's own row already says what happened, and one delete shouldn't read as dozens.
     */
    public function logDelete(ElementInterface $element): void
    {
        if (!$this->shouldTrack($element) || $element->deletedWithOwner) {
            return;
        }

        $this->record($element, $element->hardDelete ? EditLog::ACTION_HARD_DELETE : EditLog::ACTION_DELETE);
    }

    /**
     * Notes, before a restore, whether the element was trashed along with its owner. Craft clears
     * `deletedWithOwner` before announcing the restore, so {@see logRestore()} can't ask it then.
     */
    public function beforeRestore(ElementInterface $element): void
    {
        if ($element->id && $element->deletedWithOwner) {
            $this->restoringWithOwner[$element->id] = true;
        }
    }

    /**
     * Records an element being restored from the trash. Nested elements coming back with their
     * owner are skipped, for the same reason {@see logDelete()} skips them going.
     */
    public function logRestore(ElementInterface $element): void
    {
        if (isset($this->restoringWithOwner[$element->id])) {
            unset($this->restoringWithOwner[$element->id]);
            return;
        }

        if (!$this->shouldTrack($element)) {
            return;
        }

        $this->record($element, EditLog::ACTION_RESTORE);
    }

    /**
     * Notes where an element sits in a structure before it's moved, so {@see logMove()} can say
     * where it came from. Only repositioning reaches here — an element's first placement in a
     * structure is part of creating it, and the save already records that.
     */
    public function beforeMove(MoveElementEvent $event): void
    {
        $element = $event->element;

        if (!$element->id || !$this->shouldTrack($element)) {
            return;
        }

        $position = $this->structurePosition($event->structureId, $element->id, $element->siteId);

        // Not in the structure yet: this is its first placement, not a move.
        if ($position !== null) {
            $this->pendingMoves["$event->structureId-$element->id"] = $position;
        }
    }

    /**
     * Records a structure move: what the element was placed against, and its parent and position
     * among its siblings before and after. A move that leaves it exactly where it was isn't one.
     */
    public function logMove(MoveElementEvent $event): void
    {
        $element = $event->element;
        $key = "$event->structureId-$element->id";

        if (!array_key_exists($key, $this->pendingMoves)) {
            return;
        }

        $from = $this->pendingMoves[$key];
        unset($this->pendingMoves[$key]);

        $to = $this->structurePosition($event->structureId, $element->id, $element->siteId);

        if ($to !== null && $from['parentId'] === $to['parentId'] && $from['position'] === $to['position']) {
            return;
        }

        $this->record($element, EditLog::ACTION_MOVE, details: $this->encode([
            'structureId' => $event->structureId,
            'action' => $event->action,
            'targetId' => $event->targetElementId,
            'targetLabel' => $event->targetElementId ? $this->labelOf($event->targetElementId, $element->siteId) : null,
            'from' => $from,
            'to' => $to,
        ]));
    }

    /**
     * Where an element sits in a structure: its parent (null at the top level), that parent's
     * label, and its 1-based position among its siblings. Read straight from the nested set, so it
     * costs three small queries rather than loading the branch.
     *
     * @return array{parentId: int|null, parentLabel: string|null, position: int}|null
     */
    public function structurePosition(int $structureId, int $elementId, ?int $siteId = null): ?array
    {
        $node = (new Query())
            ->select(['root', 'lft', 'rgt', 'level'])
            ->from(Table::STRUCTUREELEMENTS)
            ->where(['structureId' => $structureId, 'elementId' => $elementId])
            ->one();

        if (!$node) {
            return null;
        }

        // Every structure hangs off one root node with no element, so a top-level element's
        // parent is that node and comes back with a null elementId.
        $parent = (new Query())
            ->select(['elementId', 'lft'])
            ->from(Table::STRUCTUREELEMENTS)
            ->where(['structureId' => $structureId, 'root' => $node['root'], 'level' => (int)$node['level'] - 1])
            ->andWhere(['<', 'lft', $node['lft']])
            ->andWhere(['>', 'rgt', $node['rgt']])
            ->one();

        $earlierSiblings = (new Query())
            ->from(Table::STRUCTUREELEMENTS)
            ->where(['structureId' => $structureId, 'root' => $node['root'], 'level' => $node['level']])
            ->andWhere(['<', 'lft', $node['lft']])
            ->andWhere(['>', 'lft', $parent['lft'] ?? 0])
            ->count();

        $parentId = !empty($parent['elementId']) ? (int)$parent['elementId'] : null;

        return [
            'parentId' => $parentId,
            'parentLabel' => $parentId ? $this->labelOf($parentId, $siteId) : null,
            'position' => (int)$earlierSiblings + 1,
        ];
    }

    /**
     * Writes one log row. Everything about the element is captured now, label included, because
     * after a delete there may be nothing left to look it up from.
     */
    private function record(
        ElementInterface $element,
        string $action,
        bool $isNew = false,
        ?string $dirtyAttributes = null,
        ?string $details = null,
    ): void {
        // Between deploying 5.2 and running its migration, the table has no column for anything
        // but a save. Saves still get recorded, as they always were; the rest can't be, and none
        // of it may stop the element's own save or delete.
        $lifecycle = $this->hasLifecycleColumns();

        if (!$lifecycle && $action !== EditLog::ACTION_SAVE) {
            return;
        }

        $user = Craft::$app->getUser()->getIdentity();

        $record = new EditLogRecord();
        $record->elementId = $element->id;
        $record->siteId = $element->siteId;
        $record->userId = $user?->id;
        $record->elementType = get_class($element);
        // The column is 255 wide, and this runs inside the element's own save.
        // An overlong label must never be what stops content from saving.
        $record->elementLabel = mb_substr((string)$element, 0, 255);
        $record->isNew = $isNew;
        if ($lifecycle) {
            $record->action = $action;
            $record->details = $details;
        }
        // setAttribute(), not the property: the column shares its name with ActiveRecord's own
        // read-only `dirtyAttributes`.
        $record->setAttribute('dirtyAttributes', $dirtyAttributes);

        $record->save(false);

        // This edit is now the element's most recent one, so any memoized
        // answer from earlier in the request is stale.
        unset($this->lastEditorCache["{$element->id}-{$element->siteId}"]);
    }

    /**
     * Whether the `action` and `details` columns exist yet, i.e. whether the 5.2 migration has run.
     * Read from the schema Craft already has loaded, so it costs nothing after the first call.
     */
    private function hasLifecycleColumns(): bool
    {
        return EditLogRecord::getTableSchema()->getColumn('action') !== null;
    }

    private function encode(array $value): ?string
    {
        // A label cut mid-character by the 255 limit must not turn the whole row into `false`.
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: null;
    }

    private function labelOf(int $elementId, ?int $siteId): ?string
    {
        $element = Craft::$app->getElements()->getElementById($elementId, null, $siteId);

        return $element ? mb_substr((string)$element, 0, 255) : null;
    }

    /**
     * Determines whether a save — or a delete, restore or move — should be recorded.
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

        // Defaults when the plugin isn't loaded — the unit suite runs this without booting Craft.
        $settings = Plugin::getInstance()?->getSettings() ?? new Settings();

        foreach ($settings->excludedElementTypes as $type) {
            if ($type !== '' && is_a($element, $type)) {
                return false;
            }
        }

        if (!$settings->trackAnonymousSiteSaves && $this->isAnonymousSiteRequest()) {
            return false;
        }

        return true;
    }

    /**
     * Whether this request is a front-end one from somebody who isn't signed in — a cart
     * recalculating, a form submitting. Until 5.1.3 every such save was logged, so on a store the
     * table grew by rows per cart action.
     */
    public function isAnonymousSiteRequest(): bool
    {
        // Queue jobs — imports, resaves — are the system at work, not a visitor. Craft runs the
        // queue from a cookieless web request on sites that run it automatically, which would
        // otherwise look exactly like an anonymous front-end save.
        // @phpstan-ignore isset.property (Craft::$app is unset in the unit suite, which runs without Craft)
        if ($this->queueDepth > 0 || !isset(Craft::$app)) {
            return false;
        }

        $request = Craft::$app->getRequest();

        if (!$request instanceof \craft\web\Request || $request->getIsConsoleRequest() || !$request->getIsSiteRequest()) {
            return false;
        }

        return Craft::$app->getUser()->getIdentity() === null;
    }

    /**
     * How many queue jobs are running in this process right now. Maintained by
     * {@see \justinholtweb\rat\Plugin} from the queue's before/after events.
     */
    public int $queueDepth = 0;

    /**
     * @param string[]|null $actions only these actions (see the `EditLog::ACTION_*` constants), or
     * null for all of them
     * @return EditLog[]
     */
    public function getRecentEdits(int $limit = 20, int $offset = 0, ?array $actions = null): array
    {
        $rows = $this->logQuery()
            ->andFilterWhere(['l.action' => $actions])
            // dateCreated only resolves to the second, so tie-break on id to
            // keep paginated results stable and genuinely newest-first.
            ->orderBy(['l.dateCreated' => SORT_DESC, 'l.id' => SORT_DESC])
            ->limit($limit)
            ->offset($offset)
            ->all();

        return array_map(fn(array $row) => $this->createModel($row), $rows);
    }

    /**
     * Returns recent edits the given user is allowed to see, skipping any whose
     * element they can't view — an editor scoped to one section shouldn't learn
     * the titles of entries in the sections they were kept out of.
     *
     * Rows are filtered after the fact rather than in SQL, because visibility
     * depends on per-element-type permission logic that isn't expressible as a
     * query. To still return a full page, this walks the log in batches, up to
     * a bounded number of them.
     *
     * @param string[]|null $actions see {@see getRecentEdits()}
     * @return EditLog[]
     */
    public function getRecentEditsVisibleTo(?User $user, int $limit = 20, ?array $actions = null): array
    {
        if (!$user) {
            return [];
        }

        $elementsService = Craft::$app->getElements();
        $batchSize = max($limit, self::VISIBILITY_BATCH_SIZE);

        $visible = [];
        $offset = 0;

        for ($batch = 0; $batch < self::MAX_VISIBILITY_BATCHES; $batch++) {
            $edits = $this->getRecentEdits($batchSize, $offset, $actions);

            if (empty($edits)) {
                break;
            }

            // One query per element type and site, and one for the editors, rather than one per row:
            // a user who can see little of the log could otherwise cost 500 lookups per render.
            $this->preload($edits);

            foreach ($edits as $edit) {
                $element = $edit->getElement();

                // A missing element is either deleted permanently or on a site
                // this user can't reach; either way there's nothing left to
                // authorize against, so only admins keep seeing it. Elements in
                // the trash are still loaded, and checked like any other.
                $canView = $element !== null
                    ? $elementsService->canView($element, $user)
                    : $user->admin;

                if ($canView) {
                    $visible[] = $edit;

                    if (count($visible) === $limit) {
                        return $visible;
                    }
                }
            }

            $offset += $batchSize;
        }

        return $visible;
    }

    /**
     * An element's history on one site, newest first. Deletes, restores and moves happen to the
     * element on every site at once, so those are included whichever site they were recorded on.
     *
     * @return EditLog[]
     */
    public function getElementHistory(int $elementId, int $siteId, int $limit = 50, int $offset = 0): array
    {
        $rows = $this->logQuery()
            ->where(['l.elementId' => $elementId])
            ->andWhere([
                'or',
                ['l.siteId' => $siteId],
                ['l.action' => EditLog::ELEMENT_WIDE_ACTIONS],
            ])
            ->orderBy(['l.dateCreated' => SORT_DESC, 'l.id' => SORT_DESC])
            ->limit($limit)
            ->offset($offset)
            ->all();

        return array_map(fn(array $row) => $this->createModel($row), $rows);
    }

    /**
     * Whether anything at all is recorded for an element ID. How the history endpoint tells an
     * element that's gone for good, but whose history an admin can still read, from an ID that
     * never existed.
     */
    public function hasHistory(int $elementId): bool
    {
        return (new Query())->from('{{%rat_editlog}}')->where(['elementId' => $elementId])->exists();
    }

    /**
     * Deletions, newest first, optionally narrowed to labels containing `$search` — the "who
     * deleted this?" lookup behind `php craft rat/log/deleted`. Searches the label recorded at the
     * time, so it finds elements that no longer exist.
     *
     * @return EditLog[]
     */
    public function getDeletions(?string $search = null, int $limit = 50): array
    {
        $query = $this->logQuery()
            ->where(['l.action' => [EditLog::ACTION_DELETE, EditLog::ACTION_HARD_DELETE]])
            ->orderBy(['l.dateCreated' => SORT_DESC, 'l.id' => SORT_DESC])
            ->limit($limit);

        if ($search !== null && $search !== '') {
            // Escaped, so `%` and `_` in what somebody typed match themselves.
            $query->andWhere(['like', 'l.elementLabel', $search]);
        }

        return array_map(fn(array $row) => $this->createModel($row), $query->all());
    }

    private function logQuery(): Query
    {
        return (new Query())
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
                ...($this->hasLifecycleColumns() ? ['l.action', 'l.details'] : []),
            ])
            ->from(['l' => '{{%rat_editlog}}']);
    }

    /**
     * Deletes log rows older than `$days` days. Run by Craft's garbage collection with the
     * `retentionDays` setting (see {@see \justinholtweb\rat\Plugin}) and by `rat/log/prune`.
     *
     * Until 5.1.3 nothing called this, so the log only ever grew. It also built its cutoff in PHP's
     * time zone and compared it with dates Craft stores in UTC, so rows were kept, or deleted, for
     * up to a day too long or short depending on where the server was.
     *
     * Deletes in chunks of ids, so pruning a log that has grown for years doesn't hold one lock
     * on the whole table while editors are saving.
     */
    public function cleanupOldLogs(int $days = 90): int
    {
        if ($days < 1) {
            return 0;
        }

        $cutoff = Db::prepareDateForDb(new DateTime("-{$days} days"));
        $db = Craft::$app->getDb();
        $deleted = 0;

        do {
            $ids = (new Query())
                ->select(['id'])
                ->from('{{%rat_editlog}}')
                ->where(['<', 'dateCreated', $cutoff])
                ->limit(self::PRUNE_CHUNK)
                ->column();

            if ($ids === []) {
                break;
            }

            $deleted += $db->createCommand()->delete('{{%rat_editlog}}', ['id' => $ids])->execute();
        } while (count($ids) === self::PRUNE_CHUNK);

        return $deleted;
    }

    /**
     * Loads the elements and editors of a list of edits in bulk: one query per element type and
     * site, one for the users. After this, `getElement()` and `getUser()` on each cost nothing.
     *
     * @param EditLog[] $edits
     * @param bool $elements false for an element's own history, where every row is the same
     * element and only the editors are worth loading
     */
    public function preload(array $edits, bool $elements = true): void
    {
        $groups = [];
        $userIds = [];

        foreach ($edits as $edit) {
            if ($elements && $edit->elementId && $edit->elementType && class_exists($edit->elementType)) {
                $groups[$edit->elementType][(int)$edit->siteId][] = $edit->elementId;
            }
            if ($edit->userId) {
                $userIds[$edit->userId] = true;
            }
        }

        $loaded = [];

        /** @var array<class-string<ElementInterface>, array<int, int[]>> $groups */
        foreach ($groups as $type => $bySite) {
            foreach ($bySite as $siteId => $ids) {
                // Trashed ones too: they're still there to link to and to authorize against.
                foreach ($type::find()->id(array_unique($ids))->siteId($siteId)->status(null)->trashed(null)->all() as $element) {
                    $loaded["$type:$siteId:$element->id"] = $element;
                }
            }
        }

        $users = $userIds === [] ? [] : User::find()->id(array_keys($userIds))->status(null)->indexBy('id')->all();

        foreach ($edits as $edit) {
            if ($elements) {
                $edit->setElement($loaded["{$edit->elementType}:{$edit->siteId}:{$edit->elementId}"] ?? null);
            }
            $edit->setUser($edit->userId ? ($users[$edit->userId] ?? null) : null);
        }
    }

    /**
     * Fetches the last editor of every element on an index page in one query, so the "Last
     * Editor" column doesn't cost a query per row.
     *
     * The latest row per element and site is the one with the highest id: rows are only ever
     * inserted, so id order is insertion order — the same tie-break {@see getLastEditor()} uses.
     *
     * @param ElementInterface[] $elements
     */
    public function prefetchLastEditors(array $elements): void
    {
        $pairs = [];

        foreach ($elements as $element) {
            if ($element instanceof ElementInterface && $element->id && $element->siteId) {
                $key = "{$element->id}-{$element->siteId}";
                if (!array_key_exists($key, $this->lastEditorCache)) {
                    $pairs[$key] = [(int)$element->id, (int)$element->siteId];
                }
            }
        }

        if ($pairs === []) {
            return;
        }

        $latest = (new Query())
            ->select(['id' => 'MAX([[id]])'])
            ->from('{{%rat_editlog}}')
            ->where(['elementId' => array_unique(array_column($pairs, 0))])
            ->andWhere(['siteId' => array_unique(array_column($pairs, 1))])
            ->groupBy(['elementId', 'siteId']);

        $rows = (new Query())
            ->select(['l.elementId', 'l.siteId', 'l.userId', 'l.dateCreated', 'u.fullName', 'u.username', 'u.email'])
            ->from(['l' => '{{%rat_editlog}}'])
            ->leftJoin(['u' => '{{%users}}'], '[[u.id]] = [[l.userId]]')
            ->where(['l.id' => $latest])
            ->all();

        foreach ($pairs as $key => $_) {
            $this->lastEditorCache[$key] = null;
        }

        foreach ($rows as $row) {
            $key = "{$row['elementId']}-{$row['siteId']}";
            if (array_key_exists($key, $pairs)) {
                unset($row['elementId'], $row['siteId']);
                $this->lastEditorCache[$key] = $row;
            }
        }
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
        $model->action = $row['action'] ?? EditLog::ACTION_SAVE;
        $model->details = $row['details'] ?? null;
        $model->dirtyAttributes = $row['dirtyAttributes'];
        $model->dateCreated = $row['dateCreated'] ? new DateTime($row['dateCreated']) : null;

        return $model;
    }
}
