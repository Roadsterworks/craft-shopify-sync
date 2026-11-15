<?php

namespace roadsterworks\craftshopifysync\controllers;

use roadsterworks\craftshopifysync\CraftShopifySync;
use yii\web\Response;

/**
 * Queues sync operations from the control panel. Every action only pushes a queue job.
 */
class SyncController extends BaseCpController
{
    protected string $permission = CraftShopifySync::PERMISSION_SYNC;

    public function beforeAction($action): bool
    {
        $this->requirePostRequest();

        return parent::beforeAction($action);
    }

    /**
     * Queue a sync of every product entry. Pass `force=1` to re-send unchanged products too.
     */
    public function actionSyncAll(): Response
    {
        $this->plugin()->syncService->queueFullSync((bool)$this->request->getBodyParam('force'));

        return $this->asSuccess(CraftShopifySync::t('Sync queued.'));
    }

    public function actionSyncEntry(): Response
    {
        $syncService = $this->plugin()->syncService;
        $entry = $syncService->findProductEntry((int)$this->request->getRequiredBodyParam('entryId'));

        if ($entry === null) {
            return $this->asFailure(CraftShopifySync::t('That entry isn’t in the product section.'));
        }

        $syncService->queueEntrySync($entry, true);

        return $this->asSuccess(CraftShopifySync::t('Sync queued.'));
    }

    /**
     * Queue deletion of every product in the Shopify store. Craft entries are kept.
     */
    public function actionDeleteAllShopifyProducts(): Response
    {
        $this->requirePermission(CraftShopifySync::PERMISSION_DELETE);

        if ($this->request->getRequiredBodyParam('confirm') !== 'DELETE') {
            return $this->asFailure(CraftShopifySync::t('Type DELETE to confirm.'));
        }

        $this->plugin()->syncService->queueDeleteAll();

        return $this->asSuccess(CraftShopifySync::t('Deleting all Shopify products.'));
    }
}
