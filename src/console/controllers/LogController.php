<?php

namespace justinholtweb\rat\console\controllers;

use craft\console\Controller;
use justinholtweb\rat\Plugin;
use yii\console\ExitCode;

/**
 * Maintains Rat's edit log.
 */
class LogController extends Controller
{
    /**
     * @var int|null Days to keep. Defaults to the `retentionDays` setting.
     */
    public ?int $days = null;

    /**
     * @var string|null Only deletions whose recorded label contains this.
     */
    public ?string $search = null;

    /**
     * @var int How many deletions to list.
     */
    public int $limit = 50;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), match ($actionID) {
            'deleted' => ['search', 'limit'],
            default => ['days'],
        });
    }

    /**
     * Lists who deleted what, newest first — including elements deleted permanently, which no
     * longer appear anywhere else. Pass --search to narrow by title.
     */
    public function actionDeleted(): int
    {
        $tracker = Plugin::getInstance()->getEditTracker();
        $deletions = $tracker->getDeletions($this->search, max(1, $this->limit));

        if ($deletions === []) {
            $this->stdout("No deletions recorded" . ($this->search ? " matching “{$this->search}”" : '') . ".\n");

            return ExitCode::OK;
        }

        $tracker->preload($deletions, elements: false);

        $rows = array_map(fn($edit) => [
            $edit->dateCreated?->format('Y-m-d H:i') ?? '',
            $edit->getUser()?->getFriendlyName() ?? ($edit->userId ? '#' . $edit->userId : 'System'),
            $edit->getActionLabel(),
            $edit->getElementTypeLabel(),
            (string)$edit->elementId,
            (string)$edit->elementLabel,
        ], $deletions);

        $this->table(['Date (UTC)', 'User', 'Action', 'Type', 'ID', 'Title'], $rows);

        return ExitCode::OK;
    }

    /**
     * Deletes edit-log rows older than the retention period. Craft's garbage collection does the
     * same thing on its own schedule; this is for cron, or for a first clean-up of a log that has
     * been growing since before 5.1.3.
     */
    public function actionPrune(): int
    {
        $days = $this->days ?? Plugin::getInstance()->getSettings()->retentionDays;

        if ($days < 1) {
            $this->stdout("Retention is off (0 days), so nothing was pruned. Pass --days to prune anyway.\n");

            return ExitCode::OK;
        }

        $deleted = Plugin::getInstance()->getEditTracker()->cleanupOldLogs($days);
        $this->stdout("Deleted $deleted edit-log " . ($deleted === 1 ? 'row' : 'rows') . " older than $days days.\n");

        return ExitCode::OK;
    }
}
