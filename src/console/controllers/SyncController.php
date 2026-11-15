<?php

namespace roadsterworks\craftshopifysync\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use roadsterworks\craftshopifysync\CraftShopifySync;
use yii\console\ExitCode;

/**
 * Queues Shopify syncs, e.g. from cron or a deploy script.
 */
class SyncController extends Controller
{
    public $defaultAction = 'all';

    /** Re-send products even when their content hasn't changed. */
    public bool $force = false;

    public function options($actionID): array
    {
        return [...parent::options($actionID), 'force'];
    }

    /**
     * Queue a sync of every product entry.
     */
    public function actionAll(): int
    {
        $jobId = CraftShopifySync::getInstance()->syncService->queueFullSync($this->force);
        $this->stdout("Queued full product sync (job {$jobId}).\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Queue a sync of a single product entry.
     */
    public function actionEntry(int $entryId): int
    {
        $syncService = CraftShopifySync::getInstance()->syncService;
        $entry = $syncService->findProductEntry($entryId);

        if ($entry === null) {
            $this->stderr("Entry {$entryId} isn't in the product section.\n", Console::FG_RED);

            return ExitCode::DATAERR;
        }

        $syncService->queueEntrySync($entry, $this->force);
        $this->stdout("Queued sync for “{$entry->title}”.\n", Console::FG_GREEN);

        return ExitCode::OK;
    }
}
