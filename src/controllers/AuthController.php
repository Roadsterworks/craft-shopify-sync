<?php

namespace roadsterworks\craftshopifysync\controllers;

use roadsterworks\craftshopifysync\CraftShopifySync;
use roadsterworks\craftshopifysync\errors\ShopifyApiException;
use yii\web\Response;

/**
 * The app's “App URL”. Sends the merchant to Shopify to approve the app, then handles the redirect back.
 */
class AuthController extends BaseCpController
{
    protected string $permission = CraftShopifySync::PERMISSION_SETTINGS;

    public function actionIndex(): Response
    {
        $auth = $this->plugin()->authService;
        $query = $this->request->getQueryParamsWithoutPath();
        $settingsUrl = CraftShopifySync::HANDLE . '/settings';

        if (!$this->plugin()->getSettings()->hasCredentials()) {
            $this->setFailFlash(CraftShopifySync::t('Save the store, client ID and secret first.'));

            return $this->redirect($settingsUrl);
        }

        // Shopify's redirect back, after the merchant approved the app.
        if (isset($query['code'])) {
            try {
                $auth->handleCallback($query);
                $this->setSuccessFlash(CraftShopifySync::t('Connected to Shopify.'));
            } catch (ShopifyApiException $e) {
                $this->plugin()->logService->error('Connecting to Shopify failed', ['error' => $e->getMessage()]);
                $this->setFailFlash($e->getMessage());
            }

            return $this->redirect($settingsUrl);
        }

        return $this->redirect($auth->getAuthoriseUrl());
    }

    public function actionDisconnect(): Response
    {
        $this->requirePostRequest();
        $this->plugin()->authService->disconnect();

        return $this->asSuccess(CraftShopifySync::t('Disconnected from Shopify.'));
    }
}
