<?php

namespace roadsterworks\craftshopifysync\controllers;

use Craft;
use craft\models\Section;
use roadsterworks\craftshopifysync\CraftShopifySync;
use roadsterworks\craftshopifysync\enums\CreationStrategy;
use roadsterworks\craftshopifysync\services\AuthService;
use roadsterworks\craftshopifysync\enums\SyncStrategy;
use yii\web\Response;

class SettingsController extends BaseCpController
{
    protected string $permission = CraftShopifySync::PERMISSION_SETTINGS;

    public function actionIndex(): Response
    {
        return $this->renderSettings();
    }

    /**
     * Changing the product section queues installation of the Shopify field on its entry types.
     */
    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        $plugin = $this->plugin();
        $previousSection = $plugin->getSettings()->productSectionHandle;

        if (!$plugin->saveSettings((array)$this->request->getBodyParam('settings', []))) {
            $this->setFailFlash(CraftShopifySync::t('Couldn’t save settings.'));

            return $this->renderSettings();
        }

        // The credentials may have changed, so drop any token and cached data from the old connection.
        $plugin->shopifyService->clearCaches();

        $section = $plugin->getSettings()->productSectionHandle;
        if ($section !== $previousSection) {
            $plugin->syncService->queueFieldInstall($previousSection, $section);
        }

        return $this->asSuccess(CraftShopifySync::t('Settings saved.'));
    }

    private function renderSettings(): Response
    {
        return $this->formScreen('settings', CraftShopifySync::t('Settings'), 'craft-shopify-sync/settings/save', [
            'settings' => $this->plugin()->getSettings(),
            'sectionOptions' => [
                ['label' => CraftShopifySync::t('Select a section…'), 'value' => ''],
                ...array_map(
                    static fn(Section $section): array => ['label' => $section->name, 'value' => $section->handle],
                    Craft::$app->getEntries()->getAllSections(),
                ),
            ],
            'isAuthorised' => $this->plugin()->authService->isAuthorised(),
            'scopes' => AuthService::SCOPES,
            'creationStrategyOptions' => CreationStrategy::options(),
            'syncStrategyOptions' => SyncStrategy::options(),
        ]);
    }
}
