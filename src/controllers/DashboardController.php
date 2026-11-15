<?php

namespace roadsterworks\craftshopifysync\controllers;

use Craft;
use craft\queue\QueueInterface;
use craft\web\CpScreenResponseBehavior;
use roadsterworks\craftshopifysync\CraftShopifySync;
use yii\web\Response;

class DashboardController extends BaseCpController
{
    public function actionIndex(): Response
    {
        $data = $this->plugin()->dashboardService->getData();

        return $this->screen('dashboard', CraftShopifySync::t('Shopify Sync'), $data, function(CpScreenResponseBehavior $screen) use ($data) {
            $screen->additionalButtonsTemplate('craft-shopify-sync/dashboard/_buttons', $data);
        });
    }

    /**
     * Live stats for the dashboard. Pass `shopify=0` to skip Shopify's product count.
     */
    public function actionStats(): Response
    {
        $queue = Craft::$app->getQueue();

        return $this->asJson([
            ...$this->plugin()->dashboardService->getLiveStats((bool)$this->request->getQueryParam('shopify', true)),
            'queueBusy' => $queue instanceof QueueInterface && $queue->getTotalJobs() > 0,
        ]);
    }
}
