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

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['days']);
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
