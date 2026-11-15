<?php

namespace roadsterworks\craftshopifysync\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use roadsterworks\craftshopifysync\CraftShopifySync;
use roadsterworks\craftshopifysync\services\AuthService;
use yii\console\ExitCode;

/**
 * Shares this environment's Shopify connection with others.
 */
class AuthController extends Controller
{
    /**
     * Print the access token, to set as SHOPIFY_SYNC_ACCESS_TOKEN in another environment.
     */
    public function actionToken(): int
    {
        $token = CraftShopifySync::getInstance()->authService->getAccessToken();

        if ($token === '') {
            $this->stderr("Not connected to Shopify yet. Authorise from the plugin settings first.\n", Console::FG_RED);

            return ExitCode::UNAVAILABLE;
        }

        $this->stdout(AuthService::TOKEN_ENV_VAR . "={$token}\n");

        return ExitCode::OK;
    }
}
